<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Casc;

/**
 * A map entry's value with a comment `Writer` puts on the lines above
 * its key -- a `# was: env("APP_URL")` note for a migrated setting, say.
 *
 * Only a map entry can carry one: a list's elements have no key to sit
 * above, and `Writer` refuses a `Commented` there.
 */
final class Commented
{
    /** @var list<string> the comment's lines, without `# ` */
    public readonly array $lines;

    /**
     * @param mixed $value the entry's value, written as if it were not wrapped
     * @param string $comment the comment; each line becomes one `# ` line
     */
    public function __construct(public readonly mixed $value, string $comment)
    {
        $this->lines = preg_split('/\r\n|\r|\n/', $comment) ?: [$comment];
    }
}
