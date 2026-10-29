<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\DestinationEndpoint;

final class DestinationEndpointTest extends TestCase
{
    public function testPrettyModeUsesTheWpJsonPath(): void
    {
        $endpoint = new DestinationEndpoint('https://example.test');

        $this->assertSame('pretty', $endpoint->mode());
        $this->assertSame('https://example.test/wp-json/panelalpha-migrator/v1/receive', $endpoint->url('receive'));
        $this->assertSame('https://example.test/wp-json/panelalpha-migrator/v1/receive/finalize', $endpoint->url('receive/finalize'));
    }

    public function testPlainModeUsesTheRestRouteQuery(): void
    {
        $endpoint = new DestinationEndpoint('https://example.test', DestinationEndpoint::MODE_PLAIN);

        $this->assertSame('plain', $endpoint->mode());
        $this->assertSame('https://example.test/?rest_route=/panelalpha-migrator/v1/receive/check', $endpoint->url('receive/check'));
    }

    public function testTrailingSlashesAndSubdirectoriesKeepOneSlash(): void
    {
        $this->assertSame(
            'https://example.test/blog/wp-json/panelalpha-migrator/v1/receive',
            (new DestinationEndpoint('https://example.test/blog/'))->url('receive')
        );
        $this->assertSame(
            'https://example.test/blog/?rest_route=/panelalpha-migrator/v1/receive',
            (new DestinationEndpoint('https://example.test/blog//', DestinationEndpoint::MODE_PLAIN))->url('receive')
        );
    }

    public function testQueryStringAndFragmentOfTheDestinationUrlAreDropped(): void
    {
        $this->assertSame(
            'https://example.test:8443/wp-json/panelalpha-migrator/v1/receive',
            (new DestinationEndpoint('https://example.test:8443/?token=abc&x=1#top'))->url('receive')
        );
        $this->assertSame(
            'https://example.test/?rest_route=/panelalpha-migrator/v1/receive',
            (new DestinationEndpoint('https://example.test/?token=abc', DestinationEndpoint::MODE_PLAIN))->url('receive')
        );
    }

    public function testQueryArgumentsStartWithAQuestionMarkInPrettyModeAndAnAmpersandInPlainMode(): void
    {
        $this->assertSame(
            'https://example.test/wp-json/panelalpha-migrator/v1/receive/check?_=123',
            (new DestinationEndpoint('https://example.test'))->url('receive/check', ['_' => '123'])
        );
        $this->assertSame(
            'https://example.test/?rest_route=/panelalpha-migrator/v1/receive/check&_=123',
            (new DestinationEndpoint('https://example.test', DestinationEndpoint::MODE_PLAIN))->url('receive/check', ['_' => '123'])
        );
    }

    public function testAnUnknownModeCountsAsPretty(): void
    {
        $endpoint = new DestinationEndpoint('https://example.test', 'something');

        $this->assertSame('pretty', $endpoint->mode());
    }

    public static function savedModes(): array
    {
        return [
            'plain' => [['drm' => 'plain'], 'plain'],
            'pretty' => [['drm' => 'pretty'], 'pretty'],
            'missing' => [[], 'pretty'],
            'garbage' => [['drm' => 'rest'], 'pretty'],
            'not a string' => [['drm' => true], 'pretty'],
        ];
    }

    /**
     * @dataProvider savedModes
     */
    #[DataProvider('savedModes')]
    public function testModeIsReadFromTheMigrationData(array $migrationData, string $expected): void
    {
        $this->assertSame($expected, DestinationEndpoint::modeFrom($migrationData));
    }
}
