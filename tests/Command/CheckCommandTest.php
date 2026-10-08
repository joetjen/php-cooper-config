<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Command;

use JOetjen\CooperConfig\Command\CheckCommand;
use JOetjen\CooperConfig\Tests\Support\ConfigTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `cooper:check`, driven through Symfony Console's `CommandTester`.
 */
final class CheckCommandTest extends ConfigTestCase
{
    /**
     * @param array<string, string> $input
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private static function execute(CheckCommand $command, array $input = []): array
    {
        $tester = new CommandTester($command);
        $status = $tester->execute($input, ['capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    public function testItIsNamedCooperCheck(): void
    {
        self::assertSame('cooper:check', (new CheckCommand())->getName());
    }

    public function testCheckLoadsAndReports(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("app.port = 1\ndb.host = \"h\"\n")]);

        [$status, $out] = self::execute(new CheckCommand(), ['--root' => $root]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("{$root}/config/config.casc", $out);
        self::assertStringContainsString('app, db', $out);
    }

    public function testCheckOfAMissingDefaultDocumentSaysItLoadsAsEmpty(): void
    {
        $root = $this->project();

        [$status, $out] = self::execute(new CheckCommand($root, ['dotenv' => false]));

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('no configuration', $out);
    }

    public function testCheckWritesNoCompiledFile(): void
    {
        // A deploy step runs as another user than the web server; a file
        // it wrote would be one the server can neither read nor replace.
        $root = $this->project(['config/config.casc' => self::casc("a = 1\n")]);

        self::execute(new CheckCommand($root, ['compiledCache' => true]));

        self::assertDirectoryDoesNotExist("{$root}/var");
    }

    public function testCheckOfABrokenDocumentFailsWithTheError(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("a = = 1\n")]);

        [$status, , $err] = self::execute(new CheckCommand(), ['--root' => $root]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString("{$root}/config/config.casc", $err);
    }

    public function testAnErrorMessageIsPrintedAsItIsNotAsConsoleMarkup(): void
    {
        // Symfony Console reads `<tag>` as a style; an error quoting a
        // document's text must reach the terminal as written.
        $root = $this->project();

        [$status, , $err] = self::execute(new CheckCommand($root), ['--path' => '<info>missing</info>.casc']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('<info>missing</info>.casc', $err);
    }

    public function testCheckTakesAPath(): void
    {
        $root = $this->project(['etc/app.casc' => self::casc("a = 1\n")]);

        [$status, $out] = self::execute(new CheckCommand(), ['--root' => $root, '--path' => 'etc/app.casc']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("{$root}/etc/app.casc", $out);
    }

    public function testCheckLoadsWithTheOptionsItWasConstructedWith(): void
    {
        // A framework integration checks the document the way it loads
        // it: its own path, environment, resolvers.
        $root = $this->project(['etc/app.casc' => self::casc("port = \${CHECK_PORT}\n")]);

        [$status, $out, $err] = self::execute(new CheckCommand($root, [
            'path' => 'etc/app.casc',
            'dotenv' => false,
            'env' => ['CHECK_PORT' => '80'],
        ]));

        self::assertSame(Command::SUCCESS, $status, $err);
        self::assertStringContainsString("{$root}/etc/app.casc", $out);
        self::assertStringContainsString('port', $out);
    }

    public function testThePathOptionOutranksTheConstructedPath(): void
    {
        $root = $this->project(['etc/a.casc' => self::casc("a = 1\n"), 'etc/b.casc' => self::casc("b = 1\n")]);

        [, $out] = self::execute(new CheckCommand($root, ['path' => 'etc/a.casc']), ['--path' => 'etc/b.casc']);

        self::assertStringContainsString("{$root}/etc/b.casc", $out);
    }

    public function testAnUnknownLoadOptionFailsWithTheReason(): void
    {
        $root = $this->project();

        [$status, , $err] = self::execute(new CheckCommand($root, ['nonsense' => true]));

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('nonsense', $err);
    }
}
