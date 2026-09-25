<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests\Support;

use RuntimeException;

/**
 * A PHP built-in server on 127.0.0.1 that records requests, standing in for the destination site.
 */
final class FakeDestination
{
    private static ?self $instance = null;

    /** @var resource */
    private $process;

    private string $directory;

    private int $port;

    private function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/wp-migrator-fake-destination-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0755, true);
        $this->port = self::findFreePort();

        $command = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $this->port,
            __DIR__ . '/fake-destination-router.php',
        ];
        $environment = array_merge(getenv(), ['FAKE_DESTINATION_DIR' => $this->directory]);

        $this->process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $this->directory . '/server.out', 'a'],
            2 => ['file', $this->directory . '/server.out', 'a'],
        ], $pipes, null, $environment);

        if (!is_resource($this->process)) {
            throw new RuntimeException('Could not start the fake destination server');
        }

        $this->waitUntilListening();
        register_shutdown_function([$this, 'stop']);
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        self::$instance->reset();

        return self::$instance;
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Answers requests whose URI contains $match with the given status and body.
     */
    public function respondWith(string $match, int $status, string $body = '{}'): void
    {
        $rules = $this->rules();
        $rules[] = ['match' => $match, 'status' => $status, 'body' => $body];
        file_put_contents($this->directory . '/rules.json', json_encode($rules));
    }

    /**
     * @return array<int, array{method: string, uri: string, protocol: string, headers: array, body: string}>
     */
    public function requests(): array
    {
        $log = $this->directory . '/requests.log';

        if (!file_exists($log)) {
            return [];
        }

        $lines = array_filter(explode("\n", file_get_contents($log)));

        return array_values(array_map(fn (string $line) => json_decode($line, true), $lines));
    }

    public function reset(): void
    {
        @unlink($this->directory . '/requests.log');
        @unlink($this->directory . '/rules.json');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->reset();
        @unlink($this->directory . '/server.out');
        @rmdir($this->directory);
    }

    private function rules(): array
    {
        $file = $this->directory . '/rules.json';

        return file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    }

    private function waitUntilListening(): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.1);

            if ($socket !== false) {
                fclose($socket);
                return;
            }

            usleep(50000);
        }

        throw new RuntimeException('The fake destination server did not start on port ' . $this->port);
    }

    private static function findFreePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    /**
     * A port nothing listens on, for connection failures.
     */
    public static function closedPortUrl(): string
    {
        return 'http://127.0.0.1:' . self::findFreePort();
    }
}
