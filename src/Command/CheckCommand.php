<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Command;

use JOetjen\CooperConfig\Config;
use JOetjen\CooperConfig\ConfigError;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cooper:check [--root=DIR] [--path=FILE]`: loads the configuration as
 * `Config::load()` would and reports it -- the document, its top-level
 * keys; exit status 1 and the error on failure.
 *
 * Writes no compiled file, whatever the load options say: a deploy step
 * usually runs as another user than the web server, which could then
 * neither read nor replace a file it wrote.
 *
 * The load options are injected, so a framework integration checks the
 * document the way it loads it -- its own `path`, `env`, resolvers,
 * tags, `modules`. `root` and `compiledCache` among them are ignored:
 * the root is the command's own (see `ProjectCommand`) and the compiled
 * cache is always off. `--path` outranks an injected `path`.
 */
final class CheckCommand extends ProjectCommand
{
    /**
     * @param string|null $projectRoot the project root unless `--root` says otherwise; `null` for the Composer root package's directory
     * @param array<string, mixed> $loadOptions `Config::load()`'s options, checked when the command runs
     */
    public function __construct(?string $projectRoot = null, private readonly array $loadOptions = [])
    {
        parent::__construct('cooper:check', $projectRoot);
    }

    protected function configure(): void
    {
        $this->setDescription('Load the configuration and report it (exit 1 on error)');
        $this->addRootOption();
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'The document, relative to the root (default: ' . Config::DEFAULT_PATH . ')');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $load = ['root' => $this->root($input), 'compiledCache' => false] + $this->loadOptions;
            $path = $input->getOption('path');
            if (is_string($path)) {
                $load['path'] = $path;
            }
            Config::load($load);
        } catch (ConfigError | \InvalidArgumentException $e) {
            self::complain($output, $e->getMessage());

            return self::FAILURE;
        }

        $report = Config::report();
        if (!$report->found) {
            self::say($output, "no configuration: {$report->file} does not exist (it loads as empty)");

            return self::SUCCESS;
        }
        $keys = array_map('strval', array_keys(Config::all()));
        self::say($output, "loaded {$report->file}");
        self::say($output, 'top-level keys: ' . ($keys === [] ? '(none)' : implode(', ', $keys)));

        return self::SUCCESS;
    }
}
