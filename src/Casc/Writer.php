<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Casc;

/**
 * Writes a PHP value tree as a CASC document (`casc/CASC.md` in
 * `joetjen/cooper`) that loads back as the same values -- the shared
 * writer behind every generated `.casc` file (a framework's existing
 * configuration converted, a scaffold).
 *
 * ```php
 * echo (new Writer())->write([
 *     'database' => [
 *         'host' => new Commented(new Raw('${DB_HOST:"127.0.0.1"}'), 'was: env("DB_HOST")'),
 *         'port' => 5432,
 *     ],
 * ]);
 * ```
 *
 * ## What becomes what
 *
 *  - The tree is the document: each entry a `key = value` line, in the
 *    order given. A nested associative array is a block (`key { ... }`),
 *    a list (`array_is_list()`) a list literal -- inline when it fits on
 *    one line, else one element per line.
 *  - A map inside a list, at any depth, is a block element (CASC.md
 *    §6.10): `[{ host = "a" }, { host = "b" }]`, each one inline when
 *    it fits and holds no block or comment, else over several lines.
 *  - An empty array is `[]`: a PHP array cannot say whether it is an
 *    empty list or an empty map, and both load back as `[]`. `MapValue`
 *    says it is a map -- `{}` when empty (CASC.md §5.4), which written
 *    over a map already there leaves it as it is, where `[]` replaces
 *    it.
 *  - `int`, `float` (shortest digits that read back the same, always with
 *    a fraction or exponent, so it stays a float; `inf`/`-inf`), `bool`,
 *    `null` (`nil`).
 *  - A string is double-quoted, escaped, unless it holds a reference
 *    opener (`${`, `@{`, `%{`, `!{`, `!Name(`): then it is single-quoted,
 *    which CASC never interpolates. One holding an opener *and* a `'`
 *    or a control character fits neither; it is double-quoted with each
 *    opener's first character interpolated from a private variable
 *    (`@*casc_writer_dollar = '$'`), since escapes are processed before
 *    interpolation and no escape keeps `${` literal.
 *  - A key matching CASC.md §4.1 and not a reserved word is written bare;
 *    any other is quoted (§4.2), by the same rule as a string.
 *  - `Raw` is written as given; `Commented` puts `# ` lines above its key.
 *
 * Anything else -- an object, a resource, `NAN`, text that is not
 * UTF-8 -- is an `\InvalidArgumentException` naming where in the tree
 * it is.
 */
final class Writer
{
    /** CASC.md §4.1. */
    private const IDENTIFIER = '/\A[a-zA-Z][a-zA-Z0-9_+\-]*[?!]?\z/';

    /**
     * Words a bare key must not be: §6.4's reserved values, which the
     * grammar reads as values in key position, and the two statement
     * keywords, quoted so a key never reads as the start of a statement.
     */
    private const RESERVED_KEYS = ['nil', 'true', 'false', 'inf', 'import', 'for'];

    /** Where interpolation starts in a double-quoted string (CASC.md §7). */
    private const OPENER = '/[@$%!]\{|![A-Za-z][A-Za-z0-9_+\-]*[?!]?\(/';

    /** The private variable that stands in for each opener's first character. */
    private const HELPERS = ['$' => 'casc_writer_dollar', '@' => 'casc_writer_at', '%' => 'casc_writer_percent', '!' => 'casc_writer_bang'];

    /** A list is written inline while its line stays this short. */
    private const WIDTH = 80;

    /** @var array<string, true> the helper variables this document uses, by opener character */
    private array $helpers = [];

    /**
     * @param string $version the version header's value
     * @param string $indent one level of block and list indentation
     */
    public function __construct(private readonly string $version = '1.0', private readonly string $indent = '  ')
    {
    }

    /**
     * The CASC document for `$tree`.
     *
     * @param array<array-key, mixed> $tree the document's top-level entries (a list's indices become keys)
     * @param string $comment lines written as `# ` comments under the header
     * @return string the document, ending in a newline
     * @throws \InvalidArgumentException when a value has no CASC spelling
     */
    public function write(array $tree, string $comment = ''): string
    {
        $this->helpers = [];
        $body = $this->entries($tree, 0, '');

        $parts = ["#@version = {$this->version}\n"];
        if ($comment !== '') {
            $parts[] = self::comment((new Commented(null, $comment))->lines, '');
        }
        if ($this->helpers !== []) {
            $declarations = '';
            foreach (array_keys($this->helpers) as $char) {
                $declarations .= '@*' . self::HELPERS[$char] . " = '{$char}'\n";
            }
            $parts[] = $declarations;
        }
        if ($body !== '') {
            $parts[] = $body;
        }

        return implode("\n", $parts);
    }

