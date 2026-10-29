<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Tests;

use PHPUnit\Framework\TestCase;
use PanelAlpha\Migrator\Restore\StagedFiles;
use PanelAlpha\Migrator\Tests\Support\TemporaryDirectory;

final class StagedFilesTest extends TestCase
{
    use TemporaryDirectory;

    private string $abspath;

    private string $directory;

    protected function setUp(): void
    {
        $this->abspath = $this->createTemporaryDirectory();
        $this->directory = $this->createTemporaryDirectory();
    }

    public static function stagedPaths(): array
    {
        return [
            'core folders' => ['wp-admin/index.php', true],
            'core includes, any type' => ['wp-includes/js/a.js', true],
            'plugin file, any type' => ['wp-content/plugins/seo/img/a.png', true],
            'theme file, any type' => ['wp-content/themes/t/style.css', true],
            'single plugin file' => ['wp-content/plugins/hello.php', true],
            'mu-plugins' => ['wp-content/mu-plugins/host/a.txt', true],
            'file in the WordPress directory' => ['robots.txt', true],
            'file in wp-content' => ['wp-content/advanced-cache.php', true],
            'php in uploads' => ['wp-content/uploads/2024/index.php', true],
            'php in uppercase' => ['wp-content/uploads/a.PHP', true],
            'phtml' => ['wp-content/cache/a.phtml', true],
            'phar' => ['wp-content/cache/a.phar', true],
            'inc' => ['wp-content/cache/a.inc', true],
            'user ini' => ['wp-content/uploads/.user.ini', true],
            'nested htaccess' => ['wp-content/uploads/.htaccess', true],
            'image in uploads' => ['wp-content/uploads/2024/a.jpg', false],
            'translation file' => ['wp-content/languages/pl_PL.mo', false],
            'text file deeper' => ['wp-content/cache/a.txt', false],
        ];
    }

