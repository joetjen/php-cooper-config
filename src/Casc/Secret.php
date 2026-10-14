<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Casc;

/**
 * A map entry's value whose key `Writer` marks secret (CASC.md §4.3):
 * `*password = "..."`, or `*database { ... }` for a block, every value
 * in which then loads as a secret. The value itself is written as if it
 * were not wrapped -- a scalar, a `Raw`, a list, a block.
 *
 * It composes with `Commented` in either order:
 * `new Secret(new Commented($value, '...'))` and
 * `new Commented(new Secret($value), '...')` both write the comment
 * above a `*key` line.
 *
 * Only a map entry can carry one: the `*` marks a key, and a list's
 * elements have none, so `Writer` refuses a `Secret` there. A map that
 * is a list element is different -- its entries have keys, so
 * `[new MapValue(['password' => new Secret('x')])]` writes
 * `[{ *password = "x" }]`.
 */
final class Secret
{
    /**
     * @param mixed $value the entry's value, written as if it were not wrapped
     */
    public function __construct(public readonly mixed $value)
    {
    }
}
