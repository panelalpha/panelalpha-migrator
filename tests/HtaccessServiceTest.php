<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use Exception;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\Config;
use WordpressMigrator\Helpers\JsonCompressor;
use WordpressMigrator\Services\Migration\HtaccessService;
use WordpressMigrator\Tests\Support\CurlOptionCapture;
use WordpressMigrator\Tests\Support\FakeDestination;
use WordpressMigrator\Tests\Support\ResetsGlobalState;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

final class HtaccessServiceTest extends TestCase
{
    use ResetsGlobalState;
    use TemporaryDirectory;

    private string $siteDir;
    private string $migrationDir;
    private FakeDestination $destination;

    protected function setUp(): void
    {
        $this->siteDir = $this->createTemporaryDirectory();
        $this->migrationDir = $this->createTemporaryDirectory();
        Config::set('root_path', $this->siteDir);
        Config::set('migration_dir', $this->migrationDir);
        Config::set('allowed_destinations', ['127.0.0.1']);
        $this->destination = FakeDestination::get();
    }

    public function testTransferSendsExactFileContentMigrationIdAndToken(): void
    {
        $content = "RewriteEngine On\n# unicode: zażółć\n";
        self::writeFile($this->siteDir . '.htaccess', $content);
        $this->saveMigration(['destinationUrl' => $this->destination->url(), 'token' => 'secret']);
        CurlOptionCapture::reset();

        $this->assertSame(['transferred' => true], (new HtaccessService())->transfer('mig_1'));

        $requests = $this->destination->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('/wp-json/wp-migrator/v1/receive-htaccess', $requests[0]['uri']);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('application/json', $requests[0]['headers']['content-type']);
        $this->assertSame('secret', $requests[0]['headers']['x-migrator-token']);
        $this->assertSame(['content' => $content, 'migration_id' => 'mig_1'], json_decode($requests[0]['body'], true));
        $this->assertTrue(CurlOptionCapture::peerVerificationEnabled());
        $this->assertTrue(CurlOptionCapture::hostVerificationEnabled());
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
    public function testTransferSkipsCertificateChecksOnlyForAManualIpWithInsecureTls(array $tlsData, bool $strict): void
    {
        self::writeFile($this->siteDir . '.htaccess', 'RewriteEngine On');
        $this->saveMigration(array_merge(['destinationUrl' => $this->destination->url(), 'token' => 'secret'], $tlsData));
        CurlOptionCapture::reset();

        $this->assertSame(['transferred' => true], (new HtaccessService())->transfer('mig_1'));

        $this->assertSame($strict, CurlOptionCapture::peerVerificationEnabled());
        $this->assertSame($strict, CurlOptionCapture::hostVerificationEnabled());
    }

    public function testTransferConnectsToTheStoredPinnedIp(): void
    {
        Config::set('allowed_destinations', []);
        self::writeFile($this->siteDir . '.htaccess', 'RewriteEngine On');
        $this->saveMigration([
            'destinationUrl' => 'http://destination.invalid:' . $this->destination->port(),
            'pinned_ip' => '127.0.0.1',
            'token' => 'secret',
        ]);
        CurlOptionCapture::reset();

        $this->assertSame(['transferred' => true], (new HtaccessService())->transfer('mig_1'));

        $this->assertSame(
            ['destination.invalid:' . $this->destination->port() . ':127.0.0.1'],
            CurlOptionCapture::get(CURLOPT_RESOLVE)
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTransferNeverSendsTheSiteTokenToTheDestination(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'config.php', "<?php\nreturn ['token' => 'config-token'];\n");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);
        self::writeFile($this->siteDir . '.htaccess', 'RewriteEngine On');
        $this->saveMigration(['destinationUrl' => $this->destination->url()], false);

        try {
            (new HtaccessService())->transfer('mig_1');
            $this->fail('A migration without a destination token must not be sent');
        } catch (\RuntimeException $e) {
            $this->assertSame('Destination token not found in migration data', $e->getMessage());
        }

        $this->assertSame([], $this->destination->requests());
    }

    public function testMissingHtaccessReturnsWithoutSendingRequest(): void
    {
        $this->saveMigration(['destinationUrl' => $this->destination->url()]);

        $this->assertSame(
            ['transferred' => false, 'message' => 'No .htaccess file found on source'],
            (new HtaccessService())->transfer('mig_1')
        );
        $this->assertSame([], $this->destination->requests());
    }

    public function testMissingDestinationUrlThrowsBeforeSendingRequest(): void
    {
        self::writeFile($this->siteDir . '.htaccess', 'rule');
        $this->saveMigration(['token' => 'secret']);

        try {
            (new HtaccessService())->transfer('mig_1');
            $this->fail('Expected a missing destination error');
        } catch (Exception $exception) {
            $this->assertSame('Destination URL not found in migration data', $exception->getMessage());
        }

        $this->assertSame([], $this->destination->requests());
    }

    public function test404RetriesThroughNonPrettyRestRouteWithSameBody(): void
    {
        self::writeFile($this->siteDir . '.htaccess', 'RewriteEngine On');
        $this->saveMigration(['destination_url' => $this->destination->url(), 'token' => 'secret']);
        $this->destination->respondWith('/wp-json/', 404);

        $this->assertSame(['transferred' => true], (new HtaccessService())->transfer('mig_1'));

        $requests = $this->destination->requests();
        $this->assertCount(2, $requests);
        $this->assertSame('/?rest_route=/wp-migrator/v1/receive-htaccess', $requests[1]['uri']);
        $this->assertSame($requests[0]['body'], $requests[1]['body']);
        $this->assertSame($requests[0]['headers']['x-migrator-token'], $requests[1]['headers']['x-migrator-token']);
    }

    public function testManualIpThatIsNotOneValidIpIsRefusedBeforeAnyRequest(): void
    {
        self::writeFile($this->siteDir . '.htaccess', 'rule');
        $this->saveMigration(['destinationUrl' => $this->destination->url(), 'ip' => '127.0.0.1,127.0.0.2']);

        try {
            (new HtaccessService())->transfer('mig_1');
            $this->fail('Expected the destination to be refused');
        } catch (\WordpressMigrator\Helpers\DestinationNotAllowedException $exception) {
            $this->assertSame([], $this->destination->requests());
        }
    }

    public function testLongErrorBodyIsCutInTheMessage(): void
    {
        self::writeFile($this->siteDir . '.htaccess', 'rule');
        $this->saveMigration(['destinationUrl' => $this->destination->url()]);
        $this->destination->respondWith('/receive-htaccess', 500, str_repeat('x', 1000));

        try {
            (new HtaccessService())->transfer('mig_1');
            $this->fail('Expected destination error');
        } catch (Exception $exception) {
            $this->assertSame('Destination returned error 500: ' . str_repeat('x', 300), $exception->getMessage());
        }
    }

    public function testServerErrorContainsStatusAndBodyWithoutRetry(): void
    {
        self::writeFile($this->siteDir . '.htaccess', 'rule');
        $this->saveMigration(['destinationUrl' => $this->destination->url()]);
        $this->destination->respondWith('/receive-htaccess', 500, 'broken');

        try {
            (new HtaccessService())->transfer('mig_1');
            $this->fail('Expected destination error');
        } catch (Exception $exception) {
            $this->assertSame('Destination returned error 500: broken', $exception->getMessage());
        }

        $this->assertCount(1, $this->destination->requests());
    }

    public function testConnectionFailureIsReported(): void
    {
        self::writeFile($this->siteDir . '.htaccess', 'rule');
        $this->saveMigration(['destinationUrl' => FakeDestination::closedPortUrl()]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to transfer .htaccess:');
        (new HtaccessService())->transfer('mig_1');
    }

    public function testReceiveCreatesAndOverwritesHtaccessWithExactContent(): void
    {
        $service = new HtaccessService();
        $path = $this->siteDir . '.htaccess';

        $this->assertSame(['bytes_written' => 5], $service->receive("one\n2"));
        $this->assertSame("one\n2", file_get_contents($path));
        $this->assertSame(['bytes_written' => 0], $service->receive(''));
        $this->assertSame('', file_get_contents($path));
    }

    private function saveMigration(array $data, bool $withToken = true): void
    {
        if ($withToken) {
            $data += ['token' => 'destination-token'];
        }

        self::writeFile($this->migrationDir . 'mig_1.json', JsonCompressor::compressToJson($data));
    }
}