    /**
     * @dataProvider stagedPaths
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('stagedPaths')]
    public function testWhichPathsAreStaged(string $path, bool $expected): void
    {
        $this->assertSame($expected, $this->staged()->isStaged($path));
    }

    public function testDropInsAreRecognisedOnlyDirectlyInWpContent(): void
    {
        $staged = $this->staged();

        $this->assertTrue($staged->isDestinationDropIn('wp-content/object-cache.php'));
        $this->assertTrue($staged->isDestinationDropIn('wp-content/Advanced-Cache.php'));
        $this->assertTrue($staged->isDestinationDropIn('wp-content/db.php'));
        $this->assertFalse($staged->isDestinationDropIn('wp-content/plugins/a/db.php'));
        $this->assertFalse($staged->isDestinationDropIn('db.php'));
    }

    public function testStagingFolderIsCreatedAndProtected(): void
    {
        $this->assertTrue($this->staged()->ensureDirectories());

        $this->assertDirectoryExists($this->directory . 'staged');
        $this->assertFileExists($this->directory . 'index.php');
        $this->assertStringContainsString('Require all denied', file_get_contents($this->directory . '.htaccess'));
    }

    public function testPluginFolderIsSwappedWhole(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->live('wp-content/plugins/seo/removed-in-source.php', 'gone');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $this->stage('wp-content/plugins/seo/added.php', 'added');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(['moved' => 1, 'warnings' => []], $result);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
        $this->assertSame('added', file_get_contents($this->abspath . 'wp-content/plugins/seo/added.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/seo/removed-in-source.php');
        $this->assertSame('gone', file_get_contents($this->directory . 'replaced/wp-content/plugins/seo/removed-in-source.php'));
        $this->assertDirectoryDoesNotExist($this->directory . 'staged/wp-content/plugins/seo');
    }

    public function testNewPluginWithoutALiveFolderIsMovedIn(): void
    {
        $this->stage('wp-content/themes/fresh/style.css', 'css');

        $this->staged()->moveIntoPlace();

        $this->assertSame('css', file_get_contents($this->abspath . 'wp-content/themes/fresh/style.css'));
        $this->assertDirectoryDoesNotExist($this->directory . 'replaced');
    }

    public function testCoreFoldersAreSwappedWhenTheStagedCoreIsWhole(): void
    {
        foreach (['wp-includes/version.php', 'wp-includes/old.php', 'wp-includes/gone/a.js', 'wp-admin/old.php', 'wp-content/plugins/own/own.php', 'old-root.php'] as $path) {
            $this->live($path, 'live');
        }
        $this->stage('wp-includes/version.php', 'new');
        $this->stage('wp-admin/index.php', 'new');

        $this->staged()->moveIntoPlace();

        $this->assertSame('new', file_get_contents($this->abspath . 'wp-includes/version.php'));
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-admin/index.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-includes/old.php');
        $this->assertDirectoryDoesNotExist($this->abspath . 'wp-includes/gone');
        $this->assertFileDoesNotExist($this->abspath . 'wp-admin/old.php');
        $this->assertFileExists($this->abspath . 'wp-content/plugins/own/own.php');
        $this->assertFileExists($this->abspath . 'old-root.php');
    }

    public function testPartialCoreIsMovedFileByFileAndKeepsTheRest(): void
    {
        $this->live('wp-includes/version.php', 'live');
        $this->live('wp-includes/keep.php', 'keep');
        $this->stage('wp-includes/formatting.php', 'new');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(1, $result['moved']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-includes/formatting.php'));
        $this->assertSame('keep', file_get_contents($this->abspath . 'wp-includes/keep.php'));
        $this->assertSame('live', file_get_contents($this->abspath . 'wp-includes/version.php'));
    }

    public function testMuPluginsAndOtherFilesMoveOneByOneAndKeepTheHostsFiles(): void
    {
        $this->live('wp-content/mu-plugins/host.php', 'host');
        $this->live('wp-content/index.php', 'old index');
        $this->stage('wp-content/mu-plugins/source.php', 'source');
        $this->stage('wp-content/index.php', 'new index');
        $this->stage('wp-content/uploads/2024/index.php', 'silence');
        $this->stage('wp-content/plugins/hello.php', 'hello');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(4, $result['moved']);
        $this->assertSame('host', file_get_contents($this->abspath . 'wp-content/mu-plugins/host.php'));
        $this->assertSame('source', file_get_contents($this->abspath . 'wp-content/mu-plugins/source.php'));
        $this->assertSame('new index', file_get_contents($this->abspath . 'wp-content/index.php'));
        $this->assertSame('silence', file_get_contents($this->abspath . 'wp-content/uploads/2024/index.php'));
        $this->assertSame('hello', file_get_contents($this->abspath . 'wp-content/plugins/hello.php'));
        $this->assertSame('old index', file_get_contents($this->directory . 'replaced/wp-content/index.php'));
    }

    public function testMovedFileKeepsItsPermissionsAndDate(): void
    {
        $this->stage('wp-content/plugins/a/a.php', '<?php');
        chmod($this->directory . 'staged/wp-content/plugins/a/a.php' . StagedFiles::SUFFIX, 0640);
        touch($this->directory . 'staged/wp-content/plugins/a/a.php' . StagedFiles::SUFFIX, 1700000000);

        $this->staged()->moveIntoPlace();

        clearstatcache();
        $path = $this->abspath . 'wp-content/plugins/a/a.php';
        $this->assertSame('640', substr(sprintf('%o', fileperms($path)), -3));
        $this->assertSame(1700000000, filemtime($path));
    }

    public function testDropInsFromTheSourceAreNeverMoved(): void
    {
        $this->live('wp-content/object-cache.php', 'destination');
        $this->stage('wp-content/object-cache.php', 'source');
        $this->stage('wp-content/db.php', 'source');
        $this->stage('wp-content/advanced-cache.php', 'source');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(0, $result['moved']);
        $this->assertSame('destination', file_get_contents($this->abspath . 'wp-content/object-cache.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/db.php');
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/advanced-cache.php');
    }

    public function testProtectedFoldersAreNeverTouched(): void
    {
        $this->live('wp-content/plugins/panelalpha-migrator/own.php', 'own');
        $this->live('wp-content/plugins/wordpress-migrator/old.php', 'old restore');
        $this->stage('wp-content/plugins/panelalpha-migrator/own.php', 'from source');
        $this->stage('wp-content/plugins/wordpress-migrator/migrator-old.php', 'from source');
        $this->stage('wp-content/plugins/other/a.php', 'other');

        $result = $this->staged([
            'wp-content/plugins/panelalpha-migrator/',
            'wp-content/plugins/wordpress-migrator/',
        ])->moveIntoPlace();

        $this->assertSame(1, $result['moved']);
        $this->assertSame('own', file_get_contents($this->abspath . 'wp-content/plugins/panelalpha-migrator/own.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/wordpress-migrator/migrator-old.php');
        $this->assertSame('old restore', file_get_contents($this->abspath . 'wp-content/plugins/wordpress-migrator/old.php'));
        $this->assertSame('other', file_get_contents($this->abspath . 'wp-content/plugins/other/a.php'));
    }

    public function testBackupIsRemovedAtFinishByDefault(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $staged = $this->staged();
        $staged->moveIntoPlace();
        $this->assertDirectoryExists($this->directory . 'replaced');

        $staged->finish();

        $this->assertDirectoryDoesNotExist($this->directory . 'replaced');
        $this->assertDirectoryDoesNotExist($this->directory . 'staged');
        $this->assertFileDoesNotExist($this->directory . 'journal.jsonl');
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
    }

    public function testBackupStaysAtFinishWhenRequested(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $staged = $this->staged([], true);
        $staged->moveIntoPlace();

        $staged->finish();

        $this->assertSame('old', file_get_contents($this->directory . 'replaced/wp-content/plugins/seo/seo.php'));
        $this->assertDirectoryDoesNotExist($this->directory . 'staged');
    }

    public function testFinishBeforeTheMoveIsCompleteIsRefused(): void
    {
        $this->stage('a.php', 'x');
        file_put_contents($this->directory . 'journal.jsonl', "{\"state\":\"moving\"}\n");

        $this->expectException(\RuntimeException::class);

        $this->staged()->finish();
    }

    public function testMovingTwiceDoesNothingTheSecondTime(): void
    {
        $this->stage('a.php', 'x');
        $staged = $this->staged();
        $staged->moveIntoPlace();

        $this->assertSame(['moved' => 0, 'warnings' => []], $staged->moveIntoPlace());
        $this->assertSame('x', file_get_contents($this->abspath . 'a.php'));
    }

    public function testNothingStagedIsNotAnError(): void
    {
        $this->assertSame(['moved' => 0, 'warnings' => []], $this->staged()->moveIntoPlace());
    }

    public function testRollbackAfterASuccessfulMovePutsTheOldFilesBack(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->live('index.php', 'old index');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $this->stage('wp-content/plugins/fresh/a.php', 'fresh');
        $this->stage('index.php', 'new index');
        $staged = $this->staged();
        $staged->moveIntoPlace();

        $problems = $staged->rollback();

        $this->assertSame([], $problems);
        $this->assertSame('old', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
        $this->assertSame('old index', file_get_contents($this->abspath . 'index.php'));
        $this->assertDirectoryDoesNotExist($this->abspath . 'wp-content/plugins/fresh');
        $this->assertSame('new', file_get_contents($this->directory . 'staged/wp-content/plugins/seo/seo.php' . StagedFiles::SUFFIX));
        $this->assertSame('fresh', file_get_contents($this->directory . 'staged/wp-content/plugins/fresh/a.php' . StagedFiles::SUFFIX));
        $this->assertDirectoryDoesNotExist($this->directory . 'replaced/wp-content/plugins/seo');
    }

    public function testRollbackTwiceChangesNothingMore(): void
    {
        $this->live('index.php', 'old index');
        $this->stage('index.php', 'new index');
        $staged = $this->staged();
        $staged->moveIntoPlace();
        $staged->rollback();

        $this->assertSame([], $staged->rollback());
        $this->assertSame('old index', file_get_contents($this->abspath . 'index.php'));
    }

    public function testMoveCanBeRepeatedAfterARollback(): void
    {
        $this->live('index.php', 'old index');
        $this->stage('index.php', 'new index');
        $staged = $this->staged();
        $staged->moveIntoPlace();
        $staged->rollback();

        $result = $staged->moveIntoPlace();

        $this->assertSame(1, $result['moved']);
        $this->assertSame('new index', file_get_contents($this->abspath . 'index.php'));
    }

    public function testFailureHalfwayUndoesTheUnitsThatWereAlreadyMoved(): void
    {
        $this->live('a.php', 'old a');
        $this->live('z', 'a file where the source has a folder');
        $this->stage('a.php', 'new a');
        $this->stage('z/b.php', 'new b');
        $staged = $this->staged();

        try {
            $staged->moveIntoPlace();
            $this->fail('The move must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed and was undone', $e->getMessage());
        }

        $this->assertSame('old a', file_get_contents($this->abspath . 'a.php'));
        $this->assertSame('a file where the source has a folder', file_get_contents($this->abspath . 'z'));
        $this->assertSame('new a', file_get_contents($this->directory . 'staged/a.php' . StagedFiles::SUFFIX));
    }

    public function testInterruptedMoveIsFinishedByMovingAgain(): void
    {
        // A stop after the live folder went to replaced/ and before the new one took its place.
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $this->journal(['state' => 'moving'], ['path' => 'wp-content/plugins/seo', 'hadLive' => true]);
        mkdir($this->directory . 'replaced/wp-content/plugins', 0755, true);
        rename($this->abspath . 'wp-content/plugins/seo', $this->directory . 'replaced/wp-content/plugins/seo');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(1, $result['moved']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
        $this->assertSame('old', file_get_contents($this->directory . 'replaced/wp-content/plugins/seo/seo.php'));
    }

    public function testInterruptedMoveCanBeUndone(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $this->journal(['state' => 'moving'], ['path' => 'wp-content/plugins/seo', 'hadLive' => true]);
        mkdir($this->directory . 'replaced/wp-content/plugins', 0755, true);
        rename($this->abspath . 'wp-content/plugins/seo', $this->directory . 'replaced/wp-content/plugins/seo');

        $problems = $this->staged()->rollback();

        $this->assertSame([], $problems);
        $this->assertSame('old', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
        $this->assertSame('new', file_get_contents($this->directory . 'staged/wp-content/plugins/seo/seo.php' . StagedFiles::SUFFIX));
    }

    public function testJournalEntryThatLeavesTheSiteIsIgnoredAtRollback(): void
    {
        $outside = $this->createTemporaryDirectory();
        file_put_contents($outside . 'victim.php', 'original');
        $this->journal(['state' => 'moved'], ['path' => '../' . basename($outside) . '/victim.php', 'hadLive' => false]);

        $problems = $this->staged()->rollback();

        $this->assertCount(1, $problems);
        $this->assertSame('original', file_get_contents($outside . 'victim.php'));
    }

    public function testFolderIsCopiedWhenRenameIsNotPossible(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->live('wp-content/plugins/seo/removed-in-source.php', 'gone');
        $this->live('wp-content/plugins/seo/cache/a.tmp', 'cache');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $this->stage('wp-content/plugins/seo/lib/b.php', 'added');

        $staged = $this->crossDevice();
        $result = $staged->moveIntoPlace();

        $this->assertSame(1, $staged->refused);
        $this->assertSame(1, $result['moved']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
        $this->assertSame('added', file_get_contents($this->abspath . 'wp-content/plugins/seo/lib/b.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/seo/removed-in-source.php');
        $this->assertDirectoryDoesNotExist($this->abspath . 'wp-content/plugins/seo/cache');
        $this->assertSame('old', file_get_contents($this->directory . 'replaced/wp-content/plugins/seo/seo.php'));
        $this->assertSame('cache', file_get_contents($this->directory . 'replaced/wp-content/plugins/seo/cache/a.tmp'));
        $this->assertFileDoesNotExist($this->directory . 'replaced/wp-content/plugins/seo.part');
        $this->assertDirectoryDoesNotExist($this->directory . 'staged/wp-content/plugins/seo');
    }

    public function testSingleFileIsCopiedWhenRenameIsNotPossible(): void
    {
        $this->live('index.php', 'old');
        $this->stage('index.php', 'new');
        $this->stage('wp-content/mu-plugins/a.php', 'mu');
        chmod($this->directory . 'staged/index.php' . StagedFiles::SUFFIX, 0600);

        $staged = $this->crossDevice();
        $result = $staged->moveIntoPlace();

        $this->assertSame(2, $result['moved']);
        $this->assertSame(2, $staged->refused);
        $this->assertSame('new', file_get_contents($this->abspath . 'index.php'));
        $this->assertSame('mu', file_get_contents($this->abspath . 'wp-content/mu-plugins/a.php'));
        $this->assertSame('old', file_get_contents($this->directory . 'replaced/index.php'));
        clearstatcache();
        $this->assertSame('600', substr(sprintf('%o', fileperms($this->abspath . 'index.php')), -3));
    }

    public function testRollbackAfterACopiedMoveRestoresTheOldFolder(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->live('wp-content/plugins/seo/removed-in-source.php', 'gone');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $this->stage('wp-content/plugins/seo/added.php', 'added');
        $this->stage('wp-content/plugins/fresh/a.php', 'fresh');
        $staged = $this->crossDevice();
        $staged->moveIntoPlace();

        $problems = $staged->rollback();

        $this->assertSame([], $problems);
        $this->assertSame('old', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
        $this->assertSame('gone', file_get_contents($this->abspath . 'wp-content/plugins/seo/removed-in-source.php'));
        $this->assertFileDoesNotExist($this->abspath . 'wp-content/plugins/seo/added.php');
        $this->assertDirectoryDoesNotExist($this->abspath . 'wp-content/plugins/fresh');
    }

    public function testSuffixIsRemovedWhenTheFilesGoLive(): void
    {
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $this->stage('wp-content/plugins/seo/lib/b.php', 'b');
        $this->stage('index.php', 'index');
        $this->stage('wp-content/mu-plugins/m.php', 'm');

        $this->staged()->moveIntoPlace();

        foreach (['wp-content/plugins/seo/seo.php', 'wp-content/plugins/seo/lib/b.php', 'index.php', 'wp-content/mu-plugins/m.php'] as $path) {
            $this->assertFileExists($this->abspath . $path);
            $this->assertFileDoesNotExist($this->abspath . $path . StagedFiles::SUFFIX);
        }
        $this->assertSame([], $this->filesWithTheSuffix($this->abspath));
    }

    public function testStagedFilesKeepTheSuffixUntilTheyMove(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $staged = $this->crossDevice();

        $this->assertSame(['wp-content/plugins/seo/seo.php' . StagedFiles::SUFFIX], $this->filesWithTheSuffix($this->directory . 'staged'));
        $staged->moveIntoPlace();
        $this->assertSame([], $this->filesWithTheSuffix($this->directory . 'staged'));
    }

    public function testStopHalfwayThroughRemovingTheSuffixIsFinishedByMovingAgain(): void
    {
        $this->stage('wp-content/plugins/seo/a.php', 'a');
        $this->stage('wp-content/plugins/seo/b.php', 'b');
        $this->stage('wp-content/plugins/seo/lib/c.php', 'c');
        $this->stage('root.php', 'root');
        // The first file lost its suffix, the others did not. A single file lost it too.
        rename(
            $this->directory . 'staged/wp-content/plugins/seo/a.php' . StagedFiles::SUFFIX,
            $this->directory . 'staged/wp-content/plugins/seo/a.php'
        );
        rename($this->directory . 'staged/root.php' . StagedFiles::SUFFIX, $this->directory . 'staged/root.php');
        $this->journal(
            ['state' => 'moving'],
            ['path' => 'wp-content/plugins/seo', 'hadLive' => false],
            ['path' => 'root.php', 'hadLive' => false]
        );

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(2, $result['moved']);
        $this->assertSame('a', file_get_contents($this->abspath . 'wp-content/plugins/seo/a.php'));
        $this->assertSame('b', file_get_contents($this->abspath . 'wp-content/plugins/seo/b.php'));
        $this->assertSame('c', file_get_contents($this->abspath . 'wp-content/plugins/seo/lib/c.php'));
        $this->assertSame('root', file_get_contents($this->abspath . 'root.php'));
        $this->assertSame([], $this->filesWithTheSuffix($this->abspath));
    }

    public function testRollbackBringsTheSuffixBackAndARetryWorks(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->live('index.php', 'old index');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $this->stage('wp-content/plugins/seo/lib/b.php', 'b');
        $this->stage('index.php', 'new index');
        $staged = $this->staged();
        $staged->moveIntoPlace();

        $this->assertSame([], $staged->rollback());

        $this->assertSame([
            'index.php' . StagedFiles::SUFFIX,
            'wp-content/plugins/seo/lib/b.php' . StagedFiles::SUFFIX,
            'wp-content/plugins/seo/seo.php' . StagedFiles::SUFFIX,
        ], $this->filesWithTheSuffix($this->directory . 'staged'));
        $this->assertSame('old', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));

        $result = $staged->moveIntoPlace();

        $this->assertSame(2, $result['moved']);
        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
        $this->assertSame('b', file_get_contents($this->abspath . 'wp-content/plugins/seo/lib/b.php'));
        $this->assertSame('new index', file_get_contents($this->abspath . 'index.php'));
        $this->assertSame([], $this->filesWithTheSuffix($this->abspath));
    }

    public function testRollbackAfterACopiedMoveBringsTheSuffixBack(): void
    {
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        $staged = $this->crossDevice();
        $staged->moveIntoPlace();

        $this->assertSame([], $staged->rollback());

        $this->assertSame('old', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
        $this->assertSame([], $this->filesWithTheSuffix($this->abspath));
        $this->assertSame(
            ['wp-content/plugins/seo/seo.php' . StagedFiles::SUFFIX],
            $this->filesWithTheSuffix($this->directory . 'staged')
        );

        $staged->moveIntoPlace();

        $this->assertSame('new', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
    }

    public function testNamesWithTheReservedSuffixAreRecognised(): void
    {
        $staged = $this->staged();

        $this->assertTrue($staged->hasReservedSuffix('wp-content/plugins/a/x.php.pam-staged'));
        $this->assertTrue($staged->hasReservedSuffix('wp-content/plugins/a/x.php.PAM-STAGED'));
        $this->assertTrue($staged->hasReservedSuffix('wp-content/plugins/a.pam-staged/x.php'));
        $this->assertFalse($staged->hasReservedSuffix('wp-content/plugins/a/x.pam-staged.php'));
        $this->assertFalse($staged->hasReservedSuffix('wp-content/plugins/a/x.php'));
    }

    public function testStagedSymlinkIsNotMoved(): void
    {
        $outside = $this->createTemporaryDirectory();
        file_put_contents($outside . 'secret.php', 'secret');
        $this->stage('ok.php', 'ok');
        symlink($outside . 'secret.php', $this->directory . 'staged/link.php' . StagedFiles::SUFFIX);

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(1, $result['moved']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('link.php', $result['warnings'][0]);
        $this->assertFileDoesNotExist($this->abspath . 'link.php');
    }

    public function testStagedFolderWithASymlinkInsideIsNotSwapped(): void
    {
        $outside = $this->createTemporaryDirectory();
        $this->live('wp-content/plugins/seo/seo.php', 'old');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');
        symlink(rtrim($outside, '/'), $this->directory . 'staged/wp-content/plugins/seo/linked');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(0, $result['moved']);
        $this->assertCount(1, $result['warnings']);
        $this->assertSame('old', file_get_contents($this->abspath . 'wp-content/plugins/seo/seo.php'));
    }

    public function testLiveFolderThatIsASymlinkIsNotSwapped(): void
    {
        $target = $this->createTemporaryDirectory();
        file_put_contents($target . 'seo.php', 'target');
        mkdir($this->abspath . 'wp-content/plugins', 0755, true);
        symlink(rtrim($target, '/'), $this->abspath . 'wp-content/plugins/seo');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(0, $result['moved']);
        $this->assertStringContainsString('symlink', $result['warnings'][0]);
        $this->assertSame('target', file_get_contents($target . 'seo.php'));
        $this->assertTrue(is_link($this->abspath . 'wp-content/plugins/seo'));
    }

    public function testLiveFileThatIsASymlinkIsNotReplaced(): void
    {
        $target = $this->createTemporaryDirectory();
        file_put_contents($target . 'a.php', 'target');
        symlink($target . 'a.php', $this->abspath . 'a.php');
        $this->stage('a.php', 'new');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(0, $result['moved']);
        $this->assertSame('target', file_get_contents($target . 'a.php'));
    }

    public function testLiveParentThatLeadsOutsideIsNotWrittenThrough(): void
    {
        $outside = $this->createTemporaryDirectory();
        symlink(rtrim($outside, '/'), $this->abspath . 'linked');
        $this->stage('linked/new.php', 'new');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(0, $result['moved']);
        $this->assertFileDoesNotExist($outside . 'new.php');
    }

    public function testFolderWhereAFileIsLiveIsNotSwapped(): void
    {
        $this->live('wp-content/plugins/seo', 'a file');
        $this->stage('wp-content/plugins/seo/seo.php', 'new');

        $result = $this->staged()->moveIntoPlace();

        $this->assertSame(0, $result['moved']);
        $this->assertSame('a file', file_get_contents($this->abspath . 'wp-content/plugins/seo'));
    }

    public function testResolvesOutsideRootsAcceptsAnyAllowedRoot(): void
    {
        $real = $this->createTemporaryDirectory();
        symlink(rtrim($real, '/'), $this->abspath . 'wp-content');
        $staged = $this->staged();

        $this->assertTrue($staged->resolvesOutsideRoots($this->abspath . 'wp-content/uploads/a.jpg', [$this->abspath]));
        $this->assertFalse($staged->resolvesOutsideRoots(
            $this->abspath . 'wp-content/uploads/a.jpg',
            [$this->abspath, $this->abspath . 'wp-content']
        ));
        $this->assertTrue($staged->resolvesOutsideRoots($this->abspath . 'wp-content', [$this->abspath . 'wp-content']));
    }

    public function testDiscardRemovesEverythingOfTheMigration(): void
    {
        $this->stage('a.php', 'x');
        $staged = $this->staged();
        $staged->ensureDirectories();
        $staged->moveIntoPlace();

        $staged->discard();

        $this->assertDirectoryDoesNotExist($this->directory);
        $this->assertSame('x', file_get_contents($this->abspath . 'a.php'));
    }

    /**
     * @return string[] Sorted paths under a folder whose name ends with the suffix, relative to it
     */
    private function filesWithTheSuffix(string $directory): array
    {
        $found = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(rtrim($directory, '/'), \FilesystemIterator::SKIP_DOTS));

