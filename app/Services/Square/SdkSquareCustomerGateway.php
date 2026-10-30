<?php

namespace App\Services\Square;

use App\Contracts\SquareCustomerGateway;
use App\Models\Business;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Environments;
use Square\Exceptions\SquareApiException;
use Square\SquareClient;
use Square\Types\Address;
use Throwable;

/**
 * The real Square customer gateway (spec Sections 4.1 and 9),
 * behind the contract Phase 1 shipped. The official SDK client is
 * built from configuration only: environment from SQUARE_ENVIRONMENT,
 * token from the environment, version pinned when SQUARE_VERSION is
 * set, never anything hardcoded.
 *
 * Failure semantics are exactly the spec's: any failure (API error,
 * timeout, transport fault) returns null, the signup completes with
 * square_customer_id null in the pending provisioning state, and the
 * Phase 4 sweeper (Section 9.7) retries creation. Failures are
 * logged with the business ID and the Square error category only;
 * the token and the request payload are never written (Section 8.8).
 *
 * The PSR-18 transport: production passes an explicit Guzzle client
 * carrying the configured timeout (the SDK warns, which Laravel
 * turns into an exception, when a timeout is requested of a
 * transport that cannot honor it, so the timeout lives in the
 * client, not the SDK options); tests inject a stub and never touch
 * the network. Live sandbox verification of this gateway is a Phase
 * 4 kickoff gate (Amendment 5): no sandbox credentials exist yet.
 */
class SdkSquareCustomerGateway implements SquareCustomerGateway
{
    public function __construct(private ?ClientInterface $httpClient = null) {}

    public function createCustomerFor(Business $business): ?string
    {
        $token = (string) config('services.square.access_token');

        if ($token === '') {
            Log::error('Square customer creation attempted without a configured access token.', [
                'business_id' => $business->id,
            ]);

            return null;
        }

        $request = (new CreateCustomerRequest)
            ->setIdempotencyKey('er2-business-'.$business->id)
            ->setCompanyName($business->name)
            ->setEmailAddress($business->contact_email)
            ->setPhoneNumber($business->contact_phone_e164)
            ->setReferenceId('business-'.$business->id);

        // RS2-2: provisioning can now be asked for before the
        // business has an address (the Card step precedes the
        // Location step for a checkpoint account). A request
        // carrying a null address is never sent: the Address is
        // attached only when a street exists, exactly as it was
        // when every business had an address at signup.
        if (trim((string) $business->street) !== '') {
            $request->setAddress((new Address)
                ->setAddressLine1($business->street)
                ->setLocality($business->city)
                ->setAdministrativeDistrictLevel1($business->state)
                ->setPostalCode($business->postal_code)
                ->setCountry($business->country));
        }

        try {
            $response = $this->client($token)->customers->create($request);
        } catch (SquareApiException $e) {
            Log::error('Square customer creation failed with an API error.', [
                'business_id' => $business->id,
                'status_code' => $e->getStatusCode(),
                'square_errors' => $this->errorSummary($e),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::error('Square customer creation failed before a response.', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);

            return null;
        }

        $customerId = $response->getCustomer()?->getId();

        if ($customerId === null) {
            Log::error('Square customer creation returned no customer.', [
                'business_id' => $business->id,
            ]);

            return null;
        }

        return $customerId;
    }

    private function client(string $token): SquareClient
    {
        $options = [
            'baseUrl' => config('services.square.environment') === 'production'
                ? Environments::Production->value
                : Environments::Sandbox->value,
            'maxRetries' => 0,
            'client' => $this->httpClient ?? $this->defaultClient(),
        ];

        $version = config('services.square.version');

        return new SquareClient(
            token: $token,
            version: is_string($version) && $version !== '' ? $version : null,
            options: $options,
        );
    }

    private function defaultClient(): ClientInterface
    {
        return new \GuzzleHttp\Client([
            'timeout' => (float) config('services.square.timeout', 10),
            'connect_timeout' => 5.0,
        ]);
    }

    /**
     * The error categories and codes Square returned. Deliberately
     * not the response body: bodies can echo request data, and the
     * log rule (Section 8.8) writes summaries, never payloads.
     *
     * @return list<string>
     */
    private function errorSummary(SquareApiException $e): array
    {
        $body = json_decode($e->getBody() ?? '', true);
        $summary = [];

        if (is_array($body) && is_array($body['errors'] ?? null)) {
            foreach ($body['errors'] as $error) {
                if (is_array($error)) {
                    $summary[] = (string) ($error['category'] ?? 'unknown').':'.(string) ($error['code'] ?? 'unknown');
                }
            }
        }

        return $summary;
    }
}
