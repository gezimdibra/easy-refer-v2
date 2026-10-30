<?php

namespace Tests\Feature;

use App\Mail\AllowanceLastVisitMail;
use App\Mail\AllowancePausedMail;
use App\Mail\BillingForecastMail;
use App\Mail\CardExpiredMail;
use App\Mail\CardExpiryGuardMail;
use App\Mail\DunningPauseWarningMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\DunningRecoveredMail;
use App\Mail\DunningReminderMail;
use App\Mail\FirstVisitAlertMail;
use App\Mail\LifecycleFirstScanMail;
use App\Mail\LifecycleSetupPosterMail;
use App\Mail\LifecycleSetupRewardMail;
use App\Mail\NewsletterConfirmationMail;
use App\Mail\WelcomeMail;
use App\Models\BillingRecord;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\Support\World;
use Tests\TestCase;

/**
 * EU-S1 (R5 pack, finding 1 + unit EU-S1's named check): the custom
 * email family's dark scheme is repaired. Every template renders
 * with a dark block in which the button cell's background (amber
 * #f5a300) differs from the card background (#221d17), the link
 * color (#f0b64a) differs from both dark grounds, the wordmark
 * carries its dark class, and every anchor is either an em-a link
 * or the one button anchor inside the em-btn cell.
 */
class EmailDarkSchemeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, Mailable> */
    private function mailables(): array
    {
        $business = World::business();
        $record = new BillingRecord(['amount_cents' => 4200]);
        // The recovered mail reads the record's period and its
        // events relation, so its fixture record is persisted.
        $persisted = new BillingRecord;
        $persisted->forceFill([
            'business_id' => $business->id, 'kind' => 'monthly', 'amount_cents' => 4200,
            'idempotency_key' => 'dark-scheme-fixture', 'state' => 'paid',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
        ]);
        $persisted->save();
        $card = new PaymentMethod(['exp_month' => 11, 'exp_year' => 2027]);
        $user = new User;
        $user->forceFill(['id' => 7, 'name' => 'Dana Owner', 'email' => 'dana@example.test']);
        $user->setRelation('business', $business);

        return [
            'welcome' => new WelcomeMail($user),
            'billing-forecast' => new BillingForecastMail($business, 'Your next charge is $42.'),
            'card-expiry-guard' => new CardExpiryGuardMail($business, $card),
            'dunning-failed' => new DunningPaymentFailedMail($business, $record, now()->addDays(7)),
            'dunning-reminder' => new DunningReminderMail($business, $record, now()->addDays(7)),
            'dunning-pause' => new DunningPauseWarningMail($business, $record, now()->addDays(7)),
            'dunning-recovered' => new DunningRecoveredMail($business, $persisted),
            'first-visit-alert' => new FirstVisitAlertMail($business),
            'newsletter-confirmation' => new NewsletterConfirmationMail('https://example.test/confirm'),
            // M-5 (Amendment 72): the six lifecycle mailables
            // render on the same shell, so the same dark repair
            // covers them.
            'lifecycle-setup-reward' => new LifecycleSetupRewardMail($business, $user),
            'lifecycle-setup-poster' => new LifecycleSetupPosterMail($business),
            'lifecycle-first-scan' => new LifecycleFirstScanMail($business),
            'allowance-last' => new AllowanceLastVisitMail($business),
            'allowance-paused' => new AllowancePausedMail($business),
            'card-expired' => new CardExpiredMail($business, $card),
        ];
    }

    public function test_every_custom_email_carries_the_repaired_dark_scheme(): void
    {
        foreach ($this->mailables() as $name => $mailable) {
            $html = $mailable->render();

            $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html, $name);
            $this->assertMatchesRegularExpression(
                '/\.em-card \{ background-color: #221d17 !important/',
                $html,
                $name.': card lifts to the dark surface above the outer ground',
            );
            $this->assertMatchesRegularExpression(
                '/\.em-btn \{ background-color: #f5a300 !important; \}/',
                $html,
                $name.': button goes amber in dark, never the card color',
            );
            $this->assertMatchesRegularExpression(
                '/\.em-a \{ color: #f0b64a !important; \}/',
                $html,
                $name.': links take the light-amber token in dark',
            );
            $this->assertMatchesRegularExpression(
                '/\.em-wordmark \{ color: #faf6ef !important; \}/',
                $html,
                $name.': wordmark flips to the cream token in dark',
            );
            $this->assertStringContainsString('class="em-wordmark"', $html, $name.': wordmark span classed');
            $this->assertStringContainsString('class="em-foot"', $html, $name.': footer cell classed');
            $this->assertStringContainsString('class="em-rule"', $html, $name.': header rule classed');

            // Every anchor is accounted for: an em-a link, or the
            // single button anchor inside the em-btn cell.
            preg_match_all('/<a [^>]*>/', $html, $anchors);
            foreach ($anchors[0] as $anchor) {
                $this->assertTrue(
                    str_contains($anchor, 'em-a') || str_contains($html, 'class="em-btn"'),
                    $name.': unclassed anchor '.$anchor,
                );
            }
            $unclassed = array_filter($anchors[0], fn ($a) => ! str_contains($a, 'em-a'));
            $this->assertLessThanOrEqual(1, count($unclassed), $name.': at most the one button anchor is unclassed');
            if (count($unclassed) === 1) {
                $this->assertStringContainsString('class="em-btn"', $html, $name.': the unclassed anchor is the button');
            }
        }
    }
}
