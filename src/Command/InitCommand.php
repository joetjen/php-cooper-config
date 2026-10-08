<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cooper:init [--root=DIR]`: writes `config/config.casc` and one
 * directory per environment (`config/dev/`, `config/test/`,
 * `config/prod/`), each holding one `.casc` so the document's
 * `import "${COOPER_ENV}/*.casc"` matches. Refuses to overwrite
 * anything: one existing file and nothing is written.
 *
 * A plain Symfony Console command with no framework behind it, so a
 * Symfony bundle registers it with `bin/console`, a Laravel package
 * with artisan, and `vendor/bin/cooper-config` runs it standalone.
 */
final class InitCommand extends ProjectCommand
{
    private const ENVIRONMENTS = ['dev', 'test', 'prod'];

    /**
     * @param string|null $projectRoot where to scaffold unless `--root` says otherwise; `null` for the Composer root package's directory
     */
    public function __construct(?string $projectRoot = null)
    {
        parent::__construct('cooper:init', $projectRoot);
    }

    protected function configure(): void
    {
        $this->setDescription('Scaffold config/config.casc and config/{dev,test,prod}/');
        $this->addRootOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $root = $this->root($input);
        } catch (\InvalidArgumentException $e) {
            self::complain($output, $e->getMessage());

            return self::FAILURE;
        }

        $files = ['config/config.casc' => self::mainDocument()];
        foreach (self::ENVIRONMENTS as $env) {
            $files["config/{$env}/app.casc"] = self::environmentDocument($env);
        }

        // All or nothing: one existing file and nothing is written.
        $existing = array_filter(array_keys($files), static fn (string $path): bool => file_exists("{$root}/{$path}"));
        if ($existing !== []) {
            self::complain($output, 'refusing to overwrite: ' . implode(', ', $existing));

            return self::FAILURE;
        }

        foreach ($files as $path => $content) {
            $file = "{$root}/{$path}";
            if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0777, true)) {
                self::complain($output, 'could not create ' . dirname($path));

                return self::FAILURE;
            }
            file_put_contents($file, $content);
            self::say($output, "created {$path}");
        }

        return self::SUCCESS;
    }

    private static function mainDocument(): string
    {
        return <<<'CASC'
            #@version = 1.0

            # The application's configuration, loaded once at startup by
            # joetjen/cooper-config: JOetjen\CooperConfig\Config::load().
            #
            # Each top-level block is one part of the application:
            #
            #   database {
            #     host      = "localhost"
            #     *password = ${DB_PASSWORD}
            #   }
            #
            # Settings for one environment go in config/<env>/. COOPER_ENV names
            # the environment; unset, APP_ENV does (local and development as
            # dev, testing as test, production as prod), else it is dev.

            # Last, so an environment overrides everything above.
            import "${COOPER_ENV}/*.casc"

            CASC;
    }

    private static function environmentDocument(string $env): string
    {
        return <<<CASC
            #@version = 1.0

            # Settings for the {$env} environment, over config/config.casc.
            # Every .casc file in this directory is imported.

            CASC;
    }
}
