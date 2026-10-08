<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

/**
 * `Config::require()` found nothing at the path it was given.
 */
final class MissingKeyError extends \OutOfBoundsException
{
    /**
     * @param list<string|int> $segments the path that was required, as segments
     */
    public function __construct(public readonly array $segments)
    {
        parent::__construct('the configuration has no value at ' . implode('.', $segments));
    }
}
