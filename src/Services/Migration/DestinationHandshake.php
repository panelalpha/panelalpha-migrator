<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Services\Migration;

use Exception;
use PanelAlpha\Migrator\Api\Middleware\ResponseFormatter;
use PanelAlpha\Migrator\Helpers\DestinationEndpoint;
use PanelAlpha\Migrator\Helpers\DestinationGuard;
use PanelAlpha\Migrator\Helpers\Http;
use PanelAlpha\Migrator\Helpers\MigrationLock;
use PanelAlpha\Migrator\Helpers\TokenHelper;
use PanelAlpha\Migrator\Services\Backup\ChunkHttpSender;

/**
 * Class DestinationHandshake
 *
 * @package PanelAlpha\Migrator\Services\Migration
 *
 * The first contact with the destination, shared by the dashboard and the panel flow:
 * checks the destination before anything is scanned, and sends the info chunk.
 */
class DestinationHandshake
{
    /** Lowest receive version in the answer of receive/check that this plugin can talk to. */
    private const MIN_RECEIVE_VERSION = 2;

    private DestinationGuard $destinationGuard;
    private MigrationStorageService $storageService;
    private MigrationLock $lock;

    /**
     * @param DestinationGuard|null $destinationGuard
     * @param MigrationStorageService|null $storageService
     * @param MigrationLock|null $lock
     */
    public function __construct(
        ?DestinationGuard $destinationGuard = null,
        ?MigrationStorageService $storageService = null,
        ?MigrationLock $lock = null
    ) {
        $this->destinationGuard = $destinationGuard ?? new DestinationGuard();
        $this->storageService = $storageService ?? new MigrationStorageService();
        $this->lock = $lock ?? new MigrationLock();
    }

    /**
     * Checks that the destination runs a plugin with the receive routes and accepts the token.
     * The pretty REST URL is tried first, then ?rest_route=. The form that answers is returned
     * and has to be saved in the migration data under DestinationEndpoint::MODE_KEY.
     *
     * @param array $target destinationUrl, token, and optionally ip, pinned_ip and insecure_tls
     * @return string DestinationEndpoint::MODE_PRETTY or DestinationEndpoint::MODE_PLAIN
     * @throws DestinationHandshakeException
     * @throws \PanelAlpha\Migrator\Helpers\DestinationNotAllowedException
     */
    public function check(array $target): string
    {
        $destinationUrl = $target['destinationUrl'] ?? $target['destination_url'] ?? $target['du'] ?? null;
        $token = $target['token'] ?? null;

        if (!is_string($destinationUrl) || $destinationUrl === '') {
            throw new DestinationHandshakeException('destination_not_ready', 'Destination URL is not defined.');
        }

        if (!is_string($token) || $token === '') {
            throw new DestinationHandshakeException(
                'destination_not_ready',
                'Destination token is missing. Use the full URL with the token from the destination.'
            );
        }

        $httpArgs = [
            'headers' => ['X-Migrator-Token' => $token],
            'timeout' => 30,
        ];

        $ip = $target['ip'] ?? null;
        $pinned = $target['pinned_ip'] ?? null;
        if (!is_string($pinned) || $pinned === '') {
            $pinned = $this->destinationGuard->assertAllowed($destinationUrl, is_string($ip) && $ip !== '' ? $ip : null);
        }

        if ($pinned !== null) {
            $httpArgs['ip'] = $pinned;
        }

        $httpArgs = array_merge($httpArgs, Http::tlsArgs($target));

        $routeMissing = false;

        foreach ([DestinationEndpoint::MODE_PRETTY, DestinationEndpoint::MODE_PLAIN] as $mode) {
            $endpoint = new DestinationEndpoint($destinationUrl, $mode);
            $response = Http::get($endpoint->url('receive/check', ['_' => (string) time()]), $httpArgs);

            if (Http::isError($response)) {
                throw new DestinationHandshakeException(
                    'destination_not_ready',
                    esc_html('Failed to reach the destination: ' . $response->getErrorMessage())
                );
            }

            $status = Http::retrieveResponseCode($response);
            $body = (string) Http::retrieveBody($response);
            $decoded = json_decode($body, true);
            $served = $status >= 200 && $status < 300 && is_array($decoded) && array_key_exists('receive', $decoded);

            if ($served) {
                if ((int) $decoded['receive'] < self::MIN_RECEIVE_VERSION) {
                    throw $this->incompatible();
                }

                return $mode;
            }

            // A 404, or a 2xx answer that is not ours (the home page), means this URL form
            // is not routed to WordPress or the plugin has no such route. Try the other form.
            if ($status === 404 || ($status >= 200 && $status < 300)) {
                if (is_array($decoded) && ($decoded['code'] ?? null) === 'rest_no_route') {
                    $routeMissing = true;
                }
                continue;
            }

            $error = $this->errorFor($status, $decoded, $body);

            throw $error;
        }

        if ($routeMissing) {
            throw $this->incompatible();
        }

        throw new DestinationHandshakeException(
            'destination_not_ready',
            'The WordPress REST API does not answer on the destination. Check that the destination URL is the site address and that its REST API works.'
        );
    }

