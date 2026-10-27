<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\DestinationGuard;
use WordpressMigrator\Helpers\DestinationNotAllowedException;

final class DestinationGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedDestinationUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'this network' => ['http://0.1.2.3/'],
            'private 10' => ['http://10.1.2.3/'],
            'private 192.168' => ['http://192.168.0.5/'],
            'private 172.16' => ['http://172.16.5.5/'],
            'link local' => ['http://169.254.169.254/'],
            'shared address space' => ['http://100.64.1.1/'],
            'localhost' => ['http://localhost/'],
            'localhost any case' => ['http://LOCALHOST/wp-json'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 link local' => ['http://[fe80::1]/'],
            'ipv6 unique local' => ['http://[fc00::1]/'],
            'ipv6 unique local fd' => ['http://[fd12:3456::1]/'],
            'ipv6 unspecified' => ['http://[::]/'],
            'ipv4 mapped private' => ['http://[::ffff:10.1.2.3]/'],
            'nat64' => ['http://[64:ff9b::808:808]/'],
            'ipv4 unspecified' => ['http://0.0.0.0/'],
            'ipv4 mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'ietf protocol assignments' => ['http://192.0.0.8/'],
            'test net 1' => ['http://192.0.2.1/'],
            'benchmarking' => ['http://198.18.0.1/'],
            'test net 2' => ['http://198.51.100.1/'],
            'test net 3' => ['http://203.0.113.1/'],
            'ipv4 multicast' => ['http://224.0.0.1/'],
            'reserved' => ['http://240.0.0.1/'],
            'broadcast' => ['http://255.255.255.255/'],
            'ipv6 multicast' => ['http://[ff02::1]/'],
            'ipv6 site local' => ['http://[fec0::1]/'],
            'ipv6 documentation' => ['http://[2001:db8::1]/'],
            'teredo' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/'],
            '6to4 of a private address' => ['http://[2002:0a01:0203::1]/'],
            'nat64 local use' => ['http://[64:ff9b:1::1]/'],
            'ipv4 compatible' => ['http://[::10.1.2.3]/'],
            'ftp scheme' => ['ftp://8.8.8.8/'],
            'gopher scheme' => ['gopher://8.8.8.8:70/'],
            'file scheme' => ['file://localhost/etc/passwd'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidManualIps(): array
    {
        return [
            'list' => ['127.0.0.1,127.0.0.2'],
            'public then private' => ['8.8.8.8,127.0.0.1'],
            'decimal' => ['2130706433'],
            'hex' => ['0x7f000001'],
            'short' => ['127.1'],
            'hostname' => ['internal.corp'],
            'with port' => ['8.8.8.8:80'],
            'with space' => ['8.8.8.8 127.0.0.1'],
        ];
    }

    /**
     * @dataProvider invalidManualIps
     */
    #[DataProvider('invalidManualIps')]
    public function testManualIpThatIsNotOneValidIpIsRejected(string $ip): void
    {
        $guard = new DestinationGuard(static fn (string $host): array => ['8.8.8.8'], []);

        $this->expectException(DestinationNotAllowedException::class);
        $this->expectExceptionMessage('The destination address is not allowed.');

        $guard->assertAllowed('https://customer.example/', $ip);
    }

    public function testManualIpv6InBracketsIsTheApprovedIp(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), []);

        $this->assertSame('2001:4860:4860::8888', $guard->assertAllowed('https://customer.example/', '[2001:4860:4860::8888]'));
    }

    public function testSchemeIsComparedWithoutRegardToCase(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), []);

        $this->assertSame('8.8.8.8', $guard->assertAllowed('HTTPS://8.8.8.8/'));
    }

    public function testSixToFourOfAPublicAddressIsAllowed(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), []);

        $this->assertSame('2002:0808:0808::1', $guard->assertAllowed('http://[2002:0808:0808::1]/'));
    }

    public function testAllowlistPermitsAReservedRange(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), ['198.51.100.7']);

        $this->assertSame('198.51.100.7', $guard->assertAllowed('http://198.51.100.7/'));
    }

    /**
     * @dataProvider blockedDestinationUrls
     */
    #[DataProvider('blockedDestinationUrls')]
    public function testBlockedDestinationIsRejected(string $url): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun());

        $this->expectException(DestinationNotAllowedException::class);
        $this->expectExceptionMessage('The destination address is not allowed.');

        $guard->assertAllowed($url);
    }

    public function testPublicIpIsAllowed(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), []);

        $guard->assertAllowed('https://8.8.8.8/migrate');

        $this->addToAssertionCount(1);
    }

    public function testPublicHostPinnedToLoopbackIsRejected(): void
    {
        $guard = new DestinationGuard(static fn (string $host): array => ['8.8.8.8']);

        $this->expectException(DestinationNotAllowedException::class);
        $this->expectExceptionMessage('The destination address is not allowed.');

        $guard->assertAllowed('https://customer.example/migrate', '127.0.0.1');
    }

    public function testManualPublicIpPassesWhenTheHostHasNoDnsRecords(): void
    {
        $guard = new DestinationGuard(static fn (string $host): array => [], []);

        $guard->assertAllowed('https://new-site.example/migrate', '8.8.8.8');

        $this->addToAssertionCount(1);
    }

    public function testManualBlockedIpIsRejectedWhenTheHostHasNoDnsRecords(): void
    {
        $guard = new DestinationGuard(static fn (string $host): array => [], []);

        $this->expectException(DestinationNotAllowedException::class);

        $guard->assertAllowed('https://new-site.example/migrate', '10.1.2.3');
    }

    public function testHostnameThatResolvesToAPrivateAddressIsRejected(): void
    {
        $guard = new DestinationGuard(static fn (string $host): array => ['10.1.2.3', '8.8.8.8']);

        $this->expectException(DestinationNotAllowedException::class);
        $this->expectExceptionMessage('The destination address is not allowed.');

        $guard->assertAllowed('https://customer.example/migrate');
    }

    public function testHostnameThatResolvesToAPublicAddressIsAllowed(): void
    {
        $guard = new DestinationGuard(static fn (string $host): array => ['8.8.8.8'], []);

        $guard->assertAllowed('https://customer.example/migrate');

        $this->addToAssertionCount(1);
    }

    public function testAllowlistPermitsLoopback(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), ['127.0.0.1']);

        $guard->assertAllowed('http://127.0.0.1/restore.php');

        $this->addToAssertionCount(1);
    }

    public function testAllowlistPermitsAHostnameRegardlessOfCase(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), ['LocalHost']);

        $guard->assertAllowed('http://localhost/restore.php');

        $this->addToAssertionCount(1);
    }

    public function testEmptyAllowlistDoesNotPermitAPrivateTenAddress(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), []);

        $this->expectException(DestinationNotAllowedException::class);
        $this->expectExceptionMessage('The destination address is not allowed.');

        $guard->assertAllowed('http://10.1.2.3/');
    }

    public function testManualIpIsTheApprovedIp(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), []);

        $this->assertSame('8.8.4.4', $guard->assertAllowed('https://customer.example/', ' 8.8.4.4 '));
    }

    public function testFirstResolvedAddressIsTheApprovedIp(): void
    {
        $guard = new DestinationGuard(static fn (string $host): array => ['8.8.8.8', '8.8.4.4'], []);

        $this->assertSame('8.8.8.8', $guard->assertAllowed('https://customer.example/'));
    }

    public function testPublicIpHostIsItsOwnApprovedIp(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), []);

        $this->assertSame('2001:4860:4860::8888', $guard->assertAllowed('https://[2001:4860:4860::8888]/'));
    }

    public function testListedHostNameHasNoApprovedIp(): void
    {
        $guard = new DestinationGuard($this->resolverThatMustNotRun(), ['localhost']);

        $this->assertNull($guard->assertAllowed('http://localhost/'));
    }

    public function testOnePrivateAddressAmongResolvedOnesStillRejects(): void
    {
        $guard = new DestinationGuard(static fn (string $host): array => ['8.8.8.8', '127.0.0.1'], []);

        $this->expectException(DestinationNotAllowedException::class);

        $guard->assertAllowed('https://customer.example/');
    }

    /**
     * @return callable(string): list<string>
     */
    private function resolverThatMustNotRun(): callable
    {
        return static function (string $host): array {
            throw new \RuntimeException('Resolver was called for ' . $host);
        };
    }
}
