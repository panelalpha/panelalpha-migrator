<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Http;
use WordpressMigrator\Helpers\HttpError;
use WordpressMigrator\Helpers\HttpResponse;
use WordpressMigrator\Tests\Support\FakeDestination;

final class HttpTest extends TestCase
{
    private FakeDestination $destination;

    protected function setUp(): void
    {
        $this->destination = FakeDestination::get();
    }

    public function testPostSendsBodyAndHeadersOverHttp11(): void
    {
        $response = Http::post($this->destination->url() . '/endpoint?x=1', [
            'headers' => ['Content-Type' => 'application/json', 'X-Migrator-Token' => 'abc'],
            'body' => '{"a":1}',
            'timeout' => 5,
        ]);

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertFalse(Http::isError($response));
        $this->assertSame(200, Http::retrieveResponseCode($response));
        $this->assertSame('{"success":true}', Http::retrieveBody($response));

        $request = $this->destination->requests()[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/endpoint?x=1', $request['uri']);
        $this->assertSame('HTTP/1.1', $request['protocol']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('abc', $request['headers']['x-migrator-token']);
        $this->assertSame('{"a":1}', $request['body']);
    }

    public function testErrorStatusIsAResponseNotAnError(): void
    {
        $this->destination->respondWith('/broken', 500, '{"error":"boom"}');

        $response = Http::post($this->destination->url() . '/broken', ['timeout' => 5]);

        $this->assertFalse(Http::isError($response));
        $this->assertSame(500, Http::retrieveResponseCode($response));
        $this->assertSame('{"error":"boom"}', Http::retrieveBody($response));
    }

    public function testConnectionFailureIsAnError(): void
    {
        $response = Http::post(FakeDestination::closedPortUrl() . '/x', ['timeout' => 5]);

        $this->assertInstanceOf(HttpError::class, $response);
        $this->assertTrue(Http::isError($response));
        $this->assertNotSame('', $response->getErrorMessage());
    }

    public function testIpPinsTheHostNameOnPorts80And443Only(): void
    {
        // The pin covers ports 80 and 443, so a name that does not resolve still fails on another port.
        $response = Http::post('http://destination.invalid:' . $this->destination->port() . '/x', [
            'ip' => '127.0.0.1',
            'timeout' => 5,
        ]);

        $this->assertTrue(Http::isError($response));
        $this->assertSame([], $this->destination->requests());
    }

    public function testResponseAndErrorHoldTheirValues(): void
    {
        $response = new HttpResponse(false, 0);
        $error = new HttpError('timed out');

        $this->assertFalse($response->getBody());
        $this->assertSame(0, $response->getStatusCode());
        $this->assertSame('timed out', $error->getErrorMessage());
        $this->assertFalse(Http::isError($response));
    }
}
