<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

use Composer\InstalledVersions;
use JOetjen\Cooper\ImportGlob;
use JOetjen\Cooper\LoadTrace;

/**
 * The compiled cache: a loaded, converted configuration written as a PHP
 * file, so the next request includes it instead of parsing the document.
 *
 * ## Why
 *
 * PHP is shared-nothing: every request starts with an empty process, and
 * Cooper's own cache lives in process memory. Loading eagerly at startup
 * would otherwise mean parsing every document on every request. A PHP
 * file is the one thing PHP keeps between requests for free -- opcache
 * holds its compiled form, and its constant arrays, in shared memory.
 *
 * ## When it is used
 *
 * Only while still valid. A compiled file records what its load
 * depended on (Cooper's `LoadTrace`):
 *
 *  - every file the load read, with modification time and size;
 *  - every filesystem import's expansion -- its root, its pattern, and
 *    the files it matched -- expanded again on every check, so a file
 *    added where `import "parts/*.casc"` looks (or removed from there)
 *    counts, though the load never read it;
 *  - every environment variable the document read (values, `${?NAME}`
 *    guards, import paths, `${COOPER_ENV}`), as a salted HMAC of the
 *    value, never the value itself.
 *
 * A load compares all three before using the file; any difference, and the
 * document is loaded afresh and the file rewritten. Environment values
 * are read the way Cooper reads them -- `.env` files, the real
 * environment, the `env` option -- so a changed `.env` counts.
 *
 * Not compiled at all, and loaded afresh every time, is a load whose
 * result has nothing to fingerprint: one that called a `resolvers`
 * entry (its answer could change any time), one given `importSchemes`,
 * or one holding a value `PhpExport` cannot write. Nor is a load that
 * read a file modified within the last second: a second edit in the
 * same second would keep the modification time the file is fingerprinted
 * by (git's "racy" entries, and the same answer); the next load
 * compiles it.
 *
 * What is baked in and not fingerprinted: the answers of consumer
 * `tags` and `!module(...)`. The `modules` mapping and the tag names are
 * part of the file's name, so a different mapping gets a different file;
 * a changed tag *implementation* needs `cache:clear` (part of any
 * deploy).
 *
 * ## Security
 *
 * The file holds the configuration as the application sees it --
 * revealed secrets included -- and is code PHP will run. So it is
 * written atomically (a temporary file renamed over it, so a request
 * never includes half a file) with mode `0600` into a directory created
 * `0700`, and is never included unless it is owned by this process's
 * user and writable by nobody else; one that fails the check is ignored
 * and rewritten. Keep the cache directory out of the web root, out of
 * version control, and out of backups that are less protected than the
 * secrets themselves.
 */
final class CompiledCache
{
    /** Bumped whenever the file's layout changes, so an older file is simply invalid. */
    public const FORMAT = 2;

    /** Every compiled file's name starts with this; `clear()` removes nothing else. */
    private const PREFIX = 'cooper-config-';

    private function __construct()
    {
    }

    /**
     * Where compiled files go by default: `<root>/var/cache/cooper-config`
     * -- `var/` being where Symfony, and PHP projects generally, keep
     * what they generate.
     *
     * @param string $root the project root
     * @return string the directory
     */
    public static function defaultDirectory(string $root): string
    {
        return rtrim($root, '/') . '/var/cache/cooper-config';
    }

    /**
     * Removes every compiled file in `$dir` (and any temporary file an
     * interrupted write left behind), and nothing else.
     *
     * @param string $dir the cache directory
     * @return int how many compiled files were removed
     */
    public static function clear(string $dir): int
    {
        $removed = 0;
        foreach (glob($dir . '/' . self::PREFIX . '*.php') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
            self::forget($file);
        }
        foreach (glob($dir . '/.' . self::PREFIX . '*') ?: [] as $file) {
            @unlink($file);
        }

        return $removed;
    }

    /**
     * The compiled file for one document, root, and set of options that
     * change what a load produces without the trace seeing it.
     *
     * @internal
     * @param array<string, mixed> $modules
     * @param array<string, mixed> $tags
     */
    public static function fileFor(string $dir, string $document, string $root, array $modules, array $tags): string
    {
        $tagNames = array_keys($tags);
        sort($tagNames, SORT_STRING);
        ksort($modules, SORT_STRING);
        $key = json_encode([
            self::FORMAT,
            $document,
            $root,
            self::version('joetjen/cooper'),
            self::version('joetjen/cooper-config'),
            $tagNames,
            array_map(static fn (mixed $v): string => is_scalar($v) ? var_export($v, true) : get_debug_type($v), $modules),
        ], JSON_THROW_ON_ERROR);

        return rtrim($dir, '/') . '/' . self::PREFIX . substr(hash('sha256', $key), 0, 32) . '.php';
    }