        foreach ($items as $item) {
            if (substr($item->getPathname(), -strlen(StagedFiles::SUFFIX)) === StagedFiles::SUFFIX) {
                $found[] = substr($item->getPathname(), strlen(rtrim($directory, '/')) + 1);
            }
        }

        sort($found);

        return $found;
    }

    private function staged(array $protectedPrefixes = [], bool $keepBackup = false): StagedFiles
    {
        return new StagedFiles($this->abspath, $this->directory, $protectedPrefixes, $keepBackup);
    }

    /**
     * A rename that works only inside one folder, like a rename between file systems.
     *
     * @return StagedFiles&object{refused: int}
     */
    private function crossDevice()
    {
        return new class ($this->abspath, $this->directory) extends StagedFiles {
            public int $refused = 0;

            protected function renamePath(string $from, string $to): bool
            {
                if (dirname($from) !== dirname($to)) {
                    $this->refused++;

                    return false;
                }

                return parent::renamePath($from, $to);
            }
        };
    }

    private function stage(string $path, string $content): void
    {
        self::writeFile($this->directory . 'staged/' . $path . StagedFiles::SUFFIX, $content);
    }

    private function live(string $path, string $content): void
    {
        self::writeFile($this->abspath . $path, $content);
    }

    private function journal(array ...$entries): void
    {
        file_put_contents($this->directory . 'journal.jsonl', implode('', array_map(
            static function (array $entry): string {
                return json_encode($entry) . "\n";
            },
            $entries
        )));
    }
}
