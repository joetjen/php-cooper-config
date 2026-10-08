<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Command;

use JOetjen\CooperConfig\ProjectRoot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What every `cooper:*` command shares: a project root, injected or
 * given as `--root`, and plain-text output.
 *
 * The root is injected through the constructor because the host knows
 * it -- a Symfony kernel's `%kernel.project_dir%`, Laravel's
 * `base_path()` -- and no framework should have to be guessed at from
 * here. Left out, it is the Composer root package's directory, as for
 * `Config::load()`. `--root` outranks both.
 *
 * @internal
 */
abstract class ProjectCommand extends Command
{
    /**
     * @param string $name the command's name
     * @param string|null $projectRoot the project root, or `null` for the Composer root package's directory
     */
    public function __construct(string $name, private readonly ?string $projectRoot = null)
    {
        parent::__construct($name);
    }

    /**
     * Adds `--root`; a subclass calls it from its own `configure()`.
     */
    protected function addRootOption(): void
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'The project root (default: ' . ($this->projectRoot ?? 'the Composer root package') . ')');
    }

    /**
     * The project root: `--root`, else the injected one, else detected.
     *
     * @throws \InvalidArgumentException when `--root` names no directory
     */
    protected function root(InputInterface $input): string
    {
        $given = $input->getOption('root');
        if (!is_string($given)) {
            return $this->projectRoot ?? ProjectRoot::detect();
        }
        $real = realpath($given);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException("--root is not a directory: {$given}");
        }

        return $real;
    }

    /**
     * Writes one line exactly as given. Console markup is off: a path or
     * an error quoting a document may hold `<...>`, which Symfony
     * Console would otherwise read as a style tag and swallow.
     */
    protected static function say(OutputInterface $output, string $line): void
    {
        $output->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    /**
     * `say()`, to standard error where the output has one.
     */
    protected static function complain(OutputInterface $output, string $line): void
    {
        self::say($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output, $line);
    }
}
