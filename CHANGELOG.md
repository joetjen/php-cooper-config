# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Requires `joetjen/cooper` 0.2** (`^0.2`); 0.1 is no longer
  admitted. Nothing here changes with it: a `cooper-secrets` module is
  still resolved to its class-string, which 0.2 gives back for a loaded
  module as 0.1 did. Its one change of behaviour, a module interpolated
  into a string reading as the name the document wrote (`Foo.Bar`),
  reaches the loaded configuration unchanged.

## [0.2.0] - 2026-10-14

### Added

- **`Casc\Secret`** marks a written entry's key secret (CASC.md §4.3):
  `new Secret($value)` as a map entry's value writes `*key = value`, or
  `*key { ... }` for a block. It nests with `Commented` in either order.
  A list element has no key to mark, so a `Secret` there is refused, as
  a `Commented` is; an entry of a map inside a list is written
  `[{ *key = value }]`.

### Fixed

- **The compiled cache is written behind a symlink.** A cache directory
  reached through one (a symlinked project, or macOS's `/var`, which is
  `/private/var`) never got a compiled file, so every request parsed
  the whole document: `tempnam()` answers with the resolved path, which
  was taken for a fallback to the system temp directory. Both sides are
  compared resolved now, so consumers no longer need to `realpath()`
  the cache directory themselves; a genuine fallback is still refused.

## [0.1.0] - 2026-10-08

### Added

- `Config::load()`: loads `config/config.casc` under the Composer root
  package's directory (or a given `path`/`root`) with
  [`joetjen/cooper`](https://github.com/joetjen/php-cooper), eagerly, once, at startup.
  Every Cooper load option is passed through, `dotenvDir` included; an
  unknown option is an error listing the supported ones. `.env` files
  are Cooper's to find, at the project root by default, the `.env.<env>`
  one named by `COOPER_ENV` (`.env.dev`, `.env.prod`); the load never
  changes the working directory. A missing default document loads an
  empty configuration; a missing explicit one, a load error, or a
  conversion error is a `ConfigError` carrying Cooper's formatted error
  and the path, and stores nothing.
- `Config::get()`/`require()`/`has()`/`all()`, by dotted path or list
  of segments, and the global `cooper_config()`. Reading before loading
  is a `NotLoadedError` saying how to load.
- `Convert::toAppConfig()`: Cooper's typed literals untagged -- byte
  sizes to byte counts, durations to whole milliseconds (refused
  otherwise), addresses to their text, tuples to lists -- the rules of
  the Elixir `cooper_config` and the Praxis `cooper_prx`.
- The `cooper-secrets` policy per top-level block: `reveal` (default),
  `keep`, or a module name whose `new()` (else constructor) wraps each
  secret; refused at the root, nested, unknown, or unwrappable.
- The compiled cache, on by default: a successful load written as an
  opcache-friendly PHP file (atomically, mode `0600`), used while every
  file it read, every import's expansion (expanded again by Cooper, so
  a file added where a glob looks counts), and every environment
  variable it read is unchanged.
- `cooper:init`, `cooper:check` and `cooper:cache:clear` as Symfony
  Console commands (`JOetjen\CooperConfig\Command\InitCommand`,
  `CheckCommand`, `CacheClearCommand`), depending on no framework --
  the project root, `check`'s load options and `cache:clear`'s cache
  directory come in through the constructor -- so a Symfony bundle and
  a Laravel package can register them with `bin/console` and artisan.
  Requires `symfony/console` `^6.4 || ^7.0 || ^8.0`.
- `vendor/bin/cooper-config`, a Symfony Console application running
  those commands, with `init`, `check`, and `cache:clear` as aliases.
  `help`, `--help` or `-h` alone lists the commands.
- `JOetjen\CooperConfig\Casc\Writer`: a PHP value tree written as a
  CASC document that loads back as the same values -- version header,
  maps as blocks, lists, strings quoted so nothing is interpolated by
  accident, non-identifier and reserved keys quoted, keys in the order
  given, a map inside a list as a block element (`[{ host = "a" }]`).
  `Raw` passes CASC source through (`${DB_HOST:"127.0.0.1"}`,
  `!int(${PORT})`); `Commented` puts `# ` lines above an entry;
  `MapValue` writes an array as a map whatever its shape -- `{}` for an
  empty one, which a bare empty array (`[]`) cannot say.
