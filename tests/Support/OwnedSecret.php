<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Support;

/**
 * An application's own secret type, built through a static `new()` --
 * the form `cooper-secrets = "..."` prefers.
 */
final class OwnedSecret
{
    private function __construct(public readonly mixed $value)
    {
    }

    public static function new(mixed $value): self
    {
        return new self($value);
    }
}