    /**
     * A map's entries at `$level`, a blank line around every block and
     * every commented entry so each reads as its own paragraph.
     *
     * Impure: a string on the way may need a helper variable, which it
     * records in `$helpers` for `write()` to declare.
     *
     * @phpstan-impure
     * @param array<array-key, mixed> $map
     */
    private function entries(array $map, int $level, string $path): string
    {
        $out = '';
        $previousStandsApart = false;
        foreach ($map as $key => $value) {
            $key = (string) $key;
            $where = $path === '' ? $key : "{$path}.{$key}";
            $lines = [];
            if ($value instanceof Commented) {
                $lines = $value->lines;
                $value = $value->value;
                // A comment on a comment: both, outer first.
                while ($value instanceof Commented) {
                    $lines = [...$lines, ...$value->lines];
                    $value = $value->value;
                }
            }
            $pad = str_repeat($this->indent, $level);
            $entry = self::comment($lines, $pad);
            $isBlock = self::isBlock($value);
            $map = $value instanceof MapValue ? $value->entries : $value;
            if ($isBlock && $map === []) {
                $entry .= "{$pad}{$this->key($key, $where)} {}\n";
            } elseif ($isBlock) {
                assert(is_array($map));
                $entry .= "{$pad}{$this->key($key, $where)} {\n" . $this->entries($map, $level + 1, $where) . "{$pad}}\n";
            } else {
                $entry .= "{$pad}{$this->key($key, $where)} = " . $this->value($value, $level, $where) . "\n";
            }

            $standsApart = $isBlock || $lines !== [];
            if ($out !== '' && ($standsApart || $previousStandsApart)) {
                $out .= "\n";
            }
            $out .= $entry;
            $previousStandsApart = $standsApart;
        }

        return $out;
    }

    /**
     * Whether `$value` is written as a block: a `MapValue`, or a
     * non-empty array that is not a list. A list holding maps is a list
     * now, its maps block elements (CASC.md §6.10); it was once a block
     * keyed `"0"`, `"1"`, ..., when CASC could not write a map inside a
     * list, and a map in a list in a list could not be written at all.
     */
    private static function isBlock(mixed $value): bool
    {
        return $value instanceof MapValue || (is_array($value) && $value !== [] && !array_is_list($value));
    }

