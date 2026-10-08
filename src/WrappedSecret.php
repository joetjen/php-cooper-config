<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

/**
 * A secret the `cooper-secrets` policy will hand to an application's own
 * class, not yet handed: the class, how to call it, and the revealed,
 * converted value.
 *
 * Conversion produces these rather than calling the class directly so
 * the compiled cache can write the call itself (`\App\Secret::new(...)`)
 * instead of trying to serialize whatever object the class returns --
 * the file then rebuilds the object exactly as a fresh load would.
 * `Convert::materialize()` makes the call for a fresh load.
 *
 * @internal
 */
final class WrappedSecret
{
    /**
     * @param class-string $class the application's secret class
     * @param bool $viaNew `$class::new($value)` when true, `new $class($value)` otherwise
     * @param mixed $value the revealed, converted value
     */
    public function __construct(
        public readonly string $class,
        public readonly bool $viaNew,
        public readonly mixed $value,
    ) {
    }

    /**
     * Makes the call.
     */
    public function wrap(mixed $value): object
    {
        $class = $this->class;

        return $this->viaNew ? $class::new($value) : new $class($value);
    }
}
