<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests;

use JOetjen\CooperConfig\Cli;
use JOetjen\CooperConfig\Tests\Support\ConfigTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `vendor/bin/cooper-config`: the standalone Symfony Console application
 * around the `cooper:*` commands, driven through `Cli::application()`
 * and `Cli::input()` exactly as the script drives them, plus real runs
 * of the script itself. What each command does is tested in
 * `tests/Command/`.
 */
final class CliTest extends ConfigTestCase
{
    /**
     * @param list<string> $args
     * @return array{0: int, 1: string} exit status, output (errors included)
     */
    private static function cli(array $args): array
    {
        $application = Cli::application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $output = new BufferedOutput();

        $status = $application->run(Cli::input(['cooper-config', ...$args]), $output);

        return [$status, $output->fetch()];
    }

    public function testTheCommandsAreRegisteredByTheirCooperNames(): void
    {
        $application = Cli::application();

        foreach (['cooper:init', 'cooper:check', 'cooper:cache:clear'] as $name) {
            self::assertTrue($application->has($name), $name);
        }
    }

    public function testTheOldCommandNamesStillWork(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);

        [$status, $out] = self::cli(['check', "--root={$root}"]);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString("loaded {$root}/config/config.casc", $out);

        [$status, $out] = self::cli(['cache:clear', "--root={$root}"]);
        self::assertSame(0, $status, $out);
        self::assertStringContainsString('removed 0 compiled', $out);

        $fresh = $this->project();
        [$status, $out] = self::cli(['init', "--root={$fresh}"]);
        self::assertSame(0, $status, $out);
        self::assertFileExists("{$fresh}/config/config.casc");
    }

    public function testTheOptionsTakeASeparateValueToo(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);

        [$status, $out] = self::cli(['cooper:check', '--root', $root]);

        self::assertSame(0, $status, $out);
    }

    public function testHelpListsTheCommands(): void
    {
        // `help` alone listed the commands before this was a Symfony
        // Console application, and still does.
        foreach ([[], ['help'], ['--help'], ['-h'], ['list']] as $args) {
            [$status, $out] = self::cli($args);

            self::assertSame(0, $status, implode(' ', $args));
            foreach (['cooper:init', 'cooper:check', 'cooper:cache:clear'] as $command) {
                self::assertStringContainsString($command, $out, implode(' ', $args));
            }
        }
    }

    public function testHelpForOneCommandDescribesIt(): void
    {
        [$status, $out] = self::cli(['help', 'check']);

        self::assertSame(0, $status);
        self::assertStringContainsString('--path', $out);
    }

    public function testAnUnknownCommandOrOptionFails(): void
    {
        [$status, $out] = self::cli(['frobnicate']);
        self::assertSame(1, $status);
        self::assertStringContainsString('frobnicate', $out);

        [$status, $out] = self::cli(['check', '--rot=x']);
        self::assertSame(1, $status);
        self::assertStringContainsString('--rot', $out);
    }

    public function testTheScriptRuns(): void
    {
        $root = $this->project();
        $script = dirname(__DIR__) . '/bin/cooper-config';

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' init --root=' . escapeshellarg($root) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertFileExists("{$root}/config/config.casc");
    }

    public function testTheScriptExitsNonZeroOnFailure(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = = 1\n")]);
        $script = dirname(__DIR__) . '/bin/cooper-config';

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' cooper:check --root=' . escapeshellarg($root) . ' 2>&1', $output, $status);

        self::assertSame(1, $status, implode("\n", $output));
    }
}
