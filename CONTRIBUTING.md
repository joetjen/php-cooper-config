# Contributing to php-cooper-config

Thanks for considering a contribution. This document covers what you
need to know before opening an issue or a pull request.

## Getting started

```sh
git clone https://github.com/joetjen/php-cooper-config.git
cd php-cooper-config
composer install
composer test
```

That should complete with no failures on a clean checkout. If it
doesn't, please open an issue before doing anything else -- that's a
bug in its own right.

## Project layout

- `src/Config.php` -- the public API: `load()` and the accessors.
- `src/functions.php` -- the global `cooper_config()` helper.
- `src/Convert.php` -- Cooper's result into application configuration,
  the `cooper-secrets` policy included; `src/WrappedSecret.php` is a
  secret waiting for an application's class.
- `src/CompiledCache.php`, `src/PhpExport.php` -- the compiled cache and
  the PHP source it is written as.
- `src/Options.php`, `src/ProjectRoot.php`, `src/LoadReport.php` --
  options, the default root, and what a load did.
- `src/ConfigError.php`, `src/ConversionError.php`,
  `src/NotLoadedError.php`, `src/MissingKeyError.php`,
  `src/NotExportable.php` -- the exceptions.
- `src/Cli.php`, `bin/cooper-config` -- the command line.
- `tests/` -- PHPUnit, mirroring `src/`; `tests/Support/` holds the
  shared test case and the classes tests wrap secrets with.

## Making a change

1. **Tests first.** A failing test that says, as a sentence, what
   should happen; then the change.
2. **Check the references.** A change to conversion or the secrets
   policy should match the Elixir `cooper_config` and the Praxis
   `cooper_prx`, or say in `README.md` why PHP differs.
3. **Property tests where the input space is large** -- a seeded,
   hand-rolled randomized loop inside PHPUnit.
4. **Run the full verification pass before opening a PR:**

   ```sh
   composer run precommit
   ```

5. **Keep documentation current.** PHPDoc, `README.md`,
   `CHEATSHEET.md`, and `CHANGELOG.md` (`[Unreleased]`) in the same
   commit. Before documenting any example, run it.

## Reporting bugs

Please include the CASC document (or a minimal excerpt), the options
you passed, what you expected, and what happened -- including the full
`ConfigError` message, which carries Cooper's own.

## License

By contributing, you agree that your contributions will be licensed
under the project's [Apache License 2.0](LICENSE).
