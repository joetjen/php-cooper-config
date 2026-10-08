<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests;

use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\CooperConfig\Config;
use JOetjen\CooperConfig\ConfigError;
use JOetjen\CooperConfig\MissingKeyError;
use JOetjen\CooperConfig\NotLoadedError;
use JOetjen\CooperConfig\ProjectRoot;
use JOetjen\CooperConfig\Tests\Support\ConfigTestCase;

/**
 * `Config`: the eager lifecycle (loaded once, at startup; read
 * afterwards, never loaded on first access), the default file and root,
 * the options, and the accessors.
 */
final class ConfigTest extends ConfigTestCase
{
    // ---- the lifecycle ------------------------------------------------------

    public function testReadingBeforeLoadingIsAnErrorThatSaysHowToLoad(): void
    {
        $reads = [
            'get' => static fn (): mixed => Config::get('a'),
            'require' => static fn (): mixed => Config::require('a'),
            'has' => static fn (): mixed => Config::has('a'),
            'all' => static fn (): mixed => Config::all(),
            'cooper_config' => static fn (): mixed => cooper_config(),
        ];

        foreach ($reads as $name => $read) {
            try {
                $read();
                self::fail("{$name} read a configuration that was never loaded");
            } catch (NotLoadedError $e) {
                self::assertStringContainsString('Config::load()', $e->getMessage(), $name);
                self::assertStringContainsString('startup', $e->getMessage(), $name);
            }
        }
    }

