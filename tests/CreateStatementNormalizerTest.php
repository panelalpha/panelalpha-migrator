<?php

declare(strict_types=1);

namespace WordpressMigrator\Tests;

use PHPUnit\Framework\TestCase;
use WordpressMigrator\Helpers\CreateStatementNormalizer;

final class CreateStatementNormalizerTest extends TestCase
{
    public function testTablespaceClauseIsRemoved(): void
    {
        $input = 'CREATE TABLE `test` (`id` int) TABLESPACE `innodb_system` ENGINE=InnoDB;';

        $result = CreateStatementNormalizer::normalize($input);

        $this->assertSame('CREATE TABLE `test` (`id` int) ENGINE=InnoDB;', $result);
    }

    public function testLegacyTableTypeClauseIsRemoved(): void
    {
        $input = 'CREATE TABLE `test` (`id` int) TYPE=MyISAM ENGINE=MyISAM;';

        $result = CreateStatementNormalizer::normalize($input);

        $this->assertSame('CREATE TABLE `test` (`id` int) ENGINE=InnoDB;', $result);
    }

    public function testPackKeysClauseIsRemoved(): void
    {
        $input = 'CREATE TABLE `test` (`id` int) PACK_KEYS=1 ENGINE=InnoDB;';

        $result = CreateStatementNormalizer::normalize($input);

        $this->assertSame('CREATE TABLE `test` (`id` int) ENGINE=InnoDB;', $result);
    }

    public function testRowFormatClauseIsRemoved(): void
    {
        $input = 'CREATE TABLE `test` (`id` int) ROW_FORMAT=COMPRESSED ENGINE=InnoDB;';

        $result = CreateStatementNormalizer::normalize($input);

        $this->assertSame('CREATE TABLE `test` (`id` int) ENGINE=InnoDB;', $result);
    }

    public function testStorageEngineBecomesInnoDB(): void
    {
        $myisamResult = CreateStatementNormalizer::normalize(
            'CREATE TABLE `test` (`id` int) ENGINE=MyISAM;'
        );
        $noEngineResult = CreateStatementNormalizer::normalize(
            'CREATE TABLE `test` (`id` int);'
        );

        $this->assertSame('CREATE TABLE `test` (`id` int) ENGINE=InnoDB;', $myisamResult);
        $this->assertSame('CREATE TABLE `test` (`id` int) ENGINE=InnoDB;', $noEngineResult);
    }
}
