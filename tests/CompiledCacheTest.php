<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests;

use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\CooperConfig\CompiledCache;
use JOetjen\CooperConfig\Config;
use JOetjen\CooperConfig\ConfigError;
use JOetjen\CooperConfig\Tests\Support\ConfigTestCase;
use JOetjen\CooperConfig\Tests\Support\Level;
use JOetjen\CooperConfig\Tests\Support\OwnedSecret;

/**
 * The compiled cache: PHP is shared-nothing, so an eagerly loaded
 * configuration would otherwise be re-parsed on every request. After a
 * successful load the converted configuration is written as a PHP file
 * opcache can serve, and used for as long as everything it was built
 * from is unchanged.
 *
 * "Built from" is fingerprinted with modification times, so every test
 * dates what it writes (`write()`'s `$age`) rather than relying on two
 * writes landing in different seconds.
 */
final class CompiledCacheTest extends ConfigTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    private static function loadCached(string $root, array $options = []): void
    {
        self::loadProject($root, $options + ['compiledCache' => true]);
    }

    /**
     * @return list<string>
     */
    private static function cacheFiles(string $dir): array
    {
        return glob("{$dir}/*") ?: [];
    }

    public function testASuccessfulLoadWritesTheCompiledFileUnderVarCache(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("app.port = 8080\n")]);

        self::loadCached($root);

        $files = self::cacheFiles("{$root}/var/cache/cooper-config");
        self::assertCount(1, $files);
        self::assertSame($files[0], Config::report()->cacheFile);
        self::assertStringStartsWith('<?php', (string) file_get_contents($files[0]));
        self::assertFalse(Config::report()->fromCache);
    }

    public function testTheCompiledFileIsReadableByItsOwnerOnly(): void
    {
        // It can hold revealed secrets.
        $root = $this->project(['config/config.casc' => self::casc("app.*password = \"hunter2\"\n")]);

        self::loadCached($root);

        $file = (string) Config::report()->cacheFile;
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertSame(0700, fileperms(dirname($file)) & 0777);
    }

    public function testTheCacheIsOnByDefault(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);

        Config::load(['root' => $root, 'dotenv' => false]);

        self::assertCount(1, self::cacheFiles("{$root}/var/cache/cooper-config"));
    }

    public function testTheNextLoadIsServedFromTheCompiledFileWithoutReadingTheDocument(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = \"one\"\n")]);
        self::loadCached($root);

        // Same modification time and size, different content: only a
        // load that never opened the document still sees "one".
        $file = "{$root}/config/config.casc";
        $mtime = (int) filemtime($file);
        file_put_contents($file, self::casc("v = \"two\"\n"));
        touch($file, $mtime);
        self::loadCached($root);

        self::assertTrue(Config::report()->fromCache);
        self::assertSame(['v' => 'one'], Config::all());
    }

    public function testAChangedDocumentIsReloaded(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = 1\n")]);
        self::loadCached($root);

        $this->write($root, 'config/config.casc', self::casc("v = 2\n"), 1800);
        self::loadCached($root);

        self::assertFalse(Config::report()->fromCache);
        self::assertSame(['v' => 2], Config::all());
    }

    public function testAChangedImportIsReloaded(): void
    {
        $root = $this->project([
            'config/config.casc' => self::casc("import \"inc/db.casc\"\n"),
            'config/inc/db.casc' => self::casc("db = 1\n"),
        ]);
        self::loadCached($root);

        $this->write($root, 'config/inc/db.casc', self::casc("db = 2\n"), 1800);
        self::loadCached($root);

        self::assertSame(['db' => 2], Config::all());
    }

    public function testAFileAddedWhereAGlobLooksIsSeen(): void
    {
        // Nothing the load read changed, but what the pattern matches did.
        $root = $this->project([
            'config/config.casc' => self::casc("import \"env/*.casc\"\n"),
            'config/env/a.casc' => self::casc("a = 1\n"),
        ]);
        // Creating `env/` dated `config/` now; a compiled file is written
        // only once nothing it records is that fresh.
        touch("{$root}/config", time() - 3600);
        self::loadCached($root);
        self::assertNotNull(Config::report()->cacheFile);

        $this->write($root, 'config/env/b.casc', self::casc("b = 2\n"), 1800);
        self::loadCached($root);

        self::assertSame(['a' => 1, 'b' => 2], Config::all());
    }

    public function testAFileAddedInADirectoryTheLoadNeverReadFromIsSeen(): void
    {
        // `env/late/` held no file when the document was compiled, so no
        // modification time of anything the load read changes when one
        // lands there: only expanding the pattern again can see it.
        $root = $this->project([
            'config/config.casc' => self::casc("import \"env/**/*.casc\"\n"),
            'config/env/a.casc' => self::casc("a = 1\n"),
        ]);
        mkdir("{$root}/config/env/late");
        touch("{$root}/config/env/late", time() - 3600);
        touch("{$root}/config/env", time() - 3600);
        touch("{$root}/config", time() - 3600);
        self::loadCached($root);
        self::assertNotNull(Config::report()->cacheFile);

        $this->write($root, 'config/env/late/b.casc', self::casc("b = 2\n"), 1800);
        self::loadCached($root);

        self::assertFalse(Config::report()->fromCache);
        self::assertSame(['a' => 1, 'b' => 2], Config::all());
    }

    public function testAFileTheGlobDoesNotMatchKeepsTheCompiledFile(): void
    {
        $root = $this->project([
            'config/config.casc' => self::casc("import \"env/*.casc\"\n"),
            'config/env/a.casc' => self::casc("a = 1\n"),
        ]);
        touch("{$root}/config", time() - 3600);
        self::loadCached($root);
        self::assertNotNull(Config::report()->cacheFile);

        $this->write($root, 'config/env/notes.txt', 'not casc', 1800);
        self::loadCached($root);

        self::assertTrue(Config::report()->fromCache);
    }

    public function testAChangedVariableTheDocumentReadIsReloaded(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = \${CC_VALUE}\n")]);
        self::loadCached($root, ['env' => ['CC_VALUE' => 'one']]);

        self::loadCached($root, ['env' => ['CC_VALUE' => 'two']]);

        self::assertFalse(Config::report()->fromCache);
        self::assertSame(['v' => 'two'], Config::all());
    }

    public function testAVariableSetOrUnsetForAGuardIsSeen(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("\${?CC_GUARD} guarded = true\nkept = 1\n")]);
        $this->systemEnv('CC_GUARD', null);
        self::loadCached($root);

        $this->systemEnv('CC_GUARD', 'on');
        self::loadCached($root);

        self::assertSame(['guarded' => true, 'kept' => 1], Config::all());
    }

    public function testAnUnchangedVariableKeepsTheCompiledFile(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = \${CC_VALUE}\n")]);
        self::loadCached($root, ['env' => ['CC_VALUE' => 'same']]);

        self::loadCached($root, ['env' => ['CC_VALUE' => 'same']]);

        self::assertTrue(Config::report()->fromCache);
    }

    public function testAppEnvChangingTheFallbackCooperEnvIsSeen(): void
    {
        $root = $this->project([
            'config/config.casc' => self::casc("import \"\${COOPER_ENV}/*.casc\"\n"),
            'config/dev/app.casc' => self::casc("env = \"dev\"\n"),
            'config/prod/app.casc' => self::casc("env = \"prod\"\n"),
        ]);
        self::loadCached($root, ['env' => ['COOPER_ENV' => '', 'APP_ENV' => 'dev']]);

        self::loadCached($root, ['env' => ['COOPER_ENV' => '', 'APP_ENV' => 'prod']]);

        self::assertSame(['env' => 'prod'], Config::all());
    }

    public function testVariableValuesAreNotStoredInClear(): void
    {
        // A guard's value never reaches the configuration, so it must not
        // reach the file either -- only a salted hash of it does.
        $root = $this->project(['config/config.casc' => self::casc("\${?CC_GUARD} on = true\n")]);

        self::loadCached($root, ['env' => ['CC_GUARD' => 'sekrit-guard-value']]);

        $source = (string) file_get_contents((string) Config::report()->cacheFile);
        self::assertStringContainsString('CC_GUARD', $source);
        self::assertStringNotContainsString('sekrit-guard-value', $source);
    }

    public function testAPathThatLooksLikePhpCannotBreakTheCompiledFile(): void
    {
        // The document's path is written into a comment in the file; a
        // closing tag or a line break in it must stay data.
        $outer = $this->project();
        $root = "{$outer}/odd ?> name\n<?php exit;";
        mkdir($root);
        $this->write($root, 'config/config.casc', self::casc("v = 1\n"));
        touch($root, time() - 3600);

        self::loadCached($root);
        self::loadCached($root);

        self::assertTrue(Config::report()->fromCache);
        self::assertSame(['v' => 1], Config::all());
    }

    public function testOffWritesNothing(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);

        self::loadProject($root, ['compiledCache' => false]);

        self::assertDirectoryDoesNotExist("{$root}/var");
        self::assertNull(Config::report()->cacheFile);
    }

    public function testADirectoryOptionPutsTheFileThere(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);
        $dir = $this->project() . '/compiled';

        self::loadCached($root, ['compiledCache' => $dir]);

        self::assertCount(1, self::cacheFiles($dir));
        self::assertDirectoryDoesNotExist("{$root}/var");
    }

    public function testEveryValueSurvivesTheCompiledFile(): void
    {
        $root = $this->project(['config/config.casc' => self::casc(<<<'CASC'
            plain {
              *password = "hunter2"
              max = 1GiB
              ttl = 5s
              net = 10.0.0.0/8
              when = 1979-05-27T07:32:00.120Z
              level = !level("high")
              pair = (1, 2)
            }
            kept {
              cooper-secrets = keep
              *password = "kept"
            }
            owned {
              cooper-secrets = "JOetjen.CooperConfig.Tests.Support.OwnedSecret"
              *password = "owned"
            }
            CASC)]);
        $options = ['tags' => ['level' => static fn (mixed $name): Level => Level::from((string) $name)]];
        self::loadCached($root, $options);
        $fresh = Config::all();

        self::loadCached($root, $options);

        self::assertTrue(Config::report()->fromCache);
        self::assertEquals($fresh, Config::all());
        self::assertSame(Level::High, Config::get('plain.level'));
        self::assertInstanceOf(CooperDateTime::class, Config::get('plain.when'));
        self::assertInstanceOf(CooperSecret::class, Config::get('kept.password'));
        self::assertSame('kept', Config::get('kept.password')->reveal());
        self::assertInstanceOf(OwnedSecret::class, Config::get('owned.password'));
        self::assertSame('owned', Config::get('owned.password')->value);
    }

    public function testALoadThatCalledAResolverIsNotCompiled(): void
    {
        // A resolver's answer has nothing to fingerprint: it would be
        // frozen until a file changed.
        $root = $this->project(['config/config.casc' => self::casc("v = !{echo:hi}\n")]);

        self::loadCached($root, ['resolvers' => ['echo' => static fn (string $p): string => $p]]);

        self::assertSame(['v' => 'hi'], Config::all());
        self::assertNull(Config::report()->cacheFile);
        self::assertSame([], self::cacheFiles("{$root}/var/cache/cooper-config"));
    }

    public function testAResolverThatWasNeverCalledDoesNotPreventCompiling(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = 1\n")]);

        self::loadCached($root, ['resolvers' => ['echo' => static fn (string $p): string => $p]]);

        self::assertNotNull(Config::report()->cacheFile);
    }

    public function testALoadWithImportSchemesIsNotCompiled(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("import \"mem://x\"\n")]);

        self::loadCached($root, ['importSchemes' => ['mem' => static fn (string $rest): string => self::casc("{$rest} = 1\n")]]);

        self::assertSame(['x' => 1], Config::all());
        self::assertNull(Config::report()->cacheFile);
    }

    public function testAValueThatCannotBeWrittenAsPhpLoadsButIsNotCompiled(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = !obj(1)\n")]);

        self::loadCached($root, ['tags' => ['obj' => static fn (mixed $v): \ArrayObject => new \ArrayObject([$v])]]);

        self::assertInstanceOf(\ArrayObject::class, Config::get('v'));
        self::assertNull(Config::report()->cacheFile);
    }

    public function testADocumentWrittenThisVerySecondIsNotCompiledYet(): void
    {
        // A second edit within the same second would keep the modification
        // time it is fingerprinted by; the next load compiles it instead.
        $root = $this->project();
        $this->write($root, 'config/config.casc', self::casc("v = 1\n"), 0);

        self::loadCached($root);

        self::assertNull(Config::report()->cacheFile);
    }

    public function testACorruptCompiledFileIsIgnoredAndReplaced(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = 1\n")]);
        self::loadCached($root);
        $file = (string) Config::report()->cacheFile;
        file_put_contents($file, '<?php return "not a cache";');

        self::loadCached($root);

        self::assertFalse(Config::report()->fromCache);
        self::assertSame(['v' => 1], Config::all());
        self::loadCached($root);
        self::assertTrue(Config::report()->fromCache);
    }

    public function testACompiledFileOthersCouldHaveWrittenIsNeverIncluded(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = 1\n")]);
        self::loadCached($root);
        $file = (string) Config::report()->cacheFile;
        chmod($file, 0666);

        self::loadCached($root);

        self::assertFalse(Config::report()->fromCache);
        self::assertSame(0600, fileperms($file) & 0777);
    }

    public function testTheWriteLeavesNoTemporaryFileBehind(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = 1\n")]);

        self::loadCached($root);
        $this->write($root, 'config/config.casc', self::casc("v = 2\n"), 1800);
        self::loadCached($root);

        self::assertCount(1, self::cacheFiles("{$root}/var/cache/cooper-config"));
    }

    public function testAFailedLoadWritesNothing(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("v = = 1\n")]);

        try {
            self::loadCached($root);
            self::fail('a broken document loaded');
        } catch (ConfigError) {
        }

        self::assertSame([], self::cacheFiles("{$root}/var/cache/cooper-config"));
    }

    public function testAMissingDefaultFileWritesNothing(): void
    {
        $root = $this->project();

        self::loadCached($root);

        self::assertDirectoryDoesNotExist("{$root}/var");
    }

    public function testDifferentDocumentsGetDifferentFiles(): void
    {
        $root = $this->project([
            'config/config.casc' => self::casc("which = \"main\"\n"),
            'config/other.casc' => self::casc("which = \"other\"\n"),
        ]);

        self::loadCached($root);
        self::loadCached($root, ['path' => 'config/other.casc']);
        self::loadCached($root);

        self::assertTrue(Config::report()->fromCache);
        self::assertSame(['which' => 'main'], Config::all());
        self::assertCount(2, self::cacheFiles("{$root}/var/cache/cooper-config"));
    }

    public function testDifferentModulesMappingsGetDifferentFiles(): void
    {
        // `!module` answers are baked into the file, so the mapping is
        // part of what identifies it.
        $root = $this->project(['config/config.casc' => self::casc("m = !module(\"Store\")\n")]);

        self::loadCached($root, ['modules' => ['Store' => 'A\\Store']]);
        self::loadCached($root, ['modules' => ['Store' => 'B\\Store']]);

        self::assertSame('B\\Store', Config::get('m'));
    }

    public function testClearRemovesEveryCompiledFile(): void
    {
        $root = $this->project([
            'config/config.casc' => self::casc("a = 1\n"),
            'config/other.casc' => self::casc("b = 1\n"),
        ]);
        self::loadCached($root);
        self::loadCached($root, ['path' => 'config/other.casc']);
        file_put_contents("{$root}/var/cache/cooper-config/unrelated.txt", 'kept');

        self::assertSame(2, CompiledCache::clear("{$root}/var/cache/cooper-config"));

        self::assertSame(["{$root}/var/cache/cooper-config/unrelated.txt"], self::cacheFiles("{$root}/var/cache/cooper-config"));
    }

    public function testClearingAMissingDirectoryRemovesNothing(): void
    {
        self::assertSame(0, CompiledCache::clear('/nonexistent/cooper-config-cache'));
    }
}
