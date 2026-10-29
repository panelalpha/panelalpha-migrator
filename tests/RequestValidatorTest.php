<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Api\Middleware\RequestValidator;

final class RequestValidatorTest extends TestCase
{
    public function testGetParamReturnsValueFromAWpStyleRequest(): void
    {
        $request = new class {
            public function get_param($key)
            {
                return $key === 'migration_id' ? 'mig_1' : null;
            }
        };

        $this->assertSame('mig_1', RequestValidator::getParam($request, 'migration_id'));
    }

    public function testGetParamReturnsTheDefaultWhenTheWpStyleRequestHasNoValue(): void
    {
        $request = new class {
            public function get_param($key)
            {
                return null;
            }
        };

        $this->assertSame('fallback', RequestValidator::getParam($request, 'migration_id', 'fallback'));
    }

    public function testGetParamFallsBackToGetParamMethodForANonWpRequest(): void
    {
        $request = new class {
            public function getParam($key)
            {
                return $key === 'migration_id' ? 'mig_2' : null;
            }
        };

        $this->assertSame('mig_2', RequestValidator::getParam($request, 'migration_id'));
    }

    public function testGetMigrationIdReturnsTheProvidedId(): void
    {
        $request = new class {
            public function get_param($key)
            {
                return $key === 'migration_id' ? 'mig_3' : null;
            }
        };

        $this->assertSame('mig_3', RequestValidator::getMigrationId($request));
    }

    public function testGetDestinationUrlReturnsAValidUrl(): void
    {
        $request = new class {
            public function get_param($key)
            {
                return $key === 'destination_url' ? 'https://example.com/site' : null;
            }
        };

        $this->assertSame('https://example.com/site', RequestValidator::getDestinationUrl($request));
    }

    public function testGetJsonParamsReturnsTheDataUnchangedWhenItIsNotCompressed(): void
    {
        $request = new class {
            public function get_json_params(): array
            {
                return ['migration_id' => 'mig_1', 'foo' => 'bar'];
            }
        };

        $this->assertSame(['migration_id' => 'mig_1', 'foo' => 'bar'], RequestValidator::getJsonParams($request));
    }

    public function testGetJsonParamsDecompressesShortKeys(): void
    {
        $request = new class {
            public function get_json_params(): array
            {
                return ['m' => 'mig_1'];
            }
        };

        $this->assertSame(['migration_id' => 'mig_1'], RequestValidator::getJsonParams($request));
    }

    public function testGetJsonParamsReturnsAnEmptyArrayWhenThereIsNoJsonBody(): void
    {
        $request = new class {
            public function get_json_params()
            {
                return null;
            }
        };

        $this->assertSame([], RequestValidator::getJsonParams($request));
    }

    public function testGetJsonParamsFallsBackToGetJsonParamsMethodForANonWpRequest(): void
    {
        $request = new class {
            public function getJsonParams(): array
            {
                return ['migration_id' => 'mig_4'];
            }
        };

        $this->assertSame(['migration_id' => 'mig_4'], RequestValidator::getJsonParams($request));
    }
}
