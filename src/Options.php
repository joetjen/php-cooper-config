<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

/**
 * The validated form of `Config::load()`'s options: this library's own
 * (`path`, `root`, `compiledCache`) apart from the ones passed through
 * to `Cooper::loadFileTraced()` untouched.
 *
 * Every key is enumerated, so a typo -- or an option from a newer
 * version -- is an error naming what is supported rather than an option
 * that silently does nothing. That couples the list to Cooper's option
 * set deliberately, as the Elixir `cooper_config` does: raising on a
 * valid-but-newer option is loud and immediately diagnosable; ignoring
 * a security-relevant one is neither. Cooper's `root` is not passed
 * through: here `root` is the project root, and imports resolve against
 * the document's own directory, as they do for any `Cooper::loadFile()`.
 *
 * @internal
 */
final class Options
{
    private const OWN = ['path', 'root', 'compiledCache'];

    private const COOPER = [
        'env', 'dotenv', 'dotenvEnv', 'dotenvFiles', 'dotenvDir', 'dotenvOverride',
        'resolvers', 'tags', 'importSchemes', 'modules', 'cache',
    ];

    /**
     * @param array<string, mixed> $cooper the options passed through to Cooper
     * @param array<string, mixed> $modules
     * @param array<string, mixed> $tags
     * @param array<string, mixed> $resolvers
     * @param array<string, mixed> $importSchemes
     */
    private function __construct(
        public readonly ?string $path,
        public readonly ?string $root,
        public readonly bool|string $compiledCache,
        public readonly array $cooper,
        public readonly array $modules,
        public readonly array $tags,
        public readonly array $resolvers,
        public readonly array $importSchemes,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException on an unknown key or a mistyped value
     */
    public static function from(array $options): self
    {
        $known = [...self::OWN, ...self::COOPER];
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'unknown option(s): ' . implode(', ', $unknown) . '; supported options: ' . implode(', ', $known)
            );
        }

        $path = $options['path'] ?? null;
        if ($path !== null && (!is_string($path) || $path === '')) {
            throw new \InvalidArgumentException('option "path" must be a non-empty string');
        }
        $root = $options['root'] ?? null;
        if ($root !== null && (!is_string($root) || !is_dir($root))) {
            throw new \InvalidArgumentException('option "root" must be an existing directory');
        }
        $compiled = $options['compiledCache'] ?? true;
        if (!is_bool($compiled) && (!is_string($compiled) || $compiled === '')) {
            throw new \InvalidArgumentException('option "compiledCache" must be true, false, or a directory');
        }

        return new self(
            $path,
            $root === null ? null : ((string) realpath($root)),
            $compiled,
            array_intersect_key($options, array_flip(self::COOPER)),
            self::map($options, 'modules'),
            self::map($options, 'tags'),
            self::map($options, 'resolvers'),
            self::map($options, 'importSchemes'),
        );
    }

    /**
     * One of the name => something options this library looks into
     * itself; Cooper checks the values again when it loads.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function map(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!is_array($value)) {
            throw new \InvalidArgumentException("option \"{$key}\" must be an array of name => value");
        }
        $map = [];
        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }
}
