<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

use JOetjen\CooperConfig\Command\CacheClearCommand;
use JOetjen\CooperConfig\Command\CheckCommand;
use JOetjen\CooperConfig\Command\InitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * `vendor/bin/cooper-config`: the `cooper:*` commands (see
 * `JOetjen\CooperConfig\Command`) as a standalone Symfony Console
 * application, for a project with no `bin/console` or artisan to
 * register them with.
 *
 *  - `cooper:init` (alias `init`) -- scaffolds `config/`.
 *  - `cooper:check` (alias `check`) -- loads and reports; exit 1 on error.
 *  - `cooper:cache:clear` (alias `cache:clear`) -- removes every compiled file.
 *
 * The aliases are the names the script had before it was a Symfony
 * Console application, so deploy scripts keep working. They are set
 * here and not on the commands, where they would land in a framework's
 * shared command namespace (`bin/console init`).
 *
 * The script itself is `Cli::application()->run(Cli::input($argv))`:
 * built here rather than in the script so tests drive exactly what it
 * runs.
 */
final class Cli
{
    private function __construct()
    {
    }

    /**
     * The application, every `cooper:*` command registered with its
     * old name as an alias.
     *
     * @param string|null $projectRoot the project root, or `null` for the Composer root package's directory
     * @return Application ready to `run()`
     */
    public static function application(?string $projectRoot = null): Application
    {
        $application = new Application('cooper-config');
        $application->addCommands([
            (new InitCommand($projectRoot))->setAliases(['init']),
            (new CheckCommand($projectRoot))->setAliases(['check']),
            (new CacheClearCommand($projectRoot))->setAliases(['cache:clear']),
        ]);

        return $application;
    }

    /**
     * `$argv` as the application's input. `help`, `--help` or `-h` alone
     * means `list`: before this was a Symfony Console application each
     * listed the commands, where Symfony's own would describe `help`
     * itself. `help <command>` is Symfony's, unchanged.
     *
     * @param list<string> $argv the script's arguments, its own name first
     * @return ArgvInput
     */
    public static function input(array $argv): ArgvInput
    {
        if (count($argv) === 2 && in_array($argv[1], ['help', '--help', '-h'], true)) {
            $argv[1] = 'list';
        }

        return new ArgvInput($argv);
    }
}
