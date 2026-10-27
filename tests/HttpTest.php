<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use WordpressMigrator\Tests\Support\CurlOptionCapture;
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

    public function testManualIpUsesThePortInTheUrl(): void
    {
        $port = $this->destination->port();
        $response = Http::post('http://destination.invalid:' . $port . '/pinned', [
            'ip' => '127.0.0.1',
            'timeout' => 5,
        ]);

        $this->assertFalse(Http::isError($response));
        $this->assertSame(200, Http::retrieveResponseCode($response));
        $request = $this->destination->requests()[0];
        $this->assertSame('/pinned', $request['uri']);
        $this->assertSame('destination.invalid:' . $port, $request['headers']['host']);
    }

    public function testManualIpUsesPort80ForHttpAnd443ForHttps(): void
    {
        $resolve = new \ReflectionMethod(Http::class, 'resolveEntries');
        if (PHP_VERSION_ID < 80100) {
            $resolve->setAccessible(true);
        }

        $this->assertSame(
            ['destination.invalid:80:127.0.0.1'],
            $resolve->invoke(null, 'http://destination.invalid/plain', '127.0.0.1')
        );
        $this->assertSame(
            ['destination.invalid:443:127.0.0.1'],
            $resolve->invoke(null, 'https://destination.invalid/secure', '127.0.0.1')
        );
    }

    public function testResolveEntryIsBuiltForAnUppercaseScheme(): void
    {
        $resolve = new \ReflectionMethod(Http::class, 'resolveEntries');
        if (PHP_VERSION_ID < 80100) {
            $resolve->setAccessible(true);
        }

        $this->assertSame(
            ['destination.invalid:443:127.0.0.1'],
            $resolve->invoke(null, 'HTTPS://destination.invalid/secure', '127.0.0.1')
        );
    }

    public function testResolveEntryIsSkippedForAnythingButOneValidIp(): void
    {
        $resolve = new \ReflectionMethod(Http::class, 'resolveEntries');
        if (PHP_VERSION_ID < 80100) {
            $resolve->setAccessible(true);
        }

        foreach (['127.0.0.1,127.0.0.2', '2130706433', '0x7f000001', '127.1', 'internal.corp', ''] as $ip) {
            $this->assertSame([], $resolve->invoke(null, 'http://destination.invalid/', $ip), $ip);
        }
    }

    public function testRequestsAreLimitedToHttpAndHttps(): void
    {
        CurlOptionCapture::reset();

        Http::post($this->destination->url() . '/protocols', ['timeout' => 5]);

        $allowed = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        $this->assertSame($allowed, CurlOptionCapture::get(CURLOPT_PROTOCOLS));
        $this->assertSame($allowed, CurlOptionCapture::get(CURLOPT_REDIR_PROTOCOLS));
    }

    public function testIpv6PinnedAddressIsBracketedInTheResolveEntry(): void
    {
        $resolve = new \ReflectionMethod(Http::class, 'resolveEntries');
        if (PHP_VERSION_ID < 80100) {
            $resolve->setAccessible(true);
        }

        $this->assertSame(
            ['destination.invalid:443:[2001:db8::1]'],
            $resolve->invoke(null, 'https://destination.invalid/secure', '2001:db8::1')
        );
        $this->assertSame(
            ['destination.invalid:443:[2001:db8::1]'],
            $resolve->invoke(null, 'https://destination.invalid/secure', '[2001:db8::1]')
        );
    }

    public function testHttpsOnPort8443ConnectsToTheManualIpAndKeepsTheHostname(): void
    {
        $capture = $this->captureTlsClientHello(8443);

        $response = Http::post('https://destination.invalid:8443/pinned', [
            'ip' => '127.0.0.1',
            'timeout' => 5,
        ]);

        $hello = $capture();
        $this->assertTrue(Http::isError($response));
        $this->assertStringNotContainsString('Could not resolve host', $response->getErrorMessage());
        $this->assertNotSame('', $hello);
        $this->assertSame("\x16", $hello[0]);
        $this->assertStringContainsString('destination.invalid', $hello);
    }

    public static function tlsScenarios(): array
    {
        return [
            'manual ip and insecure_tls' => [['ip' => '127.0.0.1', 'insecure_tls' => true], false],
            'insecure_tls without a manual ip' => [['insecure_tls' => true], true],
            'manual ip, insecure_tls off' => [['ip' => '127.0.0.1', 'insecure_tls' => false], true],
            'manual ip, insecure_tls as the string false' => [['ip' => '127.0.0.1', 'insecure_tls' => 'false'], true],
            'manual ip, insecure_tls missing' => [['ip' => '127.0.0.1'], true],
            'default' => [[], true],
        ];
    }

    /**
     * @dataProvider tlsScenarios
     */
    #[DataProvider('tlsScenarios')]
    public function testTlsArgsSkipVerificationOnlyForAManualIpWithInsecureTlsTrue(array $tlsData, bool $strict): void
    {
        $this->assertSame($strict ? [] : ['sslverify' => false], Http::tlsArgs($tlsData));
    }

    public function testSslverifyFalseTurnsOffPeerAndHostVerification(): void
    {
        CurlOptionCapture::reset();

        Http::post($this->destination->url() . '/x', ['sslverify' => false, 'timeout' => 5]);

        $this->assertFalse(CurlOptionCapture::peerVerificationEnabled());
        $this->assertFalse(CurlOptionCapture::hostVerificationEnabled());
    }

    public function testVerificationIsStrictByDefault(): void
    {
        CurlOptionCapture::reset();

        Http::post($this->destination->url() . '/x', ['timeout' => 5]);

        $this->assertTrue(CurlOptionCapture::peerVerificationEnabled());
        $this->assertTrue(CurlOptionCapture::hostVerificationEnabled());
    }

    /**
     * Accepts one connection on 127.0.0.1:$port and returns a reader for the bytes it received.
     *
     * @return callable(): string
     */
    private function captureTlsClientHello(int $port): callable
    {
        $directory = sys_get_temp_dir() . '/wp-migrator-tls-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/listen.php', <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:' . (int) getenv('LISTEN_PORT'), $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
file_put_contents(getenv('LISTEN_READY'), '1');
$client = stream_socket_accept($server, 5);
$bytes = is_resource($client) ? fread($client, 8192) : '';
file_put_contents(getenv('LISTEN_OUT'), $bytes === false ? '' : $bytes);
if (is_resource($client)) {
    fclose($client);
}
fclose($server);
PHP);

        $process = proc_open([PHP_BINARY, $directory . '/listen.php'], [
            0 => ['pipe', 'r'],
            1 => ['file', $directory . '/stdout', 'w'],
            2 => ['file', $directory . '/stderr', 'w'],
        ], $pipes, null, array_merge(getenv(), [
            'LISTEN_PORT' => (string) $port,
            'LISTEN_READY' => $directory . '/ready',
            'LISTEN_OUT' => $directory . '/capture',
        ]));
        $this->assertIsResource($process);

        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (file_exists($directory . '/ready')) {
                $ready = true;
                break;
            }
            usleep(20000);
        }
        $this->assertTrue($ready, 'The port 8443 listener did not start');

        return function () use ($process, $pipes, $directory): string {
            for ($attempt = 0; $attempt < 50; $attempt++) {
                if (file_exists($directory . '/capture')) {
                    break;
                }
                usleep(20000);
            }
            $bytes = file_exists($directory . '/capture') ? (string) file_get_contents($directory . '/capture') : '';
            fclose($pipes[0]);
            proc_close($process);
            @unlink($directory . '/listen.php');
            @unlink($directory . '/ready');
            @unlink($directory . '/capture');
            @unlink($directory . '/stdout');
            @unlink($directory . '/stderr');
            @rmdir($directory);

            return $bytes;
        };
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
