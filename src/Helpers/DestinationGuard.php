<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Helpers;

/**
 * Refuses migration destinations that point at the local machine or an internal network.
 *
 * A hostname or IP listed in allowed_destinations is accepted. An empty list still
 * allows public destinations.
 */
class DestinationGuard
{
    /** @var list<array{0: string, 1: int}> */
    private const IPV4_RANGES = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['100.64.0.0', 10],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /** @var list<array{0: string, 1: int}> */
    private const IPV6_RANGES = [
        ['::', 96],
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
        ['2001::', 32],
        ['2001:db8::', 32],
        ['fe80::', 10],
        ['fec0::', 10],
        ['fc00::', 7],
        ['ff00::', 8],
    ];

    private const IPV6_6TO4_PREFIX = "\x20\x02";

    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /** @var callable(string): list<string> */
    private $resolver;

    /** @var list<string>|null */
    private ?array $allowed;

    /**
     * @param callable(string): list<string>|null $resolver
     * @param list<string>|null                   $allowedDestinations
     */
    public function __construct(?callable $resolver = null, ?array $allowedDestinations = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            return self::systemResolver($host);
        };
        $this->allowed = $allowedDestinations;
    }

    /**
     * Returns the IP the connection must be pinned to: the manual IP, or the first
     * resolved address once every resolved address passed. Null for a listed host
     * name, which is trusted without resolving. A manual IP must be exactly one valid
     * IP address and the URL scheme must be http or https.
     *
     * @throws DestinationNotAllowedException
     */
    public function assertAllowed(string $destinationUrl, ?string $manualIp = null): ?string
    {
        $host = $this->hostFromUrl($destinationUrl);
        $manualIp = $this->normalizeManualIp($manualIp);

        // The connection goes to the manual IP, so the host name is not resolved.
        if ($manualIp !== null) {
            if (!$this->isListed($manualIp) && $this->isBlockedAddress($manualIp)) {
                throw new DestinationNotAllowedException();
            }

            return $manualIp;
        }

        if (!$this->isListed($host)) {
            return $this->assertHostAllowed($host);
        }

        return $this->isIp($host) ? $host : null;
    }

    /**
     * @return list<string>
     */
    private static function systemResolver(string $host): array
    {
        $addresses = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            foreach ($ipv4 as $ip) {
                if (is_string($ip)) {
                    $addresses[] = $ip;
                }
            }
        }

        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (is_array($record) && isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @throws DestinationNotAllowedException
     */
    private function assertHostAllowed(string $host): string
    {
        if ($this->isBlockedAddress($host)) {
            throw new DestinationNotAllowedException();
        }

        if ($this->isIp($host)) {
            return $host;
        }

        $addresses = ($this->resolver)($host);
        if ($addresses === []) {
            throw new DestinationNotAllowedException();
        }

        foreach ($addresses as $address) {
            if (!is_string($address)) {
                throw new DestinationNotAllowedException();
            }
            $normalized = $this->normalizeHost($address);
            if ($this->isBlockedAddress($normalized) && !$this->isListed($normalized)) {
                throw new DestinationNotAllowedException();
            }
        }

        return $this->normalizeHost((string) reset($addresses));
    }

    /**
     * @throws DestinationNotAllowedException
     */
    private function hostFromUrl(string $destinationUrl): string
    {
        $parsed = wp_parse_url($destinationUrl);
        if (!is_array($parsed) || empty($parsed['host']) || !is_string($parsed['host'])) {
            throw new DestinationNotAllowedException();
        }

        $scheme = isset($parsed['scheme']) && is_string($parsed['scheme']) ? strtolower($parsed['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new DestinationNotAllowedException();
        }

        return $this->normalizeHost($parsed['host']);
    }

    /**
     * @throws DestinationNotAllowedException
     */
    private function normalizeManualIp(?string $manualIp): ?string
    {
        if ($manualIp === null) {
            return null;
        }

        $manualIp = $this->normalizeHost($manualIp);
        if ($manualIp === '') {
            return null;
        }

        if (!$this->isIp($manualIp)) {
            throw new DestinationNotAllowedException();
        }

        return $manualIp;
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $zone = strpos($host, '%');
        if ($zone !== false) {
            $host = substr($host, 0, $zone);
        }

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        return $host;
    }

    private function isListed(string $candidate): bool
    {
        $candidateIp = $this->comparableIp($candidate);
        $candidateName = strtolower($candidate);

        foreach ($this->allowedDestinations() as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                continue;
            }

            $normalized = $this->normalizeHost($entry);
            $entryIp = $this->comparableIp($normalized);
            if ($candidateIp !== null && $entryIp !== null) {
                if ($candidateIp === $entryIp) {
                    return true;
                }
                continue;
            }

            if ($candidateIp === null && $entryIp === null && strtolower($normalized) === $candidateName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedDestinations(): array
    {
        if ($this->allowed !== null) {
            return $this->allowed;
        }

        $configured = Config::get('allowed_destinations');
        if (is_array($configured)) {
            return $this->stringList($configured);
        }

        return TokenHelper::getAllowedDestinations();
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $list[] = $value;
            }
        }

        return $list;
    }

    private function isBlockedAddress(string $host): bool
    {
        if (strtolower($host) === 'localhost') {
            return true;
        }

        $packed = $this->packedIp($host);
        if ($packed === null) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            return $this->matchesAnyPrefix($packed, self::IPV4_RANGES);
        }

        if (substr($packed, 0, 12) === self::IPV4_MAPPED_PREFIX) {
            $ipv4 = inet_ntop(substr($packed, 12, 4));

            return is_string($ipv4) && $this->isBlockedAddress($ipv4);
        }

        // 6to4 carries an IPv4 address in bytes 2 to 5.
        if (substr($packed, 0, 2) === self::IPV6_6TO4_PREFIX) {
            $ipv4 = inet_ntop(substr($packed, 2, 4));

            return is_string($ipv4) && $this->isBlockedAddress($ipv4);
        }

        return $this->matchesAnyPrefix($packed, self::IPV6_RANGES);
    }

    private function isIp(string $host): bool
    {
        return $this->packedIp($host) !== null;
    }

    private function packedIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($host);

        return $packed === false ? null : $packed;
    }

    /**
     * IPv4-mapped IPv6 compares equal to the embedded IPv4 address.
     */
    private function comparableIp(string $host): ?string
    {
        $packed = $this->packedIp($host);
        if ($packed === null) {
            return null;
        }

        if (strlen($packed) === 16 && substr($packed, 0, 12) === self::IPV4_MAPPED_PREFIX) {
            return substr($packed, 12, 4);
        }

        return $packed;
    }

    /**
     * @param list<array{0: string, 1: int}> $networks
     */
    private function matchesAnyPrefix(string $packed, array $networks): bool
    {
        foreach ($networks as [$network, $bits]) {
            $networkPacked = inet_pton($network);
            if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
                continue;
            }
            if ($this->prefixEquals($packed, $networkPacked, $bits)) {
                return true;
            }
        }

        return false;
    }

    private function prefixEquals(string $address, string $network, int $bits): bool
    {
        $wholeBytes = intdiv($bits, 8);
        if (substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
