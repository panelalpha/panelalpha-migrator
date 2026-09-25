<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class RestoreHttpAuthenticationTest extends TestCase
{
    use TemporaryDirectory;

    /** @var resource|null */
    private $server = null;

    private int $port;

    protected function setUp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = (int) substr($address, strrpos($address, ':') + 1);

        $directory = $this->createTemporaryDirectory();
        $this->server = proc_open([
            PHP_BINARY, '-S', '127.0.0.1:' . $this->port,
            __DIR__ . '/Support/restore-auth-router.php',
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', $directory . 'server.log', 'a'],
            2 => ['file', $directory . 'server.log', 'a'],
        ], $pipes);

        if (!is_resource($this->server)) {
            $this->fail('Could not start PHP HTTP server');
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }

        $this->fail('PHP HTTP server did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    public function testMissingTokenReturnsUnauthorized(): void
    {
        [$status, $body] = $this->request();

        $this->assertSame(401, $status);
        $this->assertSame(['error' => 'Unauthorized - missing authentication token'], $body);
    }

    public function testInvalidHeaderTokenReturnsForbidden(): void
    {
        [$status, $body] = $this->request('', ['X-Migrator-Token: wrong']);

        $this->assertSame(403, $status);
        $this->assertSame(['error' => 'Unauthorized - invalid token'], $body);
    }

    public function testInvalidQueryTokenReturnsForbidden(): void
    {
        [$status, $body] = $this->request('?token=wrong');

        $this->assertSame(403, $status);
        $this->assertSame(['error' => 'Unauthorized - invalid token'], $body);
    }

    public function testValidHeaderTokenIsAccepted(): void
    {
        [$status, $body] = $this->request('', ['X-Migrator-Token: secret']);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true], $body);
    }

    private function request(string $query = '', array $headers = []): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);
        $response = file_get_contents('http://127.0.0.1:' . $this->port . '/' . $query, false, $context);

        $this->assertNotFalse($response);
        $this->assertMatchesRegularExpression('/^HTTP\/1\.[01] (\d{3})/', $http_response_header[0]);
        preg_match('/^HTTP\/1\.[01] (\d{3})/', $http_response_header[0], $matches);

        return [(int) $matches[1], json_decode($response, true, 512, JSON_THROW_ON_ERROR)];
    }
}
