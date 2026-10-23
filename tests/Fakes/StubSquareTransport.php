<?php

namespace Tests\Fakes;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 transport stub for the Square SDK: records the request it
 * was handed and replies with a canned response (or throws, to stand
 * in for a timeout or a dead network). Tests using it never touch the
 * network.
 */
class StubSquareTransport implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function __construct(private ResponseInterface|ClientExceptionInterface $outcome) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        if ($this->outcome instanceof ClientExceptionInterface) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

/**
 * A transport-level failure (timeout, DNS, refused connection) in
 * PSR-18 clothing.
 */
class StubTransportFailure extends RuntimeException implements ClientExceptionInterface {}
