<?php

declare(strict_types=1);

namespace PanelAlpha\Migrator\Restore;

use PanelAlpha\Migrator\Helpers\Filesystem;

/**
 * Class StagedFiles
 *
 * @package PanelAlpha\Migrator\Restore
 *
 * Code files from the source do not go into the live site while the migration
 * runs. They wait in <migration folder>/staged/ and move into place at the end.
 * Whole folders are swapped for wp-admin, wp-includes and each plugin and theme.
 * Everything else moves file by file. Replaced files wait in <migration folder>/replaced/
 * and a journal records every unit, so a move that stopped halfway can be finished
 * (moveIntoPlace again) or undone (rollback).
 *
 * A staged file carries the suffix SUFFIX, so no web server runs it while it waits.
 * moveIntoPlace removes the suffix of a unit right before that unit goes live,
 * and rollback puts it back, so a retry finds the files as the handler wrote them.
 */
class StagedFiles
{
    public const SUFFIX = '.pam-staged';

    private const STAGED_EXTENSIONS = ['php', 'phtml', 'phar', 'inc'];
    private const DROP_INS = ['object-cache.php', 'advanced-cache.php', 'db.php'];
    private const CORE_DIRECTORIES = ['wp-admin', 'wp-includes'];

    private string $abspath;
    private string $directory;
    private array $protectedPrefixes;
    private bool $keepBackup;
    private FilePathConfinement $confinement;

    /**
     * StagedFiles constructor.
     *
     * @param string   $abspath           WordPress directory that file paths are relative to
     * @param string   $directory         Folder of this migration, for staged/, replaced/ and the journal
     * @param string[] $protectedPrefixes Lowercase path prefixes that are never moved
     * @param bool     $keepBackup        Keep the replaced files after finish()
     */
    public function __construct(string $abspath, string $directory, array $protectedPrefixes = [], bool $keepBackup = false)
    {
        $this->abspath = Filesystem::trailingSlashIt($abspath);
        $this->directory = Filesystem::trailingSlashIt($directory);
        $this->protectedPrefixes = $protectedPrefixes;
        $this->keepBackup = $keepBackup;
        $this->confinement = new FilePathConfinement();
    }

