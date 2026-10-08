<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

/**
 * What the last `Config::load()` did: which document, whether it was
 * there, and whether the compiled cache served or stored it. For
 * diagnostics (`cooper-config check`), never needed to read the
 * configuration itself.
 */
final class LoadReport
{
    /**
     * @param string $file the document, as an absolute path
     * @param bool $found false when the default document is missing and the configuration is empty
     * @param bool $fromCache true when the compiled cache served the configuration without reading the document
     * @param string|null $cacheFile the compiled file that served or now holds this configuration; null when there is none
     */
    public function __construct(
        public readonly string $file,
        public readonly bool $found,
        public readonly bool $fromCache,
        public readonly ?string $cacheFile,
    ) {
    }
}
