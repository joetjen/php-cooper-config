<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Command;

use JOetjen\CooperConfig\Command\CacheClearCommand;
use JOetjen\CooperConfig\Tests\Support\ConfigTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `cooper:cache:clear`, driven through Symfony Console's `CommandTester`.
 */
final class CacheClearCommandTest extends ConfigTestCase
{
    /**
     * @param array<string, string> $input
     * @return array{0: int, 1: string} exit status, stdout
     */
    private static function execute(CacheClearCommand $command, array $input = []): array
    {
        $tester = new CommandTester($command);
        $status = $tester->execute($input, ['capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay()];
    }

    public function testItIsNamedCooperCacheClear(): void
    {
        self::assertSame('cooper:cache:clear', (new CacheClearCommand())->getName());
    }

    public function testCacheClearRemovesTheCompiledFiles(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);
        self::loadProject($root, ['compiledCache' => true]);

        [$status, $out] = self::execute(new CacheClearCommand(), ['--root' => $root]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('removed 1 compiled', $out);
        self::assertSame([], glob("{$root}/var/cache/cooper-config/*") ?: []);
    }

    public function testCacheClearTakesADirectory(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);
        $dir = $this->project();
        self::loadProject($root, ['compiledCache' => $dir]);

        [$status] = self::execute(new CacheClearCommand(), ['--cache-dir' => $dir]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], glob("{$dir}/*") ?: []);
    }

    public function testTheRootAndCacheDirectoryCanBeGivenThroughTheConstructor(): void
    {
        // A framework keeps its caches under its own directory
        // (`var/cache/<env>`, `storage/framework/cache`).
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);
        $dir = $this->project();
        self::loadProject($root, ['compiledCache' => $dir]);

        [$status, $out] = self::execute(new CacheClearCommand($root, $dir));

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString($dir, $out);
        self::assertSame([], glob("{$dir}/*") ?: []);
    }

    public function testWithAnInjectedRootTheDefaultDirectoryIsUnderIt(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);
        self::loadProject($root, ['compiledCache' => true]);

        self::execute(new CacheClearCommand($root));

        self::assertSame([], glob("{$root}/var/cache/cooper-config/*") ?: []);
    }
}
