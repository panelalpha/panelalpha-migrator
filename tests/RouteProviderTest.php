<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use stdClass;
    use WordpressMigrator\Api\RouteProvider;
    use WordpressMigrator\Helpers\TokenHelper;
    use WordpressMigrator\Tests\Support\TemporaryDirectory;

    /**
     * @runTestsInSeparateProcesses
     */
    #[RunTestsInSeparateProcesses]
    final class RouteProviderTest extends TestCase
    {
        use TemporaryDirectory;

        private array $routes;

        protected function setUp(): void
        {
            require __DIR__ . '/Support/RouteProviderWordPressFunctions.php';
            $pluginDir = $this->createTemporaryDirectory();
            file_put_contents($pluginDir . 'config.php', "<?php return ['token' => 'route-test-token'];");
            define('WP_MIGRATOR_PLUGIN_DIR', $pluginDir);
            $GLOBALS['route_provider_registered_routes'] = [];
            $GLOBALS['route_provider_admin'] = false;
            $reflection = new ReflectionClass(RouteProvider::class);
            $provider = $reflection->newInstanceWithoutConstructor();
            foreach (['migrationController', 'backupController', 'restoreController', 'tokenController', 'remoteSyncOrchestrationController'] as $property) {
                $reflectionProperty = $reflection->getProperty($property);
                if (PHP_VERSION_ID < 80100) {
                    $reflectionProperty->setAccessible(true);
                }
                $reflectionProperty->setValue($provider, new stdClass());
            }
            $provider->registerWordPressRoutes();
            $this->routes = $GLOBALS['route_provider_registered_routes'];
        }

        public function testRegistersExpectedRoutesAndMethods(): void
        {
            self::assertCount(25, $this->routes);
            self::assertSame('POST', $this->route('/scan')['methods']);
            self::assertSame('GET', $this->route('/info')['methods']);
            self::assertSame('POST', $this->route('/collect-chunk')['methods']);
            self::assertSame('GET', $this->route('/remote-sync/status/(?P<migration_id>[a-zA-Z0-9_]+)')['methods']);
        }

        public function testAdminOnlyRoutesRejectGuests(): void
        {
            foreach (['/scan', '/token/current', '/token/generate-and-save'] as $route) {
                self::assertFalse(($this->route($route)['permission_callback'])(), $route);
            }
            $GLOBALS['route_provider_admin'] = true;
            foreach (['/scan', '/token/current', '/token/generate-and-save'] as $route) {
                self::assertTrue(($this->route($route)['permission_callback'])(), $route);
            }
        }

        public function testProtectedRoutesAcceptAdminOrValidToken(): void
        {
            require __DIR__ . '/Support/WordPressStubs.php';
            $valid = new \WP_REST_Request([], ['X-Migrator-Token' => TokenHelper::getToken()]);
            $invalid = new \WP_REST_Request([], ['X-Migrator-Token' => 'wrong']);
            $protected = [
                '/info', '/backup/database/next-chunk', '/backup/database/next-chunks-batch',
                '/backup/database/progress', '/backup/files/next-chunk', '/backup/files/next-chunks-batch',
                '/backup/files/progress', '/backup/status', '/backup/start', '/backup/stop',
                '/restore/files/next-chunk', '/restore/database/next-chunk', '/restore/progress',
                '/finalize', '/list-migrations', '/receive-htaccess',
            ];

            foreach ($protected as $route) {
                $permission = $this->route($route)['permission_callback'];
                self::assertFalse($permission($invalid), $route);
                self::assertTrue($permission($valid), $route);
            }
            $GLOBALS['route_provider_admin'] = true;
            foreach ($protected as $route) {
                self::assertTrue(($this->route($route)['permission_callback'])($invalid), $route);
            }
        }

        public function testCollectChunkAndOtherRoutesAcceptAdminOrValidToken(): void
        {
            require __DIR__ . '/Support/WordPressStubs.php';
            $valid = new \WP_REST_Request([], ['X-Migrator-Token' => TokenHelper::getToken()]);
            $query = new \WP_REST_Request(['token' => TokenHelper::getToken()]);
            $invalid = new \WP_REST_Request([], ['X-Migrator-Token' => 'wrong']);
            $anonymous = new \WP_REST_Request();
            $routes = [
                '/collect-chunk', '/migration-complete', '/remote-sync',
                '/remote-sync/status/(?P<migration_id>[a-zA-Z0-9_]+)',
                '/remote-sync/process-batch', '/transfer-htaccess',
            ];

            foreach ($routes as $route) {
                $permission = $this->route($route)['permission_callback'];
                self::assertNotSame('__return_true', $permission, $route);
                self::assertFalse($permission($anonymous), $route);
                self::assertFalse($permission($invalid), $route);
                self::assertTrue($permission($valid), $route);
                self::assertTrue($permission($query), $route);
            }

            $GLOBALS['route_provider_admin'] = true;
            foreach ($routes as $route) {
                self::assertTrue(($this->route($route)['permission_callback'])($invalid), $route);
            }
        }

        private function route(string $path): array
        {
            $key = 'wp-migrator/v1' . $path;
            self::assertArrayHasKey($key, $this->routes);
            return $this->routes[$key];
        }
    }
