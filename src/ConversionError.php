<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

/**
 * Cooper loaded the document, but it cannot become application
 * configuration: a duration that is not a whole number of milliseconds,
 * or a `cooper-secrets` policy that is misplaced, unknown, or names a
 * class that cannot wrap a secret. `Config::load()` reports it as a
 * `ConfigError`; `Convert::toAppConfig()` throws it directly.
 */
final class ConversionError extends \RuntimeException
{
}