    /**
     * Sends the info chunk to the destination once, before the first data chunk.
     * The destination builds its manifest from it, and cannot finish without it.
     * It is recorded as sent only after the destination confirms it.
     *
     * @param string $migrationId
     * @throws Exception
     */
    public function sendInfo(string $migrationId): void
    {
        $this->lock->exclusive($migrationId, function () use ($migrationId) {
            $data = $this->storageService->loadMigrationData($migrationId);

            if (!empty($data['info_sent'])) {
                return;
            }

            $destinationUrl = $data['destinationUrl'] ?? $data['destination_url'] ?? $data['du'] ?? null;
            $token = TokenHelper::forMigration($data);

            if (empty($destinationUrl) || empty($token)) {
                throw new Exception('Destination URL or token not found in migration data');
            }

            $ip = $data['ip'] ?? null;
            $pinned = $data['pinned_ip'] ?? null;
            if (!is_string($pinned) || $pinned === '') {
                $pinned = $this->destinationGuard->assertAllowed(
                    $destinationUrl,
                    is_string($ip) && $ip !== '' ? $ip : null
                );
                $data['pinned_ip'] = $pinned;
            }

            $sender = new ChunkHttpSender(
                $destinationUrl,
                $token,
                !empty($ip) ? $ip : $pinned,
                (bool) ($data['base64'] ?? false),
                Http::tlsArgs($data),
                DestinationEndpoint::modeFrom($data)
            );

            $info = $data;
            $info['type'] = 'info';
            $sender->send(MigrationInfoGenerator::withoutSecrets(ResponseFormatter::convertKeysToSnakeCase($info)));

            $data['info_sent'] = true;
            $this->storageService->saveMigrationData($migrationId, $data);
        });
    }

    private function incompatible(): DestinationHandshakeException
    {
        return new DestinationHandshakeException(
            'destination_incompatible',
            'Destination does not have PanelAlpha Migrator 1.2.1 or newer active. Install or update it on the destination.'
        );
    }

    /**
     * Turns an answer to receive/check that is not a success into an exception.
     * 401 with the code rest_forbidden is the plugin refusing the token. Any other 401 or 403
     * comes from something in front of the plugin, such as a security plugin or a firewall.
     *
     * @param int         $status
     * @param mixed       $decoded
     * @param string|null $body
     * @return DestinationHandshakeException
     */
    private function errorFor(int $status, $decoded, ?string $body): DestinationHandshakeException
    {
        $code = is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : '';
        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : '';

        if ($status === 401 && $code === 'rest_forbidden') {
            if (stripos($message, 'not configured') !== false) {
                return new DestinationHandshakeException(
                    'destination_not_ready',
                    'Destination token is not configured. Open PanelAlpha Migrator on the destination to create it.'
                );
            }

            return new DestinationHandshakeException(
                'destination_not_ready',
                'Destination did not accept the token. Copy the current URL with the token from the destination.'
            );
        }

        if ($status === 401 || $status === 403) {
            return new DestinationHandshakeException(
                'destination_incompatible',
                'Destination REST API is blocked (HTTP ' . $status . ($code !== '' ? ' ' . $code : '') . '). Allow /panelalpha-migrator/v1/ on the destination.'
            );
        }

        return new DestinationHandshakeException(
            'destination_not_ready',
            'Destination returned error: ' . $status . ' - ' . substr((string) $body, 0, 300)
        );
    }
}
