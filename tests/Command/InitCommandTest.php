<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Command;

use JOetjen\CooperConfig\Command\InitCommand;
use JOetjen\CooperConfig\Config;
use JOetjen\CooperConfig\Tests\Support\ConfigTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `cooper:init`, driven through Symfony Console's `CommandTester` --
 * the way `bin/console` or artisan would run it.
 */
final class InitCommandTest extends ConfigTestCase
{
    /**
     * @param array<string, string> $input
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private static function execute(InitCommand $command, array $input = []): array
    {
        $tester = new CommandTester($command);
        $status = $tester->execute($input, ['capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    public function testItIsNamedCooperInit(): void
    {
        self::assertSame('cooper:init', (new InitCommand())->getName());
    }

    public function testInitScaffoldsTheDocumentAndOneDirectoryPerEnvironment(): void
    {
        $root = $this->project();

        [$status, $out] = self::execute(new InitCommand(), ['--root' => $root]);

        self::assertSame(Command::SUCCESS, $status);
        $main = (string) file_get_contents("{$root}/config/config.casc");
        self::assertStringStartsWith('#@version = 1.0', $main);
        self::assertStringContainsString('# ', $main);
        $lines = array_values(array_filter(explode("\n", $main), static fn (string $l): bool => trim($l) !== ''));
        self::assertSame('import "${COOPER_ENV}/*.casc"', end($lines));
        foreach (['dev', 'test', 'prod'] as $env) {
            self::assertCount(1, glob("{$root}/config/{$env}/*.casc") ?: [], $env);
        }
        self::assertStringContainsString('config/config.casc', $out);
    }

    public function testTheProjectRootCanBeGivenThroughTheConstructor(): void
    {
        // A framework knows its project root; the command must not have
        // to guess it, nor need `--root` on every run.
        $root = $this->project();

        [$status] = self::execute(new InitCommand($root));

        self::assertSame(Command::SUCCESS, $status);
        self::assertFileExists("{$root}/config/config.casc");
    }

    public function testTheRootOptionOutranksTheConstructor(): void
    {
        $injected = $this->project();
        $given = $this->project();

        self::execute(new InitCommand($injected), ['--root' => $given]);

        self::assertFileExists("{$given}/config/config.casc");
        self::assertFileDoesNotExist("{$injected}/config/config.casc");
    }

    public function testTheScaffoldLoadsInEveryEnvironment(): void
    {
        $root = $this->project();
        self::execute(new InitCommand($root));

        foreach (['dev', 'test', 'prod'] as $env) {
            self::loadProject($root, ['env' => ['COOPER_ENV' => $env]]);
            self::assertSame([], Config::all(), $env);
        }
    }

    public function testInitRefusesToOverwriteAnything(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("mine = 1\n")]);

        [$status, , $err] = self::execute(new InitCommand($root));

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('config/config.casc', $err);
        self::assertSame(self::casc("mine = 1\n"), file_get_contents("{$root}/config/config.casc"));
        self::assertDirectoryDoesNotExist("{$root}/config/dev");
    }

    public function testARootThatIsNotADirectoryFailsWithTheReason(): void
    {
        [$status, , $err] = self::execute(new InitCommand(), ['--root' => '/does/not/exist']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('--root is not a directory: /does/not/exist', $err);
    }
}