    public function testLoadReadsConfigCascUnderTheRoot(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("app.port = 8080\n")]);

        self::loadProject($root);

        self::assertTrue(Config::isLoaded());
        self::assertSame(['app' => ['port' => 8080]], Config::all());
        self::assertSame("{$root}/config/config.casc", Config::report()->file);
    }

    public function testTheWholeConfigurationIsReadAtLoadAndNeverAgainOnAccess(): void
    {
        // Eager only: a change on disk is not seen until the next load.
        $root = $this->project(['config/config.casc' => self::casc("v = 1\n")]);
        self::loadProject($root);

        $this->write($root, 'config/config.casc', self::casc("v = 2\n"), 0);

        self::assertSame(1, Config::get('v'));
    }

    public function testLoadingAgainReplacesTheConfiguration(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);
        self::loadProject($root);
        $this->write($root, 'config/config.casc', self::casc("b = 2\n"), 1800);

        self::loadProject($root);

        self::assertSame(['b' => 2], Config::all());
    }

    public function testAFailedLoadStoresNothing(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);
        self::loadProject($root);
        $this->write($root, 'config/config.casc', self::casc("a = = 1\n"), 1800);

        try {
            self::loadProject($root);
            self::fail('a broken document loaded');
        } catch (ConfigError) {
        }

        self::assertSame(['a' => 1], Config::all());
    }

    public function testAFailedFirstLoadLeavesTheConfigurationUnloaded(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = = 1\n")]);

        try {
            self::loadProject($root);
            self::fail('a broken document loaded');
        } catch (ConfigError) {
        }

        self::assertFalse(Config::isLoaded());
    }

    public function testUnloadForgetsTheConfiguration(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);
        self::loadProject($root);

        Config::unload();

        self::assertFalse(Config::isLoaded());
        $this->expectException(NotLoadedError::class);
        Config::all();
    }

    // ---- which file ---------------------------------------------------------

    public function testAMissingDefaultFileLoadsAnEmptyConfiguration(): void
    {
        // A project that has no configuration yet is not one that should
        // refuse to start.
        $root = $this->project();

        self::loadProject($root);

        self::assertSame([], Config::all());
        self::assertFalse(Config::report()->found);
    }

    public function testAMissingExplicitPathIsAnErrorNamingIt(): void
    {
        $root = $this->project();

        try {
            self::loadProject($root, ['path' => 'config/app.casc']);
            self::fail('a missing explicit path loaded');
        } catch (ConfigError $e) {
            self::assertSame("{$root}/config/app.casc", $e->path);
            self::assertStringContainsString("{$root}/config/app.casc", $e->getMessage());
        }
        self::assertFalse(Config::isLoaded());
    }

    public function testARelativePathResolvesAgainstTheRoot(): void
    {
        $root = $this->project(['etc/app.casc' => self::casc("from = \"etc\"\n")]);

        self::loadProject($root, ['path' => 'etc/app.casc']);

        self::assertSame(['from' => 'etc'], Config::all());
    }

    public function testAnAbsolutePathIsUsedAsGiven(): void
    {
        $elsewhere = $this->project(['app.casc' => self::casc("from = \"elsewhere\"\n")]);
        $root = $this->project();

        self::loadProject($root, ['path' => "{$elsewhere}/app.casc"]);

        self::assertSame(['from' => 'elsewhere'], Config::all());
    }

    public function testTheDefaultRootIsTheComposerRootPackagesDirectory(): void
    {
        // In this package's own suite, the root package is this package.
        self::assertSame((string) realpath(dirname(__DIR__)), ProjectRoot::detect());
    }

    public function testARootThatIsNotADirectoryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('root');

        Config::load(['root' => '/nonexistent/cooper-config-root', 'compiledCache' => false]);
    }

    // ---- errors -------------------------------------------------------------

    public function testALoadErrorCarriesCoopersFormattedErrorAndThePath(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = = 1\n")]);

        try {
            self::loadProject($root);
            self::fail('a broken document loaded');
        } catch (ConfigError $e) {
            $cooper = $e->getPrevious();
            self::assertInstanceOf(CooperError::class, $cooper);
            self::assertSame("{$root}/config/config.casc", $e->path);
            self::assertStringContainsString("{$root}/config/config.casc", $e->getMessage());
            self::assertStringContainsString($cooper->getMessage(), $e->getMessage());
            self::assertStringContainsString($cooper->stage, $e->getMessage());
        }
    }

    public function testAConversionFailureIsALoadError(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("app.timeout = 1500us\n")]);

        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('1500000ns');

        self::loadProject($root);
    }

    // ---- options ------------------------------------------------------------

    public function testUnknownOptionsAreAnErrorListingTheSupportedOnes(): void
    {
        try {
            Config::load(['pth' => 'x', 'compiledCache' => false]);
            self::fail('an unknown option was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('pth', $e->getMessage());
            foreach (['path', 'root', 'compiledCache', 'env', 'dotenv', 'dotenvEnv', 'dotenvFiles', 'dotenvDir', 'dotenvOverride', 'resolvers', 'tags', 'importSchemes', 'modules', 'cache'] as $known) {
                self::assertStringContainsString($known, $e->getMessage());
            }
        }
    }

    public function testMistypedOwnOptionsAreRefused(): void
    {
        foreach ([['path' => 1], ['root' => false], ['compiledCache' => 3]] as $options) {
            try {
                Config::load($options);
                self::fail('accepted ' . json_encode($options));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString((string) array_key_first($options), $e->getMessage());
            }
        }
    }

    public function testEveryCooperLoadOptionIsPassedThrough(): void
    {
        $root = $this->project(['config/config.casc' => self::casc(<<<'CASC'
            import "mem://extra"
            env = ${CC_OPTION}
            tagged = !twice(21)
            resolved = !{echo:hello}
            store = !module("Store")
            CASC)]);

        self::loadProject($root, [
            'env' => ['CC_OPTION' => 'from-env-option'],
            'tags' => ['twice' => static fn (mixed $v): int => is_int($v) ? $v * 2 : 0],
            'resolvers' => ['echo' => static fn (string $payload): string => $payload],
            'modules' => ['Store' => \ArrayObject::class],
            'importSchemes' => ['mem' => static fn (string $rest): string => self::casc("{$rest} = true\n")],
            'cache' => false,
            'dotenvEnv' => null,
            'dotenvFiles' => [],
            'dotenvOverride' => false,
        ]);

        self::assertSame(
            ['extra' => true, 'env' => 'from-env-option', 'tagged' => 42, 'resolved' => 'hello', 'store' => \ArrayObject::class],
            Config::all(),
        );
    }

    public function testCooperEnvSelectsTheEnvironmentsDirectory(): void
    {
        $root = $this->project([
            'config/config.casc' => self::casc("name = \"base\"\nimport \"\${COOPER_ENV}/*.casc\"\n"),
            'config/dev/app.casc' => self::casc("name = \"dev\"\n"),
            'config/prod/app.casc' => self::casc("name = \"prod\"\n"),
        ]);

        self::loadProject($root, ['env' => ['COOPER_ENV' => 'prod']]);
        self::assertSame('prod', Config::get('name'));

        self::loadProject($root, ['env' => ['COOPER_ENV' => '', 'APP_ENV' => 'dev']]);
        self::assertSame('dev', Config::get('name'));
    }

    public function testLaravelsAppEnvNamesSelectTheSharedDirectories(): void
    {
        // Cooper maps the APP_ENV fallback onto the names every Cooper
        // uses, so Laravel's own values need no COOPER_ENV of their own.
        $root = $this->project([
            'config/config.casc' => self::casc("name = \"base\"\nimport \"\${COOPER_ENV}/*.casc\"\n"),
            'config/dev/app.casc' => self::casc("name = \"dev\"\n"),
            'config/test/app.casc' => self::casc("name = \"test\"\n"),
            'config/prod/app.casc' => self::casc("name = \"prod\"\n"),
        ]);

        foreach (['local' => 'dev', 'testing' => 'test', 'production' => 'prod'] as $appEnv => $directory) {
            self::loadProject($root, ['env' => ['COOPER_ENV' => '', 'APP_ENV' => $appEnv]]);
            self::assertSame($directory, Config::get('name'), $appEnv);
        }
    }

    public function testTheEnvFileReadIsTheOneCooperEnvNamesDotEnvProdForAppEnvProduction(): void
    {
        // Every Cooper names the `.env.<env>` file by COOPER_ENV, as the
        // config/<env>/ overlay is named, so Laravel's
        // APP_ENV=production reads `.env.prod`, never `.env.production`.
        $root = $this->project([
            'config/config.casc' => self::casc("v = \${CC_PICKED}\n"),
            '.env.prod' => "CC_PICKED=prod\n",
            '.env.production' => "CC_PICKED=production\n",
        ]);

        Config::load([
            'root' => $root,
            'compiledCache' => false,
            'dotenvDir' => $root,
            'env' => ['COOPER_ENV' => '', 'APP_ENV' => 'production'],
        ]);

        self::assertSame('prod', Config::get('v'));
    }

    public function testDotenvDirIsPassedThroughToCooper(): void
    {
        // Where `.env` files live is Cooper's business -- by default the
        // project root, wherever the process started; a front controller
        // runs in public/, where no .env lives.
        $root = $this->project([
            'config/config.casc' => self::casc("v = \${CC_DOTENV_ONLY}\n"),
            'env/.env' => "CC_DOTENV_ONLY=from-dotenv-dir\n",
            'public/index.php' => '',
        ]);
        chdir("{$root}/public");

        Config::load(['root' => $root, 'compiledCache' => false, 'dotenvDir' => "{$root}/env"]);

        self::assertSame('from-dotenv-dir', Config::get('v'));
    }

    public function testPhpdotenvIsCoopersDependencySoThisPackageNamesItNowhere(): void
    {
        // `.env` support is part of Cooper, which requires phpdotenv;
        // naming it here too would tell an application it must add it.
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach (['require', 'require-dev', 'suggest'] as $section) {
            self::assertArrayNotHasKey('vlucas/phpdotenv', $composer[$section] ?? [], $section);
        }
    }

    public function testTheLoadRunsInTheWorkingDirectoryItWasCalledFrom(): void
    {
        // Cooper finds `.env` at the project root by itself, so nothing
        // here changes directory -- a resolver sees the caller's.
        $root = $this->project([
            'config/config.casc' => self::casc("cwd = !{cwd:now}\n"),
            'public/index.php' => '',
        ]);
        chdir("{$root}/public");

        self::loadProject($root, ['resolvers' => ['cwd' => static fn (string $payload): string => (string) getcwd()]]);

        self::assertSame("{$root}/public", Config::get('cwd'));
    }

    // ---- reading ------------------------------------------------------------

    private function loadReadable(): void
    {
        $root = $this->project(['config/config.casc' => self::casc(<<<'CASC'
            app {
              port = 8080
              nothing = nil
              hosts = ["a", "b"]
              level = info
            }
            CASC)]);
        self::loadProject($root);
    }

    public function testGetReadsADottedPath(): void
    {
        $this->loadReadable();

        self::assertSame(8080, Config::get('app.port'));
        self::assertSame(['a', 'b'], Config::get('app.hosts'));
        self::assertSame('b', Config::get('app.hosts.1'));
        self::assertEquals(CooperAtom::of('info'), Config::get('app.level'));
    }

    public function testGetReadsAListOfSegments(): void
    {
        $this->loadReadable();

        self::assertSame(8080, Config::get(['app', 'port']));
        self::assertSame('a', Config::get(['app', 'hosts', 0]));
    }

    public function testASegmentListReachesAKeyHoldingADot(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("hosts { \"example.com\" = 1 }\n")]);
        self::loadProject($root);

        self::assertSame(1, Config::get(['hosts', 'example.com']));
        self::assertNull(Config::get('hosts.example.com'));
    }

    public function testGetGivesTheDefaultForAMissingPath(): void
    {
        $this->loadReadable();

        self::assertNull(Config::get('app.missing'));
        self::assertSame('fallback', Config::get('app.missing', 'fallback'));
        self::assertSame('fallback', Config::get('app.port.deeper', 'fallback'));
        self::assertSame('fallback', Config::get('nope.at.all', 'fallback'));
    }

    public function testAPresentNilIsNotMissing(): void
    {
        $this->loadReadable();

        self::assertTrue(Config::has('app.nothing'));
        self::assertNull(Config::get('app.nothing', 'fallback'));
        self::assertNull(Config::require('app.nothing'));
    }

    public function testHasTellsWhetherAPathIsThere(): void
    {
        $this->loadReadable();

        self::assertTrue(Config::has('app'));
        self::assertTrue(Config::has(['app', 'hosts', 1]));
        self::assertFalse(Config::has('app.hosts.2'));
        self::assertFalse(Config::has('app.port.deeper'));
    }

    public function testRequireGivesTheValue(): void
    {
        $this->loadReadable();

        self::assertSame(8080, Config::require('app.port'));
    }

    public function testRequireOfAMissingPathIsAnErrorNamingIt(): void
    {
        $this->loadReadable();

        try {
            Config::require(['app', 'database', 'host']);
            self::fail('a missing path was required');
        } catch (MissingKeyError $e) {
            self::assertSame(['app', 'database', 'host'], $e->segments);
            self::assertStringContainsString('app.database.host', $e->getMessage());
        }
    }

    public function testAnEmptyPathIsRefused(): void
    {
        $this->loadReadable();

        foreach (['', 'app..port', '.app', []] as $path) {
            try {
                Config::get($path);
                self::fail('accepted ' . json_encode($path));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheHelperReadsLikeGet(): void
    {
        $this->loadReadable();

        self::assertSame(Config::all(), cooper_config());
        self::assertSame(8080, cooper_config('app.port'));
        self::assertSame('fallback', cooper_config('app.missing', 'fallback'));
        self::assertSame(8080, cooper_config(['app', 'port']));
    }

    public function testADottedPathAndItsSegmentsReadTheSameForArbitraryTrees(): void
    {
        // Property-style (hand-rolled, seeded, per AGENTS.md): for random
        // nested trees, `get('a.b.c')` is `get(['a','b','c'])` is a
        // manual walk, and `has()` agrees with whether that walk arrives.
        mt_srand(4242);
        $keys = ['a', 'b', 'c', 'port', 'x_1'];

        for ($i = 0; $i < 60; $i++) {
            $tree = self::randomTree($keys, 3);
            $body = self::asCasc($tree, '');
            $root = $this->project(['config/config.casc' => self::casc($body)]);
            self::loadProject($root);
            self::assertSame($tree, Config::all());

            for ($j = 0; $j < 20; $j++) {
                $segments = [];
                for ($k = mt_rand(1, 4); $k > 0; $k--) {
                    $segments[] = $keys[mt_rand(0, count($keys) - 1)];
                }
                [$found, $expected] = self::walk($tree, $segments);

                self::assertSame($found, Config::has($segments));
                self::assertSame($found, Config::has(implode('.', $segments)));
                self::assertSame($found ? $expected : 'missing', Config::get($segments, 'missing'));
                self::assertSame($found ? $expected : 'missing', Config::get(implode('.', $segments), 'missing'));
            }
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function randomTree(array $keys, int $depth): array
    {
        $tree = [];
        foreach ($keys as $key) {
            $roll = mt_rand(0, 3);
            if ($roll === 0) {
                continue;
            }
            $tree[$key] = $roll === 1 && $depth > 0 ? self::randomTree($keys, $depth - 1) : mt_rand(0, 999);
        }

        return $tree === [] ? ['a' => 1] : $tree;
    }

    /**
     * @param array<string, mixed> $tree
     */
    private static function asCasc(array $tree, string $prefix): string
    {
        $out = '';
        foreach ($tree as $key => $value) {
            $out .= is_array($value) ? self::asCasc($value, "{$prefix}{$key}.") : "{$prefix}{$key} = {$value}\n";
        }

        return $out;
    }

    /**
     * @param list<string> $segments
     * @return array{0: bool, 1: mixed}
     */
    private static function walk(mixed $tree, array $segments): array
    {
        foreach ($segments as $segment) {
            if (!is_array($tree) || !array_key_exists($segment, $tree)) {
                return [false, null];
            }
            $tree = $tree[$segment];
        }

        return [true, $tree];
    }
}
