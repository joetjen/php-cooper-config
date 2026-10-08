<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Dotenv;

/**
 * The application's configuration: a CASC document loaded with Cooper,
 * once, at startup, and read through this class afterwards -- the PHP
 * counterpart of the Elixir `cooper_config` and the Praxis `cooper_prx`.
 *
 * ## Eager only
 *
 * ```php
 * // public/index.php, bin/console, a worker's entry point -- first thing
 * JOetjen\CooperConfig\Config::load();
 *
 * // anywhere afterwards
 * $port = Config::get('http.port', 8080);
 * ```
 *
 * **Configuration arrives; it is not asked for.** `load()` reads the
 * whole document, resolves it, converts it (see `Convert`), and stores
 * it for the process. Nothing is ever loaded on first access: reading
 * before `load()` ran is a `NotLoadedError` saying where the call goes.
 * A document that does not load stops the program at its first line,
 * with the reason, instead of at whichever request first reads a broken
 * setting -- and a configuration that is never half there is one no
 * code has to defend against.
 *
 * PHP being shared-nothing, "once" is once per request for PHP-FPM, and
 * once per process for a long-running worker. The compiled cache (see
 * `CompiledCache`, on by default) is what makes per-request loading
 * cheap.
 *
 * ## Options
 *
 *  - `path` (`string`) -- the document; relative to `root`. Default
 *    `config/config.casc`. A missing default document loads an empty
 *    configuration (a project with nothing to configure yet); a missing
 *    `path` given explicitly is a `ConfigError`.
 *  - `root` (`string`, an existing directory) -- the project root.
 *    Default: the Composer root package's directory (`ProjectRoot`).
 *  - `compiledCache` (`bool|string`, default `true`) -- `false` turns the
 *    compiled cache off, a string is the directory to keep it in instead
 *    of `<root>/var/cache/cooper-config`.
 *  - Cooper's own load options, passed through: `env`, `dotenv`,
 *    `dotenvEnv`, `dotenvFiles`, `dotenvDir`, `dotenvOverride`,
 *    `resolvers`, `tags`, `importSchemes`, `modules`, `cache` (see
 *    `JOetjen\Cooper\Cooper`). `modules` also resolves a
 *    `cooper-secrets` class name.
 *
 * An unknown option is an `\InvalidArgumentException` listing the
 * supported ones. `.env` files are Cooper's to find: it reads them from
 * the project root (the Composer root package's directory) wherever the
 * process started -- `public/`, for a web request -- or from `dotenvDir`.
 * Nothing here changes the working directory.
 *
 * ## Reading
 *
 * A path is a dotted string (`'database.host'`) or a list of segments
 * (`['hosts', 'example.com']`, for a key holding a dot). An empty path
 * or segment is an `\InvalidArgumentException`.
 */
final class Config
{
    /** The document's default location, relative to the project root. */
    public const DEFAULT_PATH = 'config/config.casc';

    /** @var array<array-key, mixed>|null */
    private static ?array $config = null;

    private static ?LoadReport $report = null;

    private function __construct()
    {
    }

    /**
     * Loads the configuration and stores it for this process, replacing
     * whatever an earlier call stored. Call it once, at startup.
     *
     * @param array<string, mixed> $options see the class documentation
     * @throws ConfigError when the document is missing (an explicit `path`), does not load, or cannot be converted; nothing is stored
     * @throws \InvalidArgumentException on an unknown or mistyped option
     */
    public static function load(array $options = []): void
    {
        $opts = Options::from($options);
        $root = $opts->root ?? ProjectRoot::detect();
        $file = self::absolute($opts->path ?? self::DEFAULT_PATH, $root);

        if (!is_file($file)) {
            if ($opts->path !== null) {
                throw ConfigError::missingFile($file);
            }
            self::$config = [];
            self::$report = new LoadReport($file, false, false, null);

            return;
        }

        [$config, $report] = self::loadDocument($file, $root, $opts);
        self::$config = $config;
        self::$report = $report;
    }

    /**
     * The value at `$path`, or `$default` when nothing is there. A
     * present `nil` is a value, not an absence.
     *
     * @param string|list<string|int> $path a dotted path or a list of segments
     * @param mixed $default what to return when `$path` leads nowhere
     * @return mixed the value, or `$default`
     * @throws NotLoadedError when `load()` has not run
     * @throws \InvalidArgumentException on an empty path or segment
     */
    public static function get(string|array $path, mixed $default = null): mixed
    {
        [$found, $value] = self::lookup($path);

        return $found ? $value : $default;
    }

    /**
     * The value at `$path`, which must be there.
     *
     * @param string|list<string|int> $path a dotted path or a list of segments
     * @return mixed the value
     * @throws MissingKeyError when nothing is at `$path`, naming it
     * @throws NotLoadedError when `load()` has not run
     * @throws \InvalidArgumentException on an empty path or segment
     */
    public static function require(string|array $path): mixed
    {
        [$found, $value] = self::lookup($path);
        if (!$found) {
            throw new MissingKeyError(self::segments($path));
        }

        return $value;
    }