    /**
     * Tells whether a file is written to the staging folder instead of the live site.
     *
     * @param string $path Path relative to the WordPress directory
     * @return bool
     */
    public function isStaged(string $path): bool
    {
        if (preg_match('#^(wp-admin|wp-includes)/#', $path) === 1
            || preg_match('#^wp-content/(plugins|themes)/[^/]+/#', $path) === 1
            || strpos($path, 'wp-content/mu-plugins/') === 0
        ) {
            return true;
        }

        $depth = substr_count($path, '/');

        if ($depth === 0
            || ($depth === 1 && strpos($path, 'wp-content/') === 0)
            || ($depth === 2 && strpos($path, 'wp-content/plugins/') === 0)
        ) {
            return true;
        }

        $name = strtolower(basename($path));

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::STAGED_EXTENSIONS, true)
            || $name === '.user.ini'
            || $name === '.htaccess';
    }

    /**
     * The destination keeps its own drop-ins. A cache or database drop-in from the
     * source would break a destination that has no matching service.
     *
     * @param string $path Path relative to the WordPress directory
     * @return bool
     */
    public function isDestinationDropIn(string $path): bool
    {
        $path = strtolower($path);

        foreach (self::DROP_INS as $dropIn) {
            if ($path === 'wp-content/' . $dropIn) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string Staging folder with a trailing slash
     */
    public function stagedRoot(): string
    {
        return $this->directory . 'staged/';
    }

    /**
     * @param string $path Path relative to the WordPress directory
     * @return string
     */
    public function stagedPath(string $path): string
    {
        return $this->stagedRoot() . $path;
    }

    /**
     * Where a staged file is written: its path with the suffix.
     *
     * @param string $path Path relative to the WordPress directory
     * @return string
     */
    public function stagedFilePath(string $path): string
    {
        return $this->stagedPath($path) . self::SUFFIX;
    }

    /**
     * A name with the suffix in it cannot be told apart from a staged file, so it is not accepted.
     *
     * @param string $path Path relative to the WordPress directory
     * @return bool
     */
    public function hasReservedSuffix(string $path): bool
    {
        return preg_match('#' . preg_quote(self::SUFFIX, '#') . '(/|$)#i', $path) === 1;
    }

    /**
     * Creates the staging folder, and the files that keep the migration folder from being listed.
     *
     * @return bool
     */
    public function ensureDirectories(): bool
    {
        if (!Filesystem::mkdirP($this->stagedRoot())) {
            return false;
        }

        if (!file_exists($this->directory . 'index.php')) {
            @file_put_contents($this->directory . 'index.php', "<?php\n// Silence is golden.\n");
        }

        if (!file_exists($this->directory . '.htaccess')) {
            @file_put_contents(
                $this->directory . '.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
            );
        }

        return true;
    }

    /**
     * Folders the live site may be written in. WordPress is loaded, so wp-content
     * may be a symlink to another place (Bitnami) and is allowed as a root of its own.
     *
     * @return string[]
     */
    public function liveRoots(): array
    {
        $roots = [$this->abspath];

        if (defined('WP_CONTENT_DIR')) {
            $roots[] = WP_CONTENT_DIR;
        }

        return $roots;
    }

    /**
     * Looks at the disk: the path may be a symlink, or its nearest existing
     * parent may be a symlink that leads out of every allowed folder.
     *
     * @param string   $fullPath
     * @param string[] $roots
     * @return bool
     */
    public function resolvesOutsideRoots(string $fullPath, array $roots): bool
    {
        if (is_link($fullPath)) {
            return true;
        }

        $real = [];

        foreach ($roots as $root) {
            $resolved = realpath($root);

            if ($resolved !== false) {
                $real[] = rtrim($resolved, '/');
            }
        }

        $parent = dirname($fullPath);

        while (!file_exists($parent)) {
            if (is_link($parent)) {
                return true;
            }

            $next = dirname($parent);

            if ($next === $parent) {
                return true;
            }

            $parent = $next;
        }

        $resolvedParent = realpath($parent);

        if ($resolvedParent === false) {
            return true;
        }

        foreach ($real as $root) {
            if ($resolvedParent === $root || strpos($resolvedParent, $root . '/') === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Moves the staged files into the live site. When it stops halfway, the call
     * repeated finishes the work. A failure undoes everything and throws.
     * Replaced files stay in replaced/ until finish().
     *
     * @return array{moved: int, warnings: string[]}
     * @throws \RuntimeException
     */
    public function moveIntoPlace(): array
    {
        $journal = $this->readJournal();

        if ($journal['state'] === 'moved') {
            return ['moved' => 0, 'warnings' => []];
        }

        if ($journal['state'] === 'rolled_back') {
            wp_delete_file($this->journalFile());
            $journal = ['state' => null, 'units' => []];
        }

        if (!is_dir($this->stagedRoot())) {
            $this->appendJournal(['state' => 'moved']);

            return ['moved' => 0, 'warnings' => []];
        }

        $this->appendJournal(['state' => 'moving']);

        $warnings = [];
        $applied = [];

        try {
            foreach ($this->plan($warnings) as $path) {
                $live = $this->abspath . $path;

                if (!$this->canReplace($path, $warnings)) {
                    continue;
                }

                $hadLive = $journal['units'][$path] ?? file_exists($live);
                $this->appendJournal(['path' => $path, 'hadLive' => $hadLive]);
                $this->applyUnit($path, $hadLive);
                $applied[] = $path;
            }

            $this->appendJournal(['state' => 'moved']);
        } catch (\Throwable $e) {
            $problems = $this->rollback();
            $message = 'Moving files into place failed and was undone: ' . $e->getMessage();

            if ($problems !== []) {
                $message .= ' (not everything could be restored: ' . implode('; ', $problems) . ')';
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not output
            throw new \RuntimeException(esc_html($message), 0, $e);
        }

        $this->invalidateOpcache($applied);

        return ['moved' => count($applied), 'warnings' => $warnings];
    }

    /**
     * Puts the replaced files back, from the journal. The new files go back to
     * the staging folder. Safe to call again.
     *
     * @return string[] What could not be restored, empty when all went back
     */
    public function rollback(): array
    {
        $journal = $this->readJournal();
        $problems = [];
        $restored = [];

        foreach (array_reverse(array_keys($journal['units'])) as $path) {
            try {
                $this->confinement->confine((string) $path, $this->abspath);
                $this->undoUnit((string) $path, $journal['units'][$path]);
                $restored[] = (string) $path;
            } catch (\Throwable $e) {
                $problems[] = $path . ': ' . $e->getMessage();
            }
        }

        if ($journal['state'] !== null) {
            $this->appendJournal(['state' => 'rolled_back']);
        }

        $this->invalidateOpcache($restored);

        return $problems;
    }

    /**
     * Ends a successful move: removes what is left in staged/, the journal, and
     * replaced/ unless the backup is kept.
     *
     * @throws \RuntimeException When the move is not complete
     */
    public function finish(): void
    {
        if ($this->readJournal()['state'] === 'moving') {
            throw new \RuntimeException('Moving files into place is not complete');
        }

        $this->removePath($this->stagedRoot());
        wp_delete_file($this->journalFile());

        if (!$this->keepBackup) {
            $this->removePath($this->replacedRoot());
        }
    }

    /**
     * Removes everything this migration left: staged and replaced files and the journal.
     */
    public function discard(): void
    {
        $this->removePath($this->directory);
    }

    /**
     * Seam for tests. A rename can fail in real life between file systems.
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    protected function renamePath(string $from, string $to): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the target can already exist (a file is replaced in one step), and WP_Filesystem::move() refuses that or deletes the target first
        return @rename($from, $to);
    }

    private function replacedRoot(): string
    {
        return $this->directory . 'replaced/';
    }

    private function journalFile(): string
    {
        return $this->directory . 'journal.jsonl';
    }

    /**
     * Lists the units to move: folders swapped whole, and single files.
     *
     * @param string[] $warnings
     * @return string[] Paths relative to the WordPress directory
     */
    private function plan(array &$warnings): array
    {
        $units = [];
        $this->collect('', $units, $warnings);

        return $units;
    }

    private function collect(string $relative, array &$units, array &$warnings): void
    {
        $names = scandir($this->stagedRoot() . $relative);

        foreach ($names === false ? [] : $names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $this->stagedPath($relative . $name);

            if (is_link($full)) {
                $warnings[] = $relative . $name . ': staged symlink was not moved';
                continue;
            }

            // Files wait with the suffix. One without it was already stripped before a stop.
            $path = $relative . (is_dir($full) ? $name : $this->withoutSuffix($name));

            if ($this->isProtected($path . (is_dir($full) ? '/' : '')) || $this->isDestinationDropIn($path)) {
                continue;
            }

            if (!is_dir($full)) {
                $units[] = $path;
            } elseif (!$this->isFolderUnit($path)) {
                $this->collect($path . '/', $units, $warnings);
            } elseif ($this->containsSymlink($full)) {
                $warnings[] = $path . ': staged folder contains a symlink and was not moved';
            } else {
                $units[] = $path;
            }
        }
    }

    /**
     * Core folders are swapped only when the staged core is whole. Without
     * wp-includes/version.php the migration carried only a part of it.
     */
    private function isFolderUnit(string $path): bool
    {
        if (in_array($path, self::CORE_DIRECTORIES, true)) {
            return is_file($this->stagedEntry('wp-includes/version.php'));
        }

        return preg_match('#^wp-content/(plugins|themes)/[^/]+$#', $path) === 1;
    }

    private function isProtected(string $path): bool
    {
        $path = strtolower($path);

        foreach ($this->protectedPrefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private function containsSymlink(string $directory): bool
    {
        foreach (array_diff((array) scandir($directory), ['.', '..']) as $name) {
            $path = $directory . '/' . $name;

            if (is_link($path) || (is_dir($path) && $this->containsSymlink($path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The disk may have changed since the file was written, so the live side is checked again.
     *
     * @param string   $path
     * @param string[] $warnings
     * @return bool
     */
    private function canReplace(string $path, array &$warnings): bool
    {
        $live = $this->abspath . $path;

        try {
            $this->confinement->confine($path, $this->abspath);
        } catch (\InvalidArgumentException $e) {
            $warnings[] = $path . ': ' . $e->getMessage();

            return false;
        }

        if ($this->resolvesOutsideRoots($live, $this->liveRoots())) {
            $warnings[] = $path . ': destination is a symlink or leads outside the WordPress directory, not replaced';

            return false;
        }

        if (file_exists($live) && is_dir($live) !== is_dir($this->stagedEntry($path))) {
            $warnings[] = $path . ': destination is a file where a folder is expected or the other way round, not replaced';

            return false;
        }

        return true;
    }

    /**
     * Moves one unit. Every step looks at the disk first, so running it again
     * after a stop finishes what is left.
     *
     * @param string $path
     * @param bool   $hadLive The live path existed before the first move
     */
    private function applyUnit(string $path, bool $hadLive): void
    {
        $staged = $this->stagedEntry($path);
        $live = $this->abspath . $path;
        $backup = $this->replacedRoot() . $path;

        if (!$this->exists($staged)) {
            return;
        }

        if ($hadLive && !$this->exists($backup)) {
            $this->ensureParent($backup);

            if (!$this->renamePath($live, $backup)) {
                // The copy gets its name only when whole, so a stop never leaves half a backup.
                $this->removePath($backup . '.part');
                $this->copyPath($live, $backup . '.part');

                if (!$this->renamePath($backup . '.part', $backup)) {
                    throw new \RuntimeException(esc_html('Failed to keep a copy of ' . $path));
                }
            }
        }

        // Only now do the files get their real names, right before they go live.
        $this->renameFiles($staged, false);
        $staged = $this->stagedPath($path);

        if (!$this->exists($live)) {
            $this->ensureParent($live);

            if ($this->renamePath($staged, $live)) {
                return;
            }
        }

        // Rename failed (another file system, or a parent that is not writable): copy file by file.
        $this->copyPath($staged, $live, true);
        $this->removePath($staged);
    }

    /**
     * Undoes one unit from what is on the disk.
     */
    private function undoUnit(string $path, bool $hadLive): void
    {
        $staged = $this->stagedPath($path);
        $live = $this->abspath . $path;
        $backup = $this->replacedRoot() . $path;

        if ($hadLive && !$this->exists($backup)) {
            return;
        }

        if ($this->exists($live)) {
            // The new files go back to staged/ so a retry does not need to send them again.
            if ($this->exists($this->stagedEntry($path)) || !$this->renamePath($live, $staged)) {
                $this->keepCopyInStaged($live, $path);

                if (!$this->removePath($live)) {
                    throw new \RuntimeException(esc_html('Failed to remove ' . $live));
                }
            }
        }

        // Back to the state the handler left, so a retry can strip the suffix again.
        if ($this->exists($staged)) {
            $this->renameFiles($staged, true);
        }

        if (!$hadLive) {
            return;
        }

        $this->ensureParent($live);

        if (!$this->renamePath($backup, $live)) {
            $this->copyPath($backup, $live);
            $this->removePath($backup);
        }
    }

    /**
     * The staged entry of a unit: the file with the suffix, else the path as it is
     * (a folder, or a file that was already stripped).
     */
    private function stagedEntry(string $path): string
    {
        $suffixed = $this->stagedFilePath($path);

        return $this->exists($suffixed) ? $suffixed : $this->stagedPath($path);
    }

    private function withoutSuffix(string $name): string
    {
        $length = strlen(self::SUFFIX);

        return strlen($name) > $length && substr($name, -$length) === self::SUFFIX ? substr($name, 0, -$length) : $name;
    }

    /**
     * Adds the suffix to the files under a staged entry, or removes it. Symlinks are
     * left alone. A file that is already in the wanted state stays, so a stop is no problem.
     *
     * @param string $entry
     * @param bool   $add
     * @throws \RuntimeException
     */
    private function renameFiles(string $entry, bool $add): void
    {
        if (is_link($entry)) {
            return;
        }

        if (is_dir($entry)) {
            foreach (array_diff((array) scandir($entry), ['.', '..']) as $name) {
                $this->renameFiles($entry . '/' . $name, $add);
            }

            return;
        }

        $has = $this->withoutSuffix($entry) !== $entry;

        if ($add === $has) {
            return;
        }

        $target = $add ? $entry . self::SUFFIX : $this->withoutSuffix($entry);

        if (!$this->renamePath($entry, $target)) {
            throw new \RuntimeException(esc_html('Failed to rename staged file: ' . $entry));
        }
    }

    /**
     * Best effort, for a move that was done by copying: the new files were removed from staged/,
     * so a copy of them keeps a retry possible. Failing here must not stop the restore of the old files.
     */
    private function keepCopyInStaged(string $live, string $path): void
    {
        if ($this->exists($this->stagedEntry($path))) {
            return;
        }

        try {
            $this->copyPath($live, $this->stagedPath($path));
        } catch (\Throwable $e) {
            $this->removePath($this->stagedPath($path));
        }
    }

    private function ensureParent(string $path): void
    {
        if (!Filesystem::mkdirP(dirname($path))) {
            throw new \RuntimeException(esc_html('Failed to create directory: ' . dirname($path)));
        }
    }

    private function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    /**
     * Copies a file or a folder with its permissions and dates. A symlink inside is
     * copied as a link, never followed. With $prune a folder ends up the same as the source.
     *
     * @param string $from
     * @param string $to
     * @param bool   $prune
     * @throws \RuntimeException
     */
    private function copyPath(string $from, string $to, bool $prune = false): void
    {
        if (is_link($from)) {
            $target = readlink($from);
            $this->removePath($to);

            if ($target === false || !@symlink($target, $to)) {
                throw new \RuntimeException(esc_html('Failed to copy symlink: ' . $from));
            }

            return;
        }

        if (is_dir($from)) {
            if ($this->exists($to) && !is_dir($to)) {
                $this->removePath($to);
            }

            if (!is_dir($to) && !wp_mkdir_p($to)) {
                throw new \RuntimeException(esc_html('Failed to create directory: ' . $to));
            }

            $names = array_diff((array) scandir($from), ['.', '..']);

            foreach ($names as $name) {
                $this->copyPath($from . '/' . $name, $to . '/' . $name, $prune);
            }

            if ($prune) {
                foreach (array_diff((array) scandir($to), ['.', '..'], $names) as $extra) {
                    $this->removePath($to . '/' . $extra);
                }
            }

            return;
        }

        if (is_dir($to) && !is_link($to)) {
            $this->removePath($to);
        }

        if (!@copy($from, $to)) {
            throw new \RuntimeException(esc_html('Failed to copy file: ' . $from));
        }

        $mode = (int) fileperms($from) & 0777;

        // WP_Filesystem_Direct::chmod() reads a mode of 0 as "use the default", which needs FS_CHMOD_FILE.
        if ($mode !== 0) {
            Filesystem::direct()->chmod($to, $mode);
        }

        @Filesystem::direct()->touch($to, (int) filemtime($from));
    }

    /**
     * Removes a file or a folder. A symlink is removed, not followed.
     *
     * @param string $path
     * @return bool True when nothing is left
     */
    private function removePath(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            wp_delete_file($path);

            return !is_link($path) && !file_exists($path);
        }

        if (!is_dir($path)) {
            return true;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
            $this->removePath($path . '/' . $name);
        }

        return Filesystem::direct()->rmdir($path, false);
    }

    /**
     * @param string[] $paths Relative paths of units that were moved or restored
     */
    private function invalidateOpcache(array $paths): void
    {
        foreach ($paths as $path) {
            $live = $this->abspath . $path;

            if (is_dir($live) && function_exists('wp_opcache_invalidate_directory')) {
                wp_opcache_invalidate_directory($live);
            } elseif (is_file($live) && function_exists('wp_opcache_invalidate')) {
                wp_opcache_invalidate($live, true);
            }
        }
    }

    /**
     * @return array{state: ?string, units: array<string, bool>}
     */
    private function readJournal(): array
    {
        $journal = ['state' => null, 'units' => []];
        $lines = is_file($this->journalFile()) ? file($this->journalFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

        foreach ($lines === false ? [] : $lines as $line) {
            $entry = json_decode($line, true);

            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['state'])) {
                $journal['state'] = (string) $entry['state'];
            } elseif (isset($entry['path']) && !isset($journal['units'][$entry['path']])) {
                $journal['units'][(string) $entry['path']] = !empty($entry['hadLive']);
            }
        }

        return $journal;
    }

    /**
     * Writes one line before the work it describes, so a stop never hides a unit.
     *
     * @param array $entry
     * @throws \RuntimeException
     */
    private function appendJournal(array $entry): void
    {
        if (!is_dir($this->directory) && !Filesystem::mkdirP($this->directory)) {
            throw new \RuntimeException(esc_html('Failed to create directory: ' . $this->directory));
        }

        $written = @file_put_contents($this->journalFile(), json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

        if ($written === false) {
            throw new \RuntimeException('Failed to write the file move journal');
        }
    }
}
