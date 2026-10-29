<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Helpers\CreateStatementNormalizer;

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

    public function testTempTableHeaderTakesTheTemporaryName(): void
    {
        $input = 'CREATE TABLE `wp_posts` (`ID` bigint, `wp_posts_note` text, KEY `wp_posts_idx` (`ID`)) COMMENT=\'wp_posts copy\' ENGINE=InnoDB;';

        $result = CreateStatementNormalizer::forTempTable($input, ['wp_posts' => 'pmt_ab12cd_wp_posts']);

        $this->assertSame(
            'CREATE TABLE `pmt_ab12cd_wp_posts` (`ID` bigint, `wp_posts_note` text, KEY `wp_posts_idx` (`ID`)) COMMENT=\'wp_posts copy\' ENGINE=InnoDB;',
            $result
        );
    }

    public function testTempTableKeepsTheIfNotExistsClause(): void
    {
        $result = CreateStatementNormalizer::forTempTable('CREATE TABLE IF NOT EXISTS `wp_a` (`id` int)', ['wp_a' => 'pmt_ab12cd_wp_a']);

        $this->assertSame('CREATE TABLE IF NOT EXISTS `pmt_ab12cd_wp_a` (`id` int)', $result);
    }

    public function testForeignKeyTargetsPointAtTemporaryTablesAndLoseTheirConstraintNames(): void
    {
        $input = 'CREATE TABLE `shop_items` (`id` int, `order_id` int, `owner_id` int, '
            . 'CONSTRAINT `fk_order` FOREIGN KEY (`order_id`) REFERENCES `shop_orders` (`id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_owner` FOREIGN KEY (`owner_id`) REFERENCES `other_app_users` (`id`)) ENGINE=InnoDB';

        $result = CreateStatementNormalizer::forTempTable($input, [
            'shop_items' => 'pmt_ab12cd_shop_items',
            'shop_orders' => 'pmt_ab12cd_shop_orders',
        ]);

        $this->assertSame(
            'CREATE TABLE `pmt_ab12cd_shop_items` (`id` int, `order_id` int, `owner_id` int, '
            . 'FOREIGN KEY (`order_id`) REFERENCES `pmt_ab12cd_shop_orders` (`id`) ON DELETE CASCADE, '
            . 'FOREIGN KEY (`owner_id`) REFERENCES `other_app_users` (`id`)) ENGINE=InnoDB',
            $result
        );
    }

    public function testNamesOfUniqueKeysAndCheckConstraintsAreHandledSeparately(): void
    {
        $input = 'CREATE TABLE `t` (`id` int, CONSTRAINT `uniq_id` UNIQUE KEY (`id`), CONSTRAINT `positive` CHECK (`id` > 0))';

        $result = CreateStatementNormalizer::forTempTable($input, ['t' => 'pmt_ab12cd_t']);

        $this->assertSame('CREATE TABLE `pmt_ab12cd_t` (`id` int, CONSTRAINT `uniq_id` UNIQUE KEY (`id`), CHECK (`id` > 0))', $result);
    }

    public function testBackticksInNamesAreEscapedAndUnescaped(): void
    {
        $result = CreateStatementNormalizer::forTempTable('CREATE TABLE `we``ird` (`id` int)', ['we`ird' => 'pmt_ab12cd_we`ird']);

        $this->assertSame('CREATE TABLE `pmt_ab12cd_we``ird` (`id` int)', $result);
    }

    public function testReplacementTextWithDollarAndBackslashIsLiteral(): void
    {
        $result = CreateStatementNormalizer::forTempTable('CREATE TABLE `t` (`id` int)', ['t' => 'pmt_$1\\0_t']);

        $this->assertSame('CREATE TABLE `pmt_$1\\0_t` (`id` int)', $result);
    }

    public function testTempTableRefusesATableWithoutATemporaryName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CreateStatementNormalizer::forTempTable('CREATE TABLE `wp_posts` (`id` int)', ['wp_users' => 'pmt_ab12cd_wp_users']);
    }

    public function testTempTableRefusesAStatementThatIsNotACreateTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CreateStatementNormalizer::forTempTable('CREATE VIEW `v` AS SELECT 1', ['v' => 'pmt_ab12cd_v']);
    }
}
