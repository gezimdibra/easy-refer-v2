<?php

namespace Tests\Feature\Owner;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\Business;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use App\Support\OpaqueToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * QRK-05 (smart-build-queue.md §5, Train 3): placement codes.
 * One active code per standing placement (counter, window, table,
 * receipt), each its own token, all resolving to the same join
 * flow; scans are stamped with the placement that produced them;
 * legacy codes with no placement still resolve. Copy for the
 * per-placement replace modal is Amendment 23 (REF-POSTER-03) and
 * is asserted on the panel surface in QrPlacementsPanelTest
 * (QRK-07), where the modal lives.
 */
class QrPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    private function enrollThrough(string $qrToken, string $phone = '+12125550111'): void
    {
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());

        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat',
            'last_name' => 'Lee',
            'phone' => $phone,
            'consent' => '1',
        ])->assertRedirect(route('refer.verify')));

        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));
    }

    public function test_four_active_codes_coexist(): void
    {
        $this->assertTrue(Schema::hasColumn('qr_codes', 'placement'));

        $business = World::business();
        $codes = QrCode::ensurePlacements($business);

        $this->assertSame(['counter', 'window', 'table', 'receipt'], array_keys($codes));

        $active = $business->qrCodes()->where('state', 'active')->get();
        $this->assertCount(4, $active);
        $this->assertSame(
            ['counter', 'receipt', 'table', 'window'],
            $active->pluck('placement')->sort()->values()->all()
        );
        // Each placement is its OWN code: four distinct tokens.
        $this->assertCount(4, $active->pluck('token_hash')->unique());

        // The single-code readers keep working and resolve the
        // counter code (the launch code every existing surface uses).
        $this->assertTrue($business->activeQrCode()->is($codes['counter']));

        // Ensuring again issues nothing new.
        QrCode::ensurePlacements($business->fresh());
        $this->assertSame(4, $business->qrCodes()->where('state', 'active')->count());
        $this->assertSame(4, $business->qrCodes()->count());
    }

    /**
     * M2 (Train-3 review, hardening): ensurePlacements issues
     * missing-placement codes inside a transaction, so two
     * simultaneous first-ever QR-page loads serialize and the
     * second sees the first's codes instead of issuing a
     * duplicate active row per placement. The harness runs on
     * one SQLite connection, where lockForUpdate is a no-op
     * and true cross-connection concurrency cannot be
     * expressed, so this pins the transactional half of the
     * pattern directly: every code ensurePlacements creates —
     * on the first pass and on a self-heal reissue — is
     * created while ensurePlacements' own transaction is open
     * (a transaction level above the one the caller already
     * had). The lock half is pinned by the interleaving test
     * below plus the code-level argument in the lane report.
     */
    public function test_ensure_placements_issues_missing_codes_inside_a_transaction(): void
    {
        $business = World::business();

        $levelsAtCreation = [];
        QrCode::created(function () use (&$levelsAtCreation) {
            $levelsAtCreation[] = DB::transactionLevel();
        });

        $baseline = DB::transactionLevel();
        $codes = QrCode::ensurePlacements($business);

        $this->assertCount(4, $levelsAtCreation);
        foreach ($levelsAtCreation as $level) {
            $this->assertGreaterThan(
                $baseline,
                $level,
                'A missing-placement code was issued outside the ensurePlacements transaction.'
            );
        }
        // The transaction is ensurePlacements' own: it has
        // closed again by the time the call returns.
        $this->assertSame($baseline, DB::transactionLevel());

        // A self-heal reissue is an issuance too: strip one
        // code's sealed copy and ensure again; the replacement
        // is likewise created inside the transaction, and the
        // heal semantics (revoke the unsealed row, inherit its
        // name, exactly one active per placement) are unchanged.
        $codes['window']->forceFill(['label' => 'Shop window', 'token_encrypted' => null])->save();
        $levelsAtCreation = [];

        $healed = QrCode::ensurePlacements($business->fresh());

        $this->assertCount(1, $levelsAtCreation);
        $this->assertGreaterThan($baseline, $levelsAtCreation[0]);
        $this->assertSame('revoked', $codes['window']->fresh()->state);
        $this->assertSame('Shop window', $healed['window']->label);
        $this->assertSame(4, $business->qrCodes()->where('state', 'active')->count());
    }

    /**
     * M2 interleaving simulation: a second first-ever load
     * arriving while the first load is mid-issuance (re-entered
     * from the first code's created event — the one hookable
     * point inside the issuance loop) must issue nothing the
     * first load has not already covered: four rows total, one
     * active code per placement, and both loads return the
     * same codes. On a single connection this simulates the
     * interleaving; across connections the transaction + row
     * lock in ensurePlacements is what serializes the two
     * loads so the second one's reads see the first's codes.
     */
    public function test_ensure_placements_interleaved_second_load_issues_no_duplicates(): void
    {
        $business = World::business();

        $secondLoad = null;
        $reentered = false;
        QrCode::created(function () use (&$secondLoad, &$reentered, $business) {
            if ($reentered) {
                return;
            }
            $reentered = true;
            $secondLoad = QrCode::ensurePlacements($business->fresh());
        });

        $firstLoad = QrCode::ensurePlacements($business);

        $this->assertTrue($reentered, 'The interleaving hook never fired; the simulation did not run.');
        $this->assertNotNull($secondLoad);
        $this->assertSame(4, $business->qrCodes()->count());
        $this->assertSame(4, $business->qrCodes()->where('state', 'active')->count());
        foreach (QrCode::PLACEMENTS as $placement) {
            $this->assertSame(
                1,
                $business->qrCodes()->where('placement', $placement)->where('state', 'active')->count(),
                "Duplicate active code for {$placement} after interleaved first loads."
            );
            $this->assertSame($firstLoad[$placement]->id, $secondLoad[$placement]->id);
        }
    }

    public function test_revoking_one_leaves_the_others_landing(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $codes = QrCode::ensurePlacements($business);
        $plains = collect($codes)->map(fn (QrCode $c) => $c->plainToken());
        $hashesBefore = collect($codes)->map(fn (QrCode $c) => $c->token_hash);

        $this->actingAs($owner)
            ->post(route('owner.qr.placement.regenerate', ['placement' => 'table']))
            ->assertRedirect(route('owner.qr'))
            // [ON-SPOT-3] (Amendment 31): the flash reads the
            // code's current display name (the table default).
            ->assertSessionHas('status', 'New code is ready for Table tent. Print a new poster for that spot.');

        // The old table token is dead; a fresh table code is active.
        $this->get(route('refer.landing', ['token' => $plains['table']]))->assertNotFound();
        $newTable = $business->qrCodes()
            ->where('placement', 'table')->where('state', 'active')->sole();
        $this->assertNotSame($hashesBefore['table'], $newTable->token_hash);

        // The other three codes are untouched and still land.
        foreach (['counter', 'window', 'receipt'] as $placement) {
            $this->get(route('refer.landing', ['token' => $plains[$placement]]))->assertOk();
            $row = $business->qrCodes()
                ->where('placement', $placement)->where('state', 'active')->sole();
            $this->assertSame($hashesBefore[$placement], $row->token_hash);
        }

        // Still exactly one active code per placement after a
        // second replace of the same placement (the FB-01
        // transaction + lock pattern, no duplicate actives).
        $this->actingAs($owner)
            ->post(route('owner.qr.placement.regenerate', ['placement' => 'table']))
            ->assertRedirect(route('owner.qr'));
        $this->assertSame(1, $business->qrCodes()
            ->where('placement', 'table')->where('state', 'active')->count());
        $this->assertSame(4, $business->qrCodes()->where('state', 'active')->count());
    }

    public function test_scan_through_table_code_records_placement_metadata(): void
    {
        $business = World::business();
        $codes = QrCode::ensurePlacements($business);

        $this->enrollThrough($codes['table']->plainToken());

        $scan = ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'scan')
            ->sole();

        $this->assertSame($codes['table']->id, $scan->metadata['qr_code_id'] ?? null);
        $this->assertSame('table', $scan->metadata['placement'] ?? null);
    }

    public function test_legacy_code_without_placement_resolves(): void
    {
        $business = World::business();

        // A pre-placement row: issued before the column existed,
        // placement null (the migration backfills real rows to
        // 'counter'; rows created outside the issuance path can
        // still carry null and must keep working).
        $plain = OpaqueToken::generate();
        $legacy = $business->qrCodes()->create([
            'token_hash' => OpaqueToken::hash($plain),
            'token_encrypted' => $plain,
            'state' => 'active',
            'placement' => null,
        ]);

        $this->get(route('refer.landing', ['token' => $plain]))->assertOk();

        // The single-code fallback chain still finds it.
        $this->assertTrue($business->activeQrCode()->is($legacy));

        // And a scan through it records which code produced it.
        $this->enrollThrough($plain, '+12125550222');
        $scan = ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'scan')
            ->sole();
        $this->assertSame($legacy->id, $scan->metadata['qr_code_id'] ?? null);
    }
}