    /**
     * One value in value position: anything `isBlock()` does not take --
     * or, in a list, anything at all, a map as a block element.
     */
    private function value(mixed $value, int $level, string $where): string
    {
        return match (true) {
            $value instanceof Raw => $value->source,
            $value instanceof Commented => throw new \InvalidArgumentException("{$where}: a comment belongs to a map entry, not to a list element"),
            self::isBlock($value) => $this->mapElement($value instanceof MapValue ? $value->entries : $value, $level, $where),
            is_array($value) => $this->list($value, $level, $where),
            $value === null => 'nil',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value, $where),
            is_string($value) => $this->string($value, $where),
            default => throw new \InvalidArgumentException("{$where}: a " . get_debug_type($value) . ' has no CASC spelling'),
        };
    }

    /**
     * @param array<array-key, mixed> $list
     */
    private function list(array $list, int $level, string $where): string
    {
        $elements = [];
        foreach ($list as $index => $element) {
            $elements[] = $this->value($element, $level + 1, "{$where}[{$index}]");
        }

        $inline = '[' . implode(', ', $elements) . ']';
        $fits = strlen(str_repeat($this->indent, $level) . $where) + 3 + strlen($inline) <= self::WIDTH;
        if ($fits && !str_contains($inline, "\n")) {
            return $inline;
        }

        // One element per line, separated by the newlines alone (CASC.md
        // §6.10), so a multi-line element needs no trailing comma.
        $pad = str_repeat($this->indent, $level + 1);

        return "[\n" . implode('', array_map(static fn (string $e): string => "{$pad}{$e}\n", $elements)) . str_repeat($this->indent, $level) . ']';
    }

    /**
     * A map as a list element (CASC.md §6.10): `{}` when empty, inline
     * (`{ host = "a", port = 1 }`) when it fits on its line and holds no
     * block or comment -- an inline block has nowhere to put either --
     * else one entry per line, as a block anywhere else is written.
     *
     * Impure, as `entries()` is.
     *
     * @phpstan-impure
     * @param array<array-key, mixed> $map
     */
    private function mapElement(array $map, int $level, string $where): string
    {
        if ($map === []) {
            return '{}';
        }

        $inline = [];
        foreach ($map as $key => $value) {
            $key = (string) $key;
            if ($value instanceof Commented || self::isBlock($value)) {
                $inline = null;
                break;
            }
            $inline[] = "{$this->key($key, "{$where}.{$key}")} = " . $this->value($value, $level, "{$where}.{$key}");
        }
        if ($inline !== null) {
            $text = '{ ' . implode(', ', $inline) . ' }';
            if (!str_contains($text, "\n") && strlen(str_repeat($this->indent, $level)) + strlen($text) <= self::WIDTH) {
                return $text;
            }
        }

        return "{\n" . $this->entries($map, $level + 1, $where) . str_repeat($this->indent, $level) . '}';
    }

    private function key(string $key, string $where): string
    {
        if (preg_match(self::IDENTIFIER, $key) === 1 && !in_array($key, self::RESERVED_KEYS, true)) {
            return $key;
        }
        $quoted = $this->string($key, $where);
        // An interpolated key segment may not contain a `.` (CASC.md
        // §4.2): one arriving through interpolation would read as two.
        if ($quoted[0] === '"' && preg_match(self::OPENER, $key) === 1 && str_contains($key, '.')) {
            throw new \InvalidArgumentException("{$where}: a key holding a \".\", a \"'\" and a reference opener has no CASC spelling");
        }

        return $quoted;
    }

    /**
     * A string literal that reads back as exactly `$text`.
     */
    private function string(string $text, string $where): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException("{$where}: text that is not UTF-8 has no CASC spelling");
        }
        if (preg_match(self::OPENER, $text) !== 1) {
            return '"' . self::escape($text) . '"';
        }
        // Single quotes are literal (CASC.md §6.5) -- and kept to text a
        // reader can see, so a control character still goes the
        // double-quoted way below.
        if (!str_contains($text, "'") && preg_match('/[\x00-\x1f\x7f]/', $text) !== 1) {
            return "'{$text}'";
        }

        $escaped = self::escape($text);
        preg_match_all(self::OPENER, $escaped, $openers);
        foreach ($openers[0] as $opener) {
            $this->helpers[$opener[0]] = true;
        }
        $escaped = (string) preg_replace_callback(
            self::OPENER,
            static fn (array $m): string => '@{' . self::HELPERS[$m[0][0]] . '}' . substr($m[0], 1),
            $escaped,
        );

        return '"' . $escaped . '"';
    }

    /**
     * Double-quoted string escapes (CASC.md §6.5); a control character
     * without a letter escape is a `\uXXXX`.
     */
    private static function escape(string $text): string
    {
        $text = strtr($text, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);

        return (string) preg_replace_callback('/[\x00-\x1f\x7f]/', static fn (array $m): string => sprintf('\\u%04x', ord($m[0])), $text);
    }

    /**
     * The shortest digits that read back as `$value` -- PHP's own
     * round-trip formatting (`serialize_precision = -1`), set for the
     * call rather than trusted to an ini file that may have changed it.
     */
    private static function float(float $value, string $where): string
    {
        if (is_nan($value)) {
            throw new \InvalidArgumentException("{$where}: NAN has no CASC spelling");
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }
        $previous = ini_set('serialize_precision', '-1');
        try {
            // Always with a fraction (`100.0`, `1.0E+25`), so it reads
            // back as a float and not an int.
            return strtolower(var_export($value, true));
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    /**
     * `# ` lines -- the space is what keeps `#key` from reading as a
     * disabled statement (CASC.md §3.2).
     *
     * @param list<string> $lines
     */
    private static function comment(array $lines, string $pad): string
    {
        return implode('', array_map(static fn (string $line): string => $line === '' ? "{$pad}#\n" : "{$pad}# {$line}\n", $lines));
    }
}
