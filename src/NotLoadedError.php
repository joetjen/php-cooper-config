<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

/**
 * The configuration was read before it was loaded.
 *
 * Loading is eager only -- once, at startup -- so this is always a
 * missing line in a front controller or bootstrap, never something to
 * recover from by loading on first access. The message says where the
 * line goes.
 */
final class NotLoadedError extends \LogicException
{
    /**
     * @internal
     */
    public static function create(): self
    {
        return new self(
            'the configuration has not been loaded: call JOetjen\CooperConfig\Config::load() once at startup '
            . '-- in your front controller or bootstrap, before anything reads it'
        );
    }
}
