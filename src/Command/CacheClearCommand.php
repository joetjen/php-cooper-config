<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Command;

use JOetjen\CooperConfig\CompiledCache;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cooper:cache:clear [--root=DIR] [--cache-dir=DIR]`: removes every
 * compiled configuration file. Part of a deploy.
 *
 * The cache directory is injectable because a framework keeps caches
 * in its own place and loads with `compiledCache` pointing there; this
 * must clear that directory, not the default one.
 */
final class CacheClearCommand extends ProjectCommand
{
    /**
     * @param string|null $projectRoot the project root unless `--root` says otherwise; `null` for the Composer root package's directory
     * @param string|null $cacheDir the cache directory unless `--cache-dir` says otherwise; `null` for `<root>/var/cache/cooper-config`
     */
    public function __construct(?string $projectRoot = null, private readonly ?string $cacheDir = null)
    {
        parent::__construct('cooper:cache:clear', $projectRoot);
    }

    protected function configure(): void
    {
        $this->setDescription('Remove every compiled configuration file');
        $this->addRootOption();
        $this->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'The cache directory (default: ' . ($this->cacheDir ?? '<root>/var/cache/cooper-config') . ')');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $given = $input->getOption('cache-dir');
            $dir = is_string($given) ? $given : ($this->cacheDir ?? CompiledCache::defaultDirectory($this->root($input)));
        } catch (\InvalidArgumentException $e) {
            self::complain($output, $e->getMessage());

            return self::FAILURE;
        }
        $removed = CompiledCache::clear($dir);
        self::say($output, "removed {$removed} compiled configuration file(s) from {$dir}");

        return self::SUCCESS;
    }
}
