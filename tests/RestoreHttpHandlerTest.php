<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordpressMigrator\Restore\RestoreHttpHandler;
use WordpressMigrator\Tests\Support\TemporaryDirectory;

/**
 * Only the paths that do not end the request with exit.
 */
final class RestoreHttpHandlerTest extends TestCase
{
    use TemporaryDirectory;

    private array $server;

    private array $get;

    private array $post;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
    }

    public function testMatchingHeaderTokenIsAccepted(): void
    {
        $_SERVER['HTTP_X_MIGRATOR_TOKEN'] = 'secret';

        (new RestoreHttpHandler('secret'))->authenticate();

        $this->addToAssertionCount(1);
    }

    public function testMatchingQueryTokenIsAccepted(): void
    {
        unset($_SERVER['HTTP_X_MIGRATOR_TOKEN']);
        $_GET['token'] = 'secret';

        (new RestoreHttpHandler('secret'))->authenticate();

        $this->addToAssertionCount(1);
    }

    public function testActionComesFromTheQueryThenThePost(): void
    {
        $handler = new RestoreHttpHandler('secret');
        $_GET = [];
        $_POST = [];
        $this->assertSame('', $handler->getAction());

        $_POST['action'] = 'status';
        $this->assertSame('status', $handler->getAction());

        $_GET['action'] = 'health';
        $this->assertSame('health', $handler->getAction());
    }

    public function testMigrationIdComesFromTheQuery(): void
    {
        $handler = new RestoreHttpHandler('secret');
        $_GET = [];
        $this->assertSame('', $handler->getMigrationId());

        $_GET['migration_id'] = 'mig_1';
        $this->assertSame('mig_1', $handler->getMigrationId());
    }

    /**
     * @runInSeparateProcess
     */
    #[RunInSeparateProcess]
    public function testTokenDefaultsToTheOneInConfigPhp(): void
    {
        $pluginDir = $this->createTemporaryDirectory();
        file_put_contents($pluginDir . 'config.php', "<?php return ['token' => 'config-token'];");
        define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);
        $_SERVER['HTTP_X_MIGRATOR_TOKEN'] = 'config-token';

        (new RestoreHttpHandler())->authenticate();

        $this->addToAssertionCount(1);
    }
}
