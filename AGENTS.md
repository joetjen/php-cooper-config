# AGENTS.md

Instructions for AI agents working in this PHP codebase.

## Communication

- Every response starts with the user's first name. Determine it from `git config user.name` (take the first name); if that's unavailable or ambiguous, ask once. Remember the answer for the rest of the session rather than re-deriving or re-asking.

## Before every commit

- Run `composer run precommit` and make sure it passes. No exceptions. It expands to `vendor/bin/phpstan analyse src --level=8` followed by `phpunit`.

## Tests

- Tests are written first: a failing test, then the change that makes it pass. Test names read as sentences (`testAMissingDefaultFileLoadsAnEmptyConfiguration`).
- Tests must stay current with behavior: a change to what code does needs its tests updated in the same commit, not "later".
- Put new tests in the file that matches what you're touching, mirroring `src/`'s own layout under `tests/`. Add a new test file only when a change doesn't fit any existing one.
- Where inputs form a space bigger than a handful of examples usefully covers (conversion, path lookup, the PHP exporter), prefer a property-based test -- a hand-rolled randomized loop inside PHPUnit, seeded with `mt_srand()` so a failure reproduces, as `php-cooper` does. Use ordinary example-based tests for fixed scenarios and regressions.
- The configuration is process-wide state: every test starts and ends with `Config::unload()` (`ConfigTestCase` does it), and writes only into its own scratch project root.

## Documentation

- Treat every documentation surface touched by a change as part of that change: PHPDoc blocks, `README.md`, `CHEATSHEET.md`, `CHANGELOG.md`, and comments explaining non-obvious behavior. Comments explain *why*.
- Every public class needs a class-level PHPDoc block. Every public method needs a `@param`/`@return`/`@throws`-annotated PHPDoc block.
- Before documenting any example or claim about behavior, run it.
- Update `CHANGELOG.md` for every user-facing change, following [Keep a Changelog](https://keepachangelog.com/): entries under `[Unreleased]` as you work, moved under a version heading on release.

## Parity across implementations

- This project mirrors the Elixir `cooper_config` and the Praxis `cooper_prx`: the same conversion rules, the same `cooper-secrets` policy and refusal messages. Where PHP forces a different call (an address as text, a tuple as a list, the compiled cache), `README.md` says so and the code explains it at the point of difference.
- CASC itself is `joetjen/cooper`'s. Nothing here parses, resolves, or extends the language; a need that looks like it does belongs in `php-cooper`.

## Eager only

- The configuration is loaded once, at startup, by `Config::load()`. Never add lazy or on-first-access loading: reading before loading is an error by design.

## Static analysis

- `vendor/bin/phpstan analyse src --level=8` is part of `composer run precommit` -- keep it clean, with a specific justification for anything that must be ignored, never a blanket one.

## Coding style

- Follow `.editorconfig` (4-space indent, PSR-12). `declare(strict_types=1)` in every PHP file.
- Target PHP 8.2+ (`composer.json`'s `require.php`).
- Classes that are not part of the public API are marked `@internal`.

## Git workflow

- Use [git flow](https://nvie.com/posts/a-successful-git-branching-model/): `main` (releases), `develop` (integration), `feature/*`, `release/*`, `hotfix/*`, `support/*`.
- No direct commits to `main` or `develop`. Branch, then merge/PR back. Base pull requests on `develop` unless you're specifically preparing a release.

## Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/): `<type>[optional scope]: <description>`.
- Breaking changes: `!` after type/scope, or a `BREAKING CHANGE:` footer.

## Versioning

- Use [Semantic Versioning](https://semver.org/). Tags match the changelog entry when tagging a release.

## License

- Apache-2.0, never MIT.
