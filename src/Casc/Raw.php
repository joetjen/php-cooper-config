<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Casc;

/**
 * A value that is already CASC source, which `Writer` writes exactly as
 * given instead of as data: an environment reference
 * (`${DB_HOST:"127.0.0.1"}`), a tagged value (`!int(${PORT})`,
 * `!module("Foo.Bar")`), a duration (`5m`), an atom (`info`) -- anything
 * a plain PHP value cannot say.
 *
 * Nothing checks that the fragment is CASC; loading the written
 * document does. Only an empty fragment is refused, since `key =` with
 * nothing after it would swallow the next line as its value.
 */
final class Raw
{
    /**
     * @param string $source the CASC value, as it should appear after `key = `
     * @throws \InvalidArgumentException when `$source` is empty or only whitespace
     */
    public function __construct(public readonly string $source)
    {
        if (trim($source) === '') {
            throw new \InvalidArgumentException('a raw CASC fragment cannot be empty');
        }
    }
}
