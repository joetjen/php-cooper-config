# Cheatsheet

php-cooper-config on one page. The [README](README.md) explains the
why; [CASC.md](https://github.com/joetjen/php-cooper/blob/main/casc/CASC.md) is the language.

## Load -- once, at startup

```php
use JOetjen\CooperConfig\Config;

Config::load(array $options = []): void
// ConfigError (->path, ->getPrevious(): CooperError|ConversionError) on failure; nothing stored
// \InvalidArgumentException on an unknown or mistyped option
```

| Option | Default | |
|---|---|---|
| `path` | `config/config.casc` | relative to `root`; missing default = empty config, missing explicit = `ConfigError` |
| `root` | Composer root package dir | the project root; `config/config.casc` is found under it |
| `compiledCache` | `true` | `false`, or a directory (default `<root>/var/cache/cooper-config`) |
| `env`, `dotenv`, `dotenvEnv`, `dotenvFiles`, `dotenvDir`, `dotenvOverride` | Cooper's | passed through; `.env` read from the project root unless `dotenvDir` is given |
| `resolvers`, `tags`, `importSchemes`, `modules`, `cache` | Cooper's | passed through |

## Read

```php
Config::get('a.b.c', $default)        // or ['a', 'b', 'c']; default when missing
Config::require('a.b')                // MissingKeyError naming the path
Config::has('a.b')                    // nil counts as present
Config::all()
cooper_config()                       // all()
cooper_config('a.b', $default)        // get()
Config::isLoaded(); Config::report(); Config::unload();
// before load(): NotLoadedError, saying to call Config::load() at startup
```

## Conversion

| CASC | PHP |
|---|---|
| `1GiB` | `1073741824` (`CooperInteger` beyond 64 bits) |
| `5s`, `500ms` | `5000`, `500` -- milliseconds; `1500us` is a load error |
| `10.0.0.1`, `10.0.0.0/8`, `::1` | `"10.0.0.1"`, `"10.0.0.0/8"`, `"::1"` |
| `(1, 2)` | `[1, 2]` |
| maps, lists | arrays, walked |
| anything else | as Cooper returns it |

## Secrets

```casc
app {
  cooper-secrets = keep     # reveal (default) | keep | "App.Secret"
  *password = ${DB_PASSWORD}
}
```

`reveal` -> the value; `keep` -> `CooperSecret`; `"App.Secret"` ->
`App\Secret::new($value)`, else `new App\Secret($value)` (name mapped by
`modules`, else by convention). Removed from the result. Refused at the
root, nested, unknown, or naming a class that cannot wrap.

## Compiled cache

`<root>/var/cache/cooper-config/cooper-config-<hash>.php`, mode `0600`,
holds revealed secrets. Valid while every file read keeps its mtime and
size, every import pattern matches the same files, and every env
variable read keeps its value
(salted HMAC). Skipped when a resolver was called, `importSchemes` is
given, a value cannot be written as PHP, or a file is under a second
old.

## Environments

```text
config/config.casc     ... import "${COOPER_ENV}/*.casc"   (last)
config/{dev,test,prod}/app.casc
```

`COOPER_ENV` (as is), else `APP_ENV` (`development`/`local` → `dev`,
`testing` → `test`, `production` → `prod`, anything else unchanged),
else `dev`. It names the `.env.<env>` file too: `.env.dev`, `.env.test`,
`.env.prod`.

## CLI

```sh
vendor/bin/cooper-config cooper:init [--root=DIR]                        # alias: init
vendor/bin/cooper-config cooper:check [--root=DIR] [--path=FILE]         # alias: check
vendor/bin/cooper-config cooper:cache:clear [--root=DIR] [--cache-dir=DIR] # alias: cache:clear
```

Symfony Console commands, for `bin/console` or artisan (no aliases there):

```php
use JOetjen\CooperConfig\Command\{InitCommand, CheckCommand, CacheClearCommand};

new InitCommand(?string $projectRoot = null);
new CheckCommand(?string $projectRoot = null, array $loadOptions = []);   // root/compiledCache ignored
new CacheClearCommand(?string $projectRoot = null, ?string $cacheDir = null);
// --root, --path, --cache-dir outrank the constructor
```

## Writing CASC

```php
use JOetjen\CooperConfig\Casc\{Commented, MapValue, Raw, Writer};

(new Writer(string $version = '1.0', string $indent = '  '))->write(array $tree, string $comment = ''): string
new Raw('${DB_HOST:"127.0.0.1"}')          // written as is
new Commented($value, 'was: env("X")')     // "# was: ..." above the key
new MapValue([])                           // a map, even empty: `{}`, not `[]`
```

| PHP | CASC |
|---|---|
| map / list / `[]` | block / list literal / `[]` |
| list holding a map | list of blocks, `[{ ... }, { ... }]`, at any depth |
| `MapValue([])`, `MapValue(['a'])` | `{}`, `{ "0" = "a" }` -- a map whatever its shape |
| `"text"` | `"text"`; `'${x}'` when it holds an opener; helper `@{casc_writer_*}` when it also holds `'` |
| `1.0`, `INF`, `null` | `1.0`, `inf`, `nil` |
| key `a.b`, `nil`, `1` | `"a.b"`, `"nil"`, `"1"` |
| `NAN`, object, resource, non-UTF-8 | `\InvalidArgumentException` naming where |
