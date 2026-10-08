<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Support;

/**
 * Neither a `new()` nor a constructor taking one argument: a class
 * `cooper-secrets` must refuse to wrap with.
 */
final class UnwrappableSecret
{
    public function __construct(public readonly mixed $value, public readonly string $label)
    {
    }
}
