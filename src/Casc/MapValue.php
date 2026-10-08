<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Casc;

/**
 * An array `Writer` writes as a map -- a block -- whatever its shape:
 * the one way to say that an empty array is an empty map (`logger {}`)
 * rather than an empty list (`logger = []`), or that an array keyed
 * `0`, `1`, ... is a map keyed `"0"`, `"1"`, ....
 *
 * A PHP array cannot say which it is, and both load back as the same
 * array, so `Writer` reads a bare one as a list. The difference is in
 * what the document means (CASC.md §5.4): written over a map that is
 * already there -- in an overlay, say -- `{}` merges and leaves it as it
 * is, where `[]` replaces it.
 */
final class MapValue
{
    /**
     * @param array<array-key, mixed> $entries the map's entries, written in the order given
     */
    public function __construct(public readonly array $entries = [])
    {
    }
}
