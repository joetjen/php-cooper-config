<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

use Composer\InstalledVersions;

/**
 * Where the application lives: the directory `config/config.casc` and
 * `var/cache/cooper-config/` are found under by default.
 *
 * The Composer root package's directory -- the project whose
 * `composer.json` installed this library -- normalised to a real path.
 * Not the working directory, which for a web request is usually
 * `public/` and for a worker whatever its supervisor chose. Only
 * without Composer's runtime API (a hand-rolled autoloader) does it fall
 * back to the working directory.
 */
final class ProjectRoot
{
    private function __construct()
    {
    }

    /**
     * @return string the project root, as an absolute path
     */
    public static function detect(): string
    {
        if (class_exists(InstalledVersions::class)) {
            $real = realpath(InstalledVersions::getRootPackage()['install_path']);
            if ($real !== false) {
                return $real;
            }
        }
        $cwd = getcwd();

        return $cwd === false ? '.' : $cwd;
    }
}
