<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Models\Business;
use App\Models\SmsCampaign;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;
use Throwable;
use TypeError;

/**
 * F-1 (deploy50 report): a word-shaped campaign segment reached
 * CampaignsController's `int $campaign` parameters and threw a
 * TypeError (HTTP 500 + an ERROR log entry) instead of the
 * designed 404. Campaigns was the only owner controller taking a
 * raw int route parameter; every sibling (Customers, Referrals,
 * Rewards, Reviews, Settings statements, Admin) uses implicit
 * model binding, where a word or a missing id resolves to no
 * model and the framework answers 404 before the controller runs.
 * The fix brings Campaigns onto that same convention.
 *
 * How these pins distinguish the defect: with exception handling
 * ON (the default), a TypeError is rendered as the branded 500
 * ("Something went wrong"), so asserting status 404 + the
 * designed "Page not found" copy + the signed-in portal chrome
 * separates the two outcomes exactly; the
 * withoutExceptionHandling pin additionally asserts the raised
 * throwable is a not-found exception, never a TypeError.
 */
class CampaignSlug404Test extends TestCase
{
    use RefreshDatabase;

    private FakeMarketingSmsGateway $gateway;

    private int $phoneSequence = 7000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeMarketingSmsGateway;
        $this->app->instance(MarketingSmsGateway::class, $this->gateway);
    }

    private function member(Business $business): void
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();
    }

    private function campaign(Business $business, string $status = SmsCampaign::STATUS_DRAFT): SmsCampaign
    {
        return SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'status' => $status,
            'created_by' => World::owner($business)->id,
        ]);
    }

    /**
     * The designed 404 for a signed-in owner (r27): the in-app
     * themed error page (portal chrome, "Back to Overview"), not
     * the 500 page and not the framework default.
     */
    private function assertDesigned404(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertSee('Page not found');
        $response->assertSee('We could not find the page you were looking for.');
        $response->assertSee('portal-theme', false);
        $response->assertSee('Back to Overview');
        $response->assertDontSee('Something went wrong');
        $response->assertDontSee('normalize.css');
    }

    // --- show (the F-1 route of record) ---

    public function test_show_word_slugs_render_the_designed_404(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        // "create" is the literal probe from the F-1 record (the
        // composer lives at /compose; /create binds as an id).
        $this->assertDesigned404($this->actingAs($owner)->get(route('campaigns.show', ['campaign' => 'create'])));
        $this->assertDesigned404($this->actingAs($owner)->get(route('campaigns.show', ['campaign' => 'nonsense'])));
    }

    public function test_show_nonexistent_numeric_is_the_designed_404(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->assertDesigned404($this->actingAs($owner)->get(route('campaigns.show', ['campaign' => 999999])));
    }

    public function test_show_real_campaign_is_unchanged(): void
    {
        $business = World::business();
        $campaign = $this->campaign($business);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.show', $campaign));

        $response->assertOk();
        $response->assertSee('Spring sale is on');
    }

    // --- confirm ---

    public function test_confirm_word_and_missing_ids_are_the_designed_404(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->assertDesigned404($this->actingAs($owner)->get(route('campaigns.confirm', ['campaign' => 'nonsense'])));
        $this->assertDesigned404($this->actingAs($owner)->get(route('campaigns.confirm', ['campaign' => 999999])));
    }

    public function test_confirm_real_draft_is_unchanged(): void
    {
        $business = World::business();
        $this->member($business);
        $campaign = $this->campaign($business);

        $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign))->assertOk();
    }

    // --- status (JSON poller) ---

    public function test_status_word_and_missing_ids_are_404(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->assertDesigned404($this->actingAs($owner)->get(route('campaigns.status', ['campaign' => 'nonsense'])));
        $this->assertDesigned404($this->actingAs($owner)->get(route('campaigns.status', ['campaign' => 999999])));
        $this->actingAs($owner)->getJson(route('campaigns.status', ['campaign' => 'nonsense']))->assertNotFound();
    }

    public function test_status_real_campaign_is_unchanged(): void
    {
        $business = World::business();
        $campaign = $this->campaign($business);

        $response = $this->actingAs(World::owner($business))->getJson(route('campaigns.status', $campaign));

        $response->assertOk();
        $response->assertJsonPath('status', SmsCampaign::STATUS_DRAFT);
    }

    // --- duplicate ---

    public function test_duplicate_word_and_missing_ids_are_the_designed_404(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $before = SmsCampaign::query()->count();

        $this->assertDesigned404($this->actingAs($owner)->post(route('campaigns.duplicate', ['campaign' => 'nonsense'])));
        $this->assertDesigned404($this->actingAs($owner)->post(route('campaigns.duplicate', ['campaign' => 999999])));
        $this->assertSame($before, SmsCampaign::query()->count());
    }

    public function test_duplicate_real_sent_campaign_is_unchanged(): void
    {
        $business = World::business();
        $sent = $this->campaign($business, SmsCampaign::STATUS_SENT);

        $response = $this->actingAs(World::owner($business))->post(route('campaigns.duplicate', $sent));

        $copy = SmsCampaign::query()->where('business_id', $business->id)->where('status', SmsCampaign::STATUS_DRAFT)->latest('id')->firstOrFail();
        $response->assertRedirect(route('campaigns.confirm', $copy));
    }

    // --- send ---

    public function test_send_word_and_missing_ids_are_the_designed_404(): void
    {
        $business = World::business();
        $this->member($business);
        $owner = World::owner($business);

        $this->assertDesigned404($this->actingAs($owner)->post(route('campaigns.send', ['campaign' => 'nonsense'])));
        $this->assertDesigned404($this->actingAs($owner)->post(route('campaigns.send', ['campaign' => 999999])));
        $this->assertSame([], $this->gateway->sent);
    }

    public function test_send_real_draft_is_unchanged(): void
    {
        $business = World::business();
        $this->member($business);
        $campaign = $this->campaign($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));

        $response = $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $response->assertRedirect(route('campaigns.show', $campaign));
        $this->assertCount(1, $this->gateway->sent);
    }

    // --- resend ---

    public function test_resend_word_and_missing_ids_are_the_designed_404(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $before = SmsCampaign::query()->count();

        $this->assertDesigned404($this->actingAs($owner)->post(route('campaigns.resend', ['campaign' => 'nonsense'])));
        $this->assertDesigned404($this->actingAs($owner)->post(route('campaigns.resend', ['campaign' => 999999])));
        $this->assertSame($before, SmsCampaign::query()->count());
    }

    public function test_resend_real_sent_campaign_is_unchanged(): void
    {
        $business = World::business();
        $sent = $this->campaign($business, SmsCampaign::STATUS_SENT);

        // No failed recipients: the existing flow lands back on
        // the campaign page with its "none can receive" status.
        $response = $this->actingAs(World::owner($business))->post(route('campaigns.resend', $sent));

        $response->assertRedirect(route('campaigns.show', $sent));
    }

    // --- destroy ---

    public function test_destroy_word_and_missing_ids_are_the_designed_404(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->assertDesigned404($this->actingAs($owner)->delete(route('campaigns.destroy', ['campaign' => 'nonsense'])));
        $this->assertDesigned404($this->actingAs($owner)->delete(route('campaigns.destroy', ['campaign' => 999999])));
    }

    public function test_destroy_real_draft_is_unchanged(): void
    {
        $business = World::business();
        $draft = $this->campaign($business);

        $response = $this->actingAs(World::owner($business))->delete(route('campaigns.destroy', $draft));

        $response->assertRedirect(route('campaigns.index'));
        $this->assertSame(0, SmsCampaign::query()->where('id', $draft->id)->count());
    }

    // --- the distinguisher: not-found, never TypeError ---

    public function test_word_segments_raise_not_found_never_a_type_error(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->actingAs($owner);
        $this->withoutExceptionHandling();

        $urls = [
            route('campaigns.show', ['campaign' => 'create']),
            route('campaigns.show', ['campaign' => 'nonsense']),
            route('campaigns.confirm', ['campaign' => 'nonsense']),
            route('campaigns.status', ['campaign' => 'nonsense']),
        ];

        foreach ($urls as $url) {
            try {
                $response = $this->get($url);
                $this->assertSame(404, $response->getStatusCode(), $url);
            } catch (Throwable $e) {
                $this->assertNotInstanceOf(TypeError::class, $e, $url);
                $this->assertTrue(
                    $e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException,
                    $url.' raised '.get_class($e)
                );
            }
        }
    }

    // --- survey pins: the sibling conventions were never exposed ---

    public function test_sibling_model_bound_routes_already_404_on_word_segments(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('customers.show', ['membership' => 'nonsense']))->assertNotFound();
        $this->actingAs($owner)->get(route('referrals.show', ['referral' => 'nonsense']))->assertNotFound();
        $this->actingAs($owner)->get(route('settings.billing.statement', ['record' => 'nonsense']))->assertNotFound();
        $this->actingAs($owner)->post(route('rewards.resend', ['reward' => 'nonsense']))->assertNotFound();
        $this->actingAs($owner)->post(route('reviews.referrals.release', ['referral' => 'nonsense']))->assertNotFound();
    }

    public function test_string_allowlist_siblings_already_404_on_word_segments(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'nonsense']))->assertNotFound();
        $this->actingAs($owner)->get(route('owner.qr.image.placement', ['placement' => 'nonsense']))->assertNotFound();
        $this->actingAs($owner)->get(route('onboarding.step', ['step' => 'nonsense']))->assertNotFound();
    }
}
