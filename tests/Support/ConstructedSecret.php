<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Support;

/**
 * A secret type with no `new()`, only a one-argument constructor -- the
 * form `cooper-secrets = "..."` falls back to.
 */
final class ConstructedSecret
{
    public function __construct(public readonly mixed $value)
    {
    }
}
