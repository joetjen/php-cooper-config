<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

use JOetjen\Cooper\CooperError;

/**
 * The configuration could not be loaded: an explicitly given file is
 * missing, Cooper refused the document, or what it loaded could not be
 * converted (a duration that is not whole milliseconds, a refused
 * `cooper-secrets` policy).
 *
 * `$path` names the document; `getPrevious()` is the cause -- a
 * `CooperError` whose formatted message (stage, file, line, column) is
 * repeated in this one, or a `ConversionError`. Nothing is stored when
 * this is thrown: a program with half its configuration is worse off
 * than one that was told why it has none.
 */
final class ConfigError extends \RuntimeException
{
    /**
     * @param string $message what went wrong, the path included
     * @param string $path the document, as an absolute path
     * @param \Throwable|null $previous the cause, if any
     */
    public function __construct(string $message, public readonly string $path, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @internal
     */
    public static function missingFile(string $path): self
    {
        return new self("failed to load the configuration: {$path} does not exist", $path);
    }

    /**
     * @internal
     */
    public static function fromCooper(string $path, CooperError $error): self
    {
        return new self(
            "failed to load the configuration from {$path} ({$error->stage} stage):\n\n{$error->getMessage()}",
            $path,
            $error,
        );
    }

    /**
     * @internal
     */
    public static function fromConversion(string $path, ConversionError $error): self
    {
        return new self("failed to load the configuration from {$path}:\n\n{$error->getMessage()}", $path, $error);
    }
}
