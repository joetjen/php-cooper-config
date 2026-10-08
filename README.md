# php-cooper-config

Loads an application's [CASC](https://github.com/joetjen/php-cooper/blob/main/casc/CASC.md) configuration
with [`joetjen/cooper`](https://github.com/joetjen/php-cooper) -- once, at startup -- and gives
the application functions to read it.

It is the PHP counterpart of the Elixir
[`cooper_config`](https://github.com/joetjen/cooper_config) and the
Praxis `cooper_prx`: the same document, the same conversion rules, the
same `cooper-secrets` policy. It is a thin layer, not a parser: Cooper
does all the language work (imports, merging, `${...}`/`%{...}`
resolution, secret wrapping); this library decides *when* the document
is read, turns the result into plain PHP values, and keeps it between
requests.

```casc
#@version = 1.0

http.port = 8080

database {
  host      = "localhost"
  *password = ${DB_PASSWORD}
  timeout   = 5s
}

import "${COOPER_ENV}/*.casc"
```

```php
// public/index.php -- the first thing the front controller does
use JOetjen\CooperConfig\Config;

require __DIR__ . '/../vendor/autoload.php';

Config::load();

// anywhere afterwards
Config::get('http.port');            // 8080
Config::require('database.password'); // "hunter2" -- revealed by default
Config::get('database.timeout');     // 5000 -- milliseconds
cooper_config('cache.ttl', 60);      // 60 -- not configured, so the default
```

**Status: 0.1.0, pre-release.** See [CHANGELOG.md](CHANGELOG.md).

## Installation

```sh
composer require joetjen/cooper-config
vendor/bin/cooper-config init        # optional: scaffold config/
```

PHP 8.2 or later. `joetjen/cooper` comes along as a dependency, and
`.env` support with it -- there is nothing more to add.

## Eager only

**Configuration arrives; it is not asked for.** `Config::load()` reads
the whole document, resolves it, converts it, and stores it for the
process. Nothing is ever loaded lazily, on first access:

- **A broken document stops the program at its first line**, with the
  reason, instead of at whichever request first reads a broken setting
  -- possibly hours after a deploy.
- **A configuration is never half there.** Code reading it never has to
  wonder whether a load is about to happen underneath it, or fail.
- **`${...}` and secrets are resolved once**, in the environment the
  process started in.

So call `Config::load()` once, at startup -- in `public/index.php`,
`bin/console`, a worker's entry point -- before anything reads it.
Reading before that is a `NotLoadedError` whose message says exactly
this. Calling `load()` again replaces the configuration; a load that
fails stores nothing and leaves what was there.

PHP being shared-nothing, "once at startup" is once per request under
PHP-FPM and once per process for a long-running worker (RoadRunner,
Swoole, FrankenPHP worker mode, a queue consumer). The
[compiled cache](#the-compiled-cache) is what makes the per-request
case cheap.

## Options

`Config::load(array $options = [])`:

| Option | Default | Effect |
|---|---|---|
| `path` | `config/config.casc` | The document, relative to `root` (or absolute). A missing *default* document loads an empty configuration -- a project with nothing to configure yet; a missing `path` you gave is a `ConfigError`. |
| `root` | the Composer root package's directory | The project root. Not the working directory, which for a web request is usually `public/`. |
| `compiledCache` | `true` | `false` turns the compiled cache off; a string is the directory to keep it in instead of `<root>/var/cache/cooper-config`. |
| `env`, `dotenv`, `dotenvEnv`, `dotenvFiles`, `dotenvDir`, `dotenvOverride` | Cooper's | `${...}` and `.env` layering, passed through to Cooper; `.env` files are read from the project root unless `dotenvDir` says otherwise. |
| `resolvers`, `tags`, `importSchemes`, `modules` | Cooper's | `!{name:...}`, `!Name(...)`, `import "scheme://..."`, `!module(...)`, passed through. `modules` also resolves a `cooper-secrets` class name. |
| `cache` | Cooper's (`true`) | Cooper's own in-memory cache, which matters for a long-running worker that reloads. |

An unknown option is an `\InvalidArgumentException` listing every
supported one: a typo, or an option from a newer version, fails at boot
instead of silently doing nothing. Cooper's own `root` is not among
them -- here `root` is the project root, and imports resolve against
the document's directory as they always do.

`.env` files are Cooper's to find: it reads them from the project root
(the Composer root package's directory) wherever the process started --
`public/`, for a web request -- or from `dotenvDir`. The load never
changes the working directory.

## Reading

```php
Config::get(string|array $path, mixed $default = null): mixed
Config::require(string|array $path): mixed   // MissingKeyError naming the path
Config::has(string|array $path): bool
Config::all(): array
cooper_config(string|array|null $path = null, mixed $default = null): mixed
```

A path is a dotted string (`'database.host'`, `'hosts.0'`) or a list of
segments (`['hosts', 'example.com']`, for a key holding a dot). An
empty path or segment is an `\InvalidArgumentException`. A present
`nil` is a value: `has()` is true and `get()` returns `null`, not the
default.

`cooper_config()` is the global shorthand -- `all()` without a path,
`get()` with one. It is not called `config()`, which Laravel owns.

`Config::isLoaded()`, `Config::report()` (which document, whether the
compiled cache served it), and `Config::unload()` (for tests) round out
the class.

## Conversion

Application configuration is read by code that has never heard of
Cooper, so Cooper's measurements arrive untagged:

| CASC | Cooper | `Config` |
|---|---|---|
| `1GiB` | `CooperBytes` | `1073741824` -- bytes; a `CooperInteger` beyond 64 bits |
| `14d`, `500ms` | `CooperDuration` | `1209600000`, `500` -- whole milliseconds; a `CooperInteger` beyond 64 bits |
| `10.0.0.1`, `::1` | `CooperIPv4`, `CooperIPv6` | `"10.0.0.1"`, `"::1"` |
| `10.0.0.0/8` | `CooperIPv4` with a prefix | `"10.0.0.0/8"` |
| `(1, "a")` | `CooperTuple` | `[1, "a"]` -- a list, elements converted |
| `{ ... }`, `[ ... ]` | arrays | arrays, walked at every depth |
| dates, times, atoms, big integers, ... | Cooper's value classes | unchanged |

- **Durations become milliseconds**, the unit most PHP timeouts and
  TTLs take beside seconds, and the one the Elixir and Praxis libraries
  chose. A duration that is not a whole number of them (`1500us`) is a
  load error naming it, never rounded: a silently shortened timeout is
  harder to find than a failed boot.
- **An address becomes its text**, where the BEAM libraries give an
  address tuple: text is what PHP's socket, stream, and filter
  functions take.
- **A tuple becomes a list.** PHP has no tuple type; `CooperTuple` is
  Cooper's, and a list is the nearest value PHP code already reads. The
  BEAM libraries keep a tuple because the BEAM has one.

`Convert::toAppConfig(array $config, array $modules = [])` is the same
conversion on its own, for a result you loaded yourself.

## Secrets

Each application -- a top-level block -- says how its own secrets
arrive, under the key `cooper-secrets`:

```casc
payments {
  cooper-secrets = keep          # reveal | keep | "App.Secret"
  *api_key       = ${PAYMENTS_KEY}
}
```

| `cooper-secrets` | A `*secret` arrives as |
|---|---|
| absent, or `reveal` | the revealed value, converted -- what configuration has always held, and what a database client expects |
| `keep` | Cooper's `CooperSecret`, untouched: redacted when printed, `->reveal()` at the point of use |
| `"App.Secret"` | the revealed, converted value handed to your class: `App\Secret::new($value)`, else `new App\Secret($value)` |

A string is a module name, exactly as `!module("...")` takes one:
dot-separated PascalCase, mapped through the `modules` option or
translated by convention (`App.Secret` -> `App\Secret`). It is for a
codebase that owns a secret type and does not want Cooper's in its
configuration contracts.

The key is removed -- it is this library's, not the application's -- and
governs every depth of its block and no other block. It is refused, as
a load error:

- **at the document root**, where a key names an application: it would
  configure one called `cooper-secrets` and govern nothing;
- **nested deeper** than the top of an application's block: one further
  down would read as governing a subtree while governing nothing;
- **with an unknown value** (`cooper-secrets = hide`);
- **naming a class** that cannot be loaded, or has neither a public
  static `new()` nor a public constructor taking one argument.

It is a key in the document rather than a load option because the
document is the one thing every way of loading it shares.

## The compiled cache

PHP is shared-nothing: every request starts empty, and Cooper's own
cache lives in process memory. Loading eagerly would mean parsing every
document on every request. So, on by default, a successful load is
written as a PHP file -- `<?php return [...];` -- that opcache serves
from shared memory, and the next request includes it instead of
parsing.

**It is used only while still valid.** The file records what its load
depended on and checks it first:

- every file the load read (the document and its imports), by
  modification time and size;
- every import's expansion (`import "env/*.casc"`), expanded again by
  Cooper -- so a file added where a glob looks, or removed from there,
  counts even though the load never read it;
- every environment variable the document read -- values, `${?NAME}`
  guards, import paths, `${COOPER_ENV}` -- as read now, `.env` files
  included, compared against a salted HMAC of the value it was built
  with. The values themselves are never written.

Anything different, and the document is loaded afresh and the file
rewritten.

**Not compiled**, and loaded afresh every time: a load that called a
`resolvers` entry (its answer could change any time and has nothing to
fingerprint), a load given `importSchemes`, one holding a value the
compiler cannot write as PHP (an object from a consumer tag; enum cases
are fine), and one that read a file modified within the last second --
a second edit in the same second would keep the modification time;
the next load compiles it.

**Baked in, and not fingerprinted**: what consumer `tags` and
`!module(...)` returned. The `modules` mapping and the tag names are
part of the file's name, so a different mapping gets a different file;
a changed tag *implementation* needs `cache:clear`, which belongs in
every deploy anyway.

### Security

The compiled file holds the configuration as the application sees it
-- **revealed secrets included** -- and it is code PHP runs. So:

- it is written atomically (a temporary file renamed over it), mode
  `0600`, in a directory created `0700`, and opcache's copy is
  invalidated after each write;
- it is never included unless owned by the process's own user and
  writable by nobody else -- otherwise it is ignored and rewritten;
- values are written as PHP literals and constructor calls, never
  through `unserialize()`;
- **keep the cache directory out of the web root, out of version
  control, and out of any backup less protected than the secrets
  themselves.** `<root>/var/` is the conventional place, already
  ignored by most PHP projects.

Run `vendor/bin/cooper-config cache:clear` on deploy.

## Environments: `COOPER_ENV`

Cooper always sets `${COOPER_ENV}` (CASC.md §7.2): a real `COOPER_ENV`
wins; unset or empty, PHP's own name for the same thing, `APP_ENV`
(from `.env` files or the environment), else `dev`. The `APP_ENV`
fallback is mapped onto the names every Cooper uses -- `development` and
`local` become `dev`, `testing` becomes `test`, `production` becomes
`prod`, anything else is used unchanged -- so Laravel's values select
`dev/`, `test/` and `prod/` as they are. A real `COOPER_ENV` is never
mapped. The same name picks the `.env.<env>` file Cooper reads:
`.env.dev`, `.env.test`, `.env.prod` -- never Laravel's
`.env.production` or `.env.testing`. This library adds nothing to it. The layout `init` scaffolds selects one directory per
environment:

```text
config/
  config.casc      shared settings; ends with import "${COOPER_ENV}/*.casc"
  dev/app.casc
  test/app.casc
  prod/app.casc    may stay empty, but must exist
```

- **Every environment needs its directory**, holding at least one
  `.casc`, so the glob matches: a typo in `COOPER_ENV` then fails loudly
  instead of silently skipping the overlay.
- **The import comes last**, so an environment overrides what is above
  it.
- Only `${...}` may appear in an import path: imports are resolved while
  the document is parsed, before `%{...}` could be.

## Command line

```sh
vendor/bin/cooper-config cooper:init          # scaffold config/; refuses to overwrite
vendor/bin/cooper-config cooper:check         # load and report; exit 1 on error
vendor/bin/cooper-config cooper:cache:clear   # remove every compiled file
```

The old names -- `init`, `check`, `cache:clear` -- still work in
`vendor/bin/cooper-config`, as aliases. `--root=DIR` names the project
root for every command; `check` takes `--path=FILE`, `cache:clear`
`--cache-dir=DIR` (`--root DIR` works too). `check` writes no compiled
file: a deploy step usually runs as another user than the web server,
which could then neither read nor replace a file it wrote.

### In a framework's console

Each command is a plain [Symfony Console](https://symfony.com/doc/current/components/console.html)
`Command` in `JOetjen\CooperConfig\Command` -- `InitCommand`,
`CheckCommand`, `CacheClearCommand` -- with no framework behind it, so
a Symfony bundle registers them with `bin/console` and a Laravel
package with artisan (whose commands are Symfony Console commands
underneath). What the host knows comes in through the constructor:

```php
new InitCommand($projectRoot);
new CheckCommand($projectRoot, $loadOptions);      // Config::load()'s options, as the app loads with
new CacheClearCommand($projectRoot, $cacheDir);    // where the app keeps the compiled cache
```

Every argument is optional (`null` is the Composer root package's
directory, and `<root>/var/cache/cooper-config`), and `--root`,
`--path` and `--cache-dir` outrank them. The `init`/`check`/
`cache:clear` aliases are the standalone script's own: registered
elsewhere, the commands go by their `cooper:` names only.

## Writing CASC

`JOetjen\CooperConfig\Casc\Writer` writes a PHP value tree as a CASC
document that loads back as the same values -- for a tool that converts
an existing configuration (a framework's `config/*.php`) or generates
one.

```php
use JOetjen\CooperConfig\Casc\{Commented, MapValue, Raw, Writer};

echo (new Writer())->write([
    'database' => [
        'host' => new Commented(new Raw('${DB_HOST:"127.0.0.1"}'), 'was: env("DB_HOST")'),
        'port' => 5432,
    ],
    'greeting' => "it's \${name}",
]);
```

```casc
#@version = 1.0

@*casc_writer_dollar = '$'

database {
  # was: env("DB_HOST")
  host = ${DB_HOST:"127.0.0.1"}

  port = 5432
}

greeting = "it's @{casc_writer_dollar}{name}"
```

- Maps become blocks, lists list literals (inline when they fit), in
  the order given. A map inside a list, at any depth, is a block
  element: `[{ host = "a" }, { host = "b" }]`, over several lines when
  it is long or holds a block or a comment.
- An empty array is `[]`: a PHP array cannot say whether it is an empty
  list or an empty map, and both load back as `[]`. Wrap it in
  `MapValue` to write a map -- `logger {}` -- which matters where the
  document is an overlay: written over a map already there, `{}` leaves
  it as it is, where `[]` replaces it. `MapValue` also writes an array
  keyed `0`, `1`, ... as a map.
- Strings are double-quoted and escaped -- single-quoted when they hold
  `${`, `@{`, `%{`, `!{` or `!Name(`, so nothing is interpolated by
  accident. Text holding one of those *and* a `'` (or a control
  character) fits neither quoting, and no escape keeps `${` literal, so
  the opener's first character comes from a private helper variable
  declared under the header, as above.
- Keys that are CASC identifiers (and not `nil`, `true`, `false`, `inf`,
  `import`, `for`) are bare; any other is quoted.
- `Raw` passes CASC source through as written (`${...}`, `!int(...)`,
  `!module("Foo.Bar")`, `5m`, an atom); `Commented` puts `# ` lines
  above an entry. `write($tree, $comment)` puts a comment under the
  header.
- An object, a resource, `NAN` and text that is not UTF-8 are refused
  with an `\InvalidArgumentException` naming where they are.

## Where to go next

- [`CHEATSHEET.md`](CHEATSHEET.md) -- the API on one page.
- [`CHANGELOG.md`](CHANGELOG.md) -- what changed.
- [`php-cooper`](https://github.com/joetjen/php-cooper) and its
  [CASC reference](https://github.com/joetjen/php-cooper/blob/main/casc/CASC.md) -- everything about the
  language and the library that loads it.

## Development

```sh
composer install
composer precommit   # PHPStan level 8, then PHPUnit
```

## License

Apache-2.0 -- see [LICENSE](LICENSE).