    /**
     * The configuration `$file` holds, or null when there is none, it
     * cannot be trusted, or it is no longer valid.
     *
     * @internal
     * @param callable(): array<string, string> $env the environment `${...}` would read now
     * @return array<array-key, mixed>|null
     */
    public static function read(string $file, callable $env): ?array
    {
        clearstatcache(true, $file);
        if (!is_file($file) || !self::trustworthy($file)) {
            return null;
        }

        try {
            $data = (static fn (string $path): mixed => include $path)($file);
        } catch (\Throwable) {
            return null;
        }
        if (
            !is_array($data)
            || ($data['format'] ?? null) !== self::FORMAT
            || !is_array($data['files'] ?? null)
            || !is_array($data['globs'] ?? null)
            || !is_array($data['env'] ?? null)
            || !is_string($data['salt'] ?? null)
            || !($data['config'] ?? null) instanceof \Closure
        ) {
            return null;
        }

        foreach ($data['files'] as $path => $stamp) {
            if (self::stamp((string) $path) !== $stamp) {
                return null;
            }
        }
        foreach ($data['globs'] as $glob) {
            // Expanded by Cooper itself, so a pattern matches here exactly
            // what it matched when the document was loaded.
            if (
                !is_array($glob)
                || !is_string($glob[0] ?? null)
                || !is_string($glob[1] ?? null)
                || !is_array($glob[2] ?? null)
                || (new ImportGlob($glob[0], $glob[1], array_values(array_map('strval', $glob[2]))))->changed()
            ) {
                return null;
            }
        }
        if ($data['env'] !== []) {
            try {
                $current = $env();
            } catch (\Throwable) {
                // A malformed `.env`: the fresh load reports it properly.
                return null;
            }
            foreach ($data['env'] as $name => $hash) {
                $name = (string) $name;
                if (!is_string($hash) || !hash_equals($hash, self::hash($data['salt'], $name, $current[$name] ?? ''))) {
                    return null;
                }
            }
        }

        try {
            $config = ($data['config'])();
        } catch (\Throwable) {
            // A secret class that has since gone away, say.
            return null;
        }

        return is_array($config) ? $config : null;
    }

    /**
     * Writes `$plan` (`Convert::plan()`'s output) to `$file`, valid for
     * as long as `$trace`'s files, import expansions, and variables hold.
     *
     * @internal
     * @param array<array-key, mixed> $plan
     * @return bool whether a file was written; false when the plan cannot be written as PHP, a file was just modified, or the directory is not writable
     */
    public static function write(string $file, LoadTrace $trace, array $plan): bool
    {
        try {
            $body = PhpExport::export($plan);
        } catch (NotExportable) {
            return false;
        }

        $stamps = [];
        foreach ($trace->files as $path) {
            $stamps[$path] = self::stamp($path);
        }
        $globs = array_map(
            static fn (ImportGlob $glob): array => [$glob->root, $glob->pattern, $glob->files],
            $trace->globs,
        );
        $now = time();
        foreach ($stamps as $stamp) {
            if ($stamp !== null && $stamp[0] >= $now - 1) {
                return false;
            }
        }

        $salt = bin2hex(random_bytes(16));
        $env = [];
        foreach ($trace->env as $name => $value) {
            $env[$name] = self::hash($salt, $name, $value ?? '');
        }

        $source = "<?php\n\n"
            . "// Written by joetjen/cooper-config: the compiled configuration of\n"
            // A path is data in a comment: no line break may end the comment
            // early, and no closing tag may end PHP mode.
            . '// ' . str_replace(["\n", "\r", '?>'], [' ', ' ', '? >'], $trace->files[0] ?? '') . "\n"
            . "// It holds revealed secrets: never commit, copy, or share it. It is\n"
            . "// rewritten whenever a file, import, or variable it records changes, and\n"
            . "// `vendor/bin/cooper-config cache:clear` removes it.\n\n"
            . "return [\n"
            . '    \'format\' => ' . self::FORMAT . ",\n"
            . '    \'files\' => ' . PhpExport::export($stamps) . ",\n"
            . '    \'globs\' => ' . PhpExport::export($globs) . ",\n"
            . '    \'salt\' => ' . var_export($salt, true) . ",\n"
            . '    \'env\' => ' . PhpExport::export($env) . ",\n"
            . "    'config' => static function (): array {\n"
            . "        return {$body};\n"
            . "    },\n"
            . "];\n";

        return self::writeAtomically($file, $source);
    }

    private static function writeAtomically(string $file, string $source): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            // Parents as the umask says (`var/` is the project's, not
            // ours); the cache directory itself private.
            if (!is_dir(dirname($dir)) && !@mkdir(dirname($dir), 0777, true) && !is_dir(dirname($dir))) {
                return false;
            }
            if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                return false;
            }
            @chmod($dir, 0700);
        }

        $temp = @tempnam($dir, '.' . self::PREFIX);
        if ($temp === false || dirname($temp) !== $dir) {
            // `tempnam()` falls back to the system temp directory, from
            // which a rename would not be atomic.
            if ($temp !== false) {
                @unlink($temp);
            }

            return false;
        }

        if (@file_put_contents($temp, $source) !== strlen($source) || !@chmod($temp, 0600) || !@rename($temp, $file)) {
            @unlink($temp);

            return false;
        }
        self::forget($file);

        return true;
    }

    /**
     * Drops opcache's copy of `$file`, which with
     * `opcache.validate_timestamps=0` would otherwise be served forever.
     */
    private static function forget(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * Owned by this process's user, writable by nobody else: a file
     * anybody else could have written is code nobody vetted.
     */
    private static function trustworthy(string $file): bool
    {
        $perms = @fileperms($file);
        if ($perms === false || ($perms & 0022) !== 0) {
            return false;
        }

        return !function_exists('posix_geteuid') || @fileowner($file) === posix_geteuid();
    }

    /**
     * @return array{0: int, 1: int}|null modification time and size, or null for a missing path
     */
    private static function stamp(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? null : [(int) $stat['mtime'], (int) $stat['size']];
    }

    private static function hash(string $salt, string $name, string $value): string
    {
        return hash_hmac('sha256', $name . "\0" . $value, $salt);
    }

    private static function version(string $package): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
            return '';
        }

        return InstalledVersions::getPrettyVersion($package) . '@' . InstalledVersions::getReference($package);
    }
}