    /**
     * Whether anything -- `nil` included -- is at `$path`.
     *
     * @param string|list<string|int> $path a dotted path or a list of segments
     * @return bool whether `$path` leads to a value
     * @throws NotLoadedError when `load()` has not run
     * @throws \InvalidArgumentException on an empty path or segment
     */
    public static function has(string|array $path): bool
    {
        return self::lookup($path)[0];
    }

    /**
     * The whole configuration.
     *
     * @return array<array-key, mixed> every top-level key and what it holds
     * @throws NotLoadedError when `load()` has not run
     */
    public static function all(): array
    {
        return self::$config ?? throw NotLoadedError::create();
    }

    /**
     * Whether `load()` has stored a configuration.
     *
     * @return bool true once a load succeeded, until `unload()`
     */
    public static function isLoaded(): bool
    {
        return self::$config !== null;
    }

    /**
     * What the last successful `load()` did -- which document, and
     * whether the compiled cache served or stored it.
     *
     * @return LoadReport the report
     * @throws NotLoadedError when `load()` has not run
     */
    public static function report(): LoadReport
    {
        return self::$report ?? throw NotLoadedError::create();
    }

    /**
     * Forgets the configuration, so the next read is a `NotLoadedError`
     * until `load()` runs again. For tests, and for a long-running worker
     * that wants a clean slate before reloading.
     */
    public static function unload(): void
    {
        self::$config = null;
        self::$report = null;
    }

    /**
     * @return array{0: array<array-key, mixed>, 1: LoadReport}
     * @throws ConfigError
     */
    private static function loadDocument(string $file, string $root, Options $opts): array
    {
        $cacheFile = null;
        if ($opts->compiledCache !== false) {
            $dir = is_string($opts->compiledCache)
                ? self::absolute($opts->compiledCache, $root)
                : CompiledCache::defaultDirectory($root);
            $cacheFile = CompiledCache::fileFor($dir, $file, $root, $opts->modules, $opts->tags);
            $cached = CompiledCache::read($cacheFile, static fn (): array => Dotenv::env($opts->cooper));
            if ($cached !== null) {
                return [$cached, new LoadReport($file, true, true, $cacheFile)];
            }
        }

        // A resolver's answer has nothing a compiled file could check, so
        // a load that asked one is not compiled. Wrapping every resolver
        // finds out exactly; resolution is never cached by Cooper, so a
        // resolver the document uses is always called.
        $resolverCalled = false;
        $cooper = $opts->cooper;
        foreach ($opts->resolvers as $name => $resolver) {
            if (is_callable($resolver)) {
                $cooper['resolvers'][$name] = static function (string $payload) use ($resolver, &$resolverCalled): mixed {
                    $resolverCalled = true;

                    return $resolver($payload);
                };
            }
        }

        try {
            $trace = Cooper::loadFileTraced($file, $cooper);
        } catch (CooperError $e) {
            throw ConfigError::fromCooper($file, $e);
        }

        try {
            $plan = Convert::plan($trace->config, $opts->modules);
        } catch (ConversionError $e) {
            throw ConfigError::fromConversion($file, $e);
        }
        try {
            $config = Convert::materialize($plan);
        } catch (\Throwable $e) {
            throw new ConfigError(
                "failed to load the configuration from {$file}: a cooper-secrets class failed to wrap a secret: {$e->getMessage()}",
                $file,
                $e,
            );
        }
        assert(is_array($config));

        // A scheme import's content has no file to fingerprint at all.
        $written = $cacheFile !== null
            && !$resolverCalled
            && $opts->importSchemes === []
            && CompiledCache::write($cacheFile, $trace, $plan);

        return [$config, new LoadReport($file, true, false, $written ? $cacheFile : null)];
    }

    private static function absolute(string $path, string $root): string
    {
        return str_starts_with($path, '/') || preg_match('#\A[A-Za-z]:[\\\\/]#', $path) === 1
            ? $path
            : rtrim($root, '/') . '/' . $path;
    }

    /**
     * @param string|list<string|int> $path
     * @return array{0: bool, 1: mixed}
     */
    private static function lookup(string|array $path): array
    {
        $value = self::all();
        foreach (self::segments($path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }

    /**
     * `$path` as segments. Typed loosely on purpose: a caller's "list"
     * may arrive with keys (`array_filter()`'s output), and only the
     * order of its values matters.
     *
     * @param string|array<array-key, string|int> $path
     * @return list<string|int>
     */
    private static function segments(string|array $path): array
    {
        $segments = is_string($path) ? explode('.', $path) : $path;
        if ($segments === []) {
            throw new \InvalidArgumentException('a configuration path needs at least one segment');
        }
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException('a configuration path has an empty segment: ' . json_encode($path));
            }
        }

        return array_values($segments);
    }
}
