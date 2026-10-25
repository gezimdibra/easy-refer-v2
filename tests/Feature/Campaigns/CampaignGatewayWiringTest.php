<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Services\Twilio\NullMarketingSmsGateway;
use App\Services\Twilio\TwilioMarketingSmsGateway;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The marketing gateway wiring contract (design Section 3.3):
 * the binding resolves the real gateway only when the account
 * key set AND the marketing messaging service are configured;
 * a half-configured environment gets the null gateway, never a
 * half-open pool. A mis-wired integration fails here, in
 * seconds, locally.
 */
class CampaignGatewayWiringTest extends TestCase
{
    public function test_unconfigured_environment_binds_the_null_gateway(): void
    {
        $this->assertInstanceOf(NullMarketingSmsGateway::class, $this->app->make(MarketingSmsGateway::class));
    }

    public function test_full_configuration_binds_the_real_gateway(): void
    {
        Config::set('services.twilio.account_sid', 'AC_TEST');
        Config::set('services.twilio.api_key_sid', 'SK_TEST');
        Config::set('services.twilio.api_key_secret', 'test-secret');
        Config::set('services.twilio.marketing_messaging_service_sid', 'MG_MARKETING_TEST');

        $this->assertInstanceOf(TwilioMarketingSmsGateway::class, $this->app->make(MarketingSmsGateway::class));
    }

    public function test_account_keys_without_the_marketing_service_stay_null(): void
    {
        Config::set('services.twilio.account_sid', 'AC_TEST');
        Config::set('services.twilio.api_key_sid', 'SK_TEST');
        Config::set('services.twilio.api_key_secret', 'test-secret');
        Config::set('services.twilio.marketing_messaging_service_sid', null);

        $this->assertInstanceOf(NullMarketingSmsGateway::class, $this->app->make(MarketingSmsGateway::class));
    }
}
