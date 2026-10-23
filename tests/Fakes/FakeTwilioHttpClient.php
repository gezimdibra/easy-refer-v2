<?php

namespace Tests\Fakes;

use Twilio\AuthStrategy\AuthStrategy;
use Twilio\Http\Client;
use Twilio\Http\Response;

/**
 * The Twilio transport seam (twilio-blueprint Section 2): canned
 * provider responses keyed by URL fragment, so gateway unit tests
 * drive every mapping branch without a network or an account.
 */
class FakeTwilioHttpClient implements Client
{
    /** @var list<array{method: string, url: string, data: array}> */
    public array $requests = [];

    /** @var list<array{match: string, status: int, body: array}> */
    public array $responses = [];

    public function respondWhen(string $urlFragment, int $status, array $body): void
    {
        $this->responses[] = ['match' => $urlFragment, 'status' => $status, 'body' => $body];
    }

    public function request(string $method, string $url, array $params = [], array $data = [], array $headers = [], ?string $user = null, ?string $password = null, ?int $timeout = null, ?AuthStrategy $authStrategy = null): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'data' => $data];

        foreach ($this->responses as $response) {
            if (str_contains($url, $response['match'])) {
                return new Response($response['status'], json_encode($response['body']));
            }
        }

        return new Response(404, json_encode(['code' => 20404, 'message' => 'not found']));
    }
}
