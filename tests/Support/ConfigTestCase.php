<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Support;

use JOetjen\CooperConfig\Config;
use PHPUnit\Framework\TestCase;

/**
 * Shared helpers: a scratch project root per test, the configuration
 * forgotten before and after every test (it is process-wide state), and
 * real environment variables put back as they were.
 *
 * `loadProject()` keeps `.env` loading and the compiled cache off unless
 * a test asks for them, so neither the machine's own environment files
 * nor a cache file written by an earlier test can leak into a result.
 */
abstract class ConfigTestCase extends TestCase
{
    protected const HEADER = "#@version = 1.0\n";

    /** @var list<string> */
    private array $scratchDirs = [];

    /** @var array<string, string|false> real variables a test changed => what they were */
    private array $savedEnv = [];

    private string $originalCwd = '';

    protected function setUp(): void
    {
        parent::setUp();
        Config::unload();
        $this->originalCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        Config::unload();
        chdir($this->originalCwd);
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        $this->savedEnv = [];
        foreach ($this->scratchDirs as $dir) {
            self::removeTree($dir);
        }
        $this->scratchDirs = [];
        parent::tearDown();
    }

    /**
     * A fresh project root holding `$files` (relative path => content),
     * every file and directory dated an hour ago -- old enough that the
     * compiled cache never sees it as just written (see `CompiledCache`).
     *
     * @param array<string, string> $files
     */
    protected function project(array $files = []): string
    {
        $dir = sys_get_temp_dir() . '/cooper_config_test_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $dir = (string) realpath($dir);
        $this->scratchDirs[] = $dir;
        foreach ($files as $path => $content) {
            $this->write($dir, $path, $content);
        }

        return $dir;
    }

    /**
     * Writes one file under `$root`, dated `$age` seconds ago, and dates
     * its directory the same, so a test controls every modification time
     * the compiled cache fingerprints.
     */
    protected function write(string $root, string $path, string $content, int $age = 3600): string
    {
        $file = "{$root}/{$path}";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
        touch($file, time() - $age);
        touch(dirname($file), time() - $age);

        return $file;
    }

    /**
     * `Config::load()` for `$root`, `.env` files and the compiled cache
     * off unless `$options` says otherwise.
     *
     * @param array<string, mixed> $options
     */
    protected static function loadProject(string $root, array $options = []): void
    {
        Config::load($options + ['root' => $root, 'dotenv' => false, 'compiledCache' => false]);
    }

    /**
     * Sets (or, with `null`, removes) a real environment variable for
     * this test only.
     */
    protected function systemEnv(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->savedEnv)) {
            $this->savedEnv[$name] = getenv($name);
        }
        putenv($value === null ? $name : "{$name}={$value}");
    }

    protected static function casc(string $body): string
    {
        return self::HEADER . $body;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
