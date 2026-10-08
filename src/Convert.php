<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\CooperError;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperIPv4;
use JOetjen\Cooper\Value\CooperIPv6;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * Turns what `Cooper` loaded into application configuration -- the
 * counterpart of the Elixir `CooperConfig.Convert` and the Praxis
 * `cooper.config/as-app-config`.
 *
 * ## Typed literals arrive untagged
 *
 * Application configuration is read by code that has never heard of
 * Cooper -- a database client, an HTTP client, a framework option -- so
 * Cooper's measurements lose their wrapper on the way in:
 *
 * | CASC | Cooper | application configuration |
 * |---|---|---|
 * | `1GiB` | `CooperBytes` | `1073741824` (bytes; `CooperInteger` beyond 64 bits) |
 * | `14d` | `CooperDuration` | `1209600000` (milliseconds; `CooperInteger` beyond 64 bits) |
 * | `10.0.0.1` | `CooperIPv4` | `"10.0.0.1"` |
 * | `10.0.0.0/8` | `CooperIPv4` with a prefix | `"10.0.0.0/8"` |
 * | `(1, "a")` | `CooperTuple` | `[1, "a"]` |
 *
 * IPv6 follows IPv4. **An address becomes its text** where the
 * references give the BEAM's address tuple: text is what PHP's own
 * socket, stream, and filter functions take. **A tuple becomes a list
 * array** where the references keep a tuple: PHP has no tuple type, a
 * `CooperTuple` is Cooper's, and a list is the nearest value PHP code
 * already knows how to read. Its elements are converted too.
 *
 * Durations become **milliseconds**, the unit most PHP timeouts and
 * TTLs take beside seconds (and the unit the references chose). One
 * that is not a whole number of milliseconds (`1500us`) is refused
 * rather than rounded: a silently shortened timeout is harder to find
 * than a failed boot.
 *
 * Maps (associative arrays) and lists are walked at every depth; dates,
 * times, atoms, big integers, and everything else pass through as
 * Cooper returns them -- each is a value, not a tag on one.
 *
 * ## Secrets: `cooper-secrets`
 *
 * A top-level block is one application (or one part of one), and it
 * says how its own secrets arrive under the key `cooper-secrets`:
 *
 *  - absent, or `reveal` -- revealed: configuration has always held raw
 *    values, and what reads it expects one;
 *  - `keep` -- left as Cooper's `CooperSecret`, untouched, for code that
 *    reveals at the point of use;
 *  - a string -- a module name, written as `!module("...")` takes one
 *    (dot-separated PascalCase, mapped through the `modules` option or
 *    translated by convention, `App.Secret` -> `App\Secret`): each secret
 *    is revealed, converted, and handed to that class's static `new()`,
 *    else its constructor -- for a codebase that owns a secret type.
 *
 * The key is removed: it is this library's, not the application's. It
 * governs every depth of its block and nothing else, and is refused at
 * the document root (where it would name an application), nested
 * deeper, with an unknown value, or naming a class that cannot wrap.
 * It is a key in the document rather than an option because the
 * document is the one place every way of loading it shares.
 */
final class Convert
{
    /**
     * The key an application states its secret handling under. Named for
     * the library, so an application can still have a setting called
     * `secrets` of its own.
     */
    public const POLICY_KEY = 'cooper-secrets';

    /** Where `resolveModule()` passes the name to Cooper, so no CASC source is ever built from it. */
    private const MODULE_VARIABLE = 'COOPER_CONFIG_SECRET_MODULE';

    private function __construct()
    {
    }

    /**
     * Converts a `Cooper::loadFile()`/`loadString()` result into
     * application configuration (see the class documentation).
     *
     * @param array<array-key, mixed> $config what Cooper loaded
     * @param array<string, mixed> $modules the `modules` option the document was loaded with, for `cooper-secrets = "Name"`
     * @return array<array-key, mixed> the application configuration
     * @throws ConversionError on a duration that is not whole milliseconds, or a refused `cooper-secrets`
     */
    public static function toAppConfig(array $config, array $modules = []): array
    {
        $materialized = self::materialize(self::plan($config, $modules));
        assert(is_array($materialized));

        return $materialized;
    }

    /**
     * `toAppConfig()` short of calling an application's secret class:
     * each such secret is a `WrappedSecret` still, which the compiled
     * cache writes as the call itself.
     *
     * @internal
     * @param array<array-key, mixed> $config
     * @param array<string, mixed> $modules
     * @return array<array-key, mixed>
     * @throws ConversionError
     */
    public static function plan(array $config, array $modules = []): array
    {
        // At the root a key names an application, so a policy written here
        // would configure one called `cooper-secrets` and govern nothing --
        // silently, which is the one outcome worse than a refusal.
        if (array_key_exists(self::POLICY_KEY, $config)) {
            throw new ConversionError(
                '"' . self::POLICY_KEY . '" is at the root of the document, where a key names an application: '
                . 'write it at the top of the block of each application whose secrets it governs'
            );
        }

        $out = [];
        foreach ($config as $key => $settings) {
            $mode = self::policyOf($settings, $modules);
            if (is_array($settings)) {
                unset($settings[self::POLICY_KEY]);
            }
            $out[$key] = self::converted($settings, $mode, [$key]);
        }

        return $out;
    }

    /**
     * Makes every `WrappedSecret` call `plan()` left for later.
     *
     * @internal
     */
    public static function materialize(mixed $value): mixed
    {
        if ($value instanceof WrappedSecret) {
            return $value->wrap(self::materialize($value->value));
        }
        if (is_array($value)) {
            return array_map(self::materialize(...), $value);
        }

        return $value;
    }

    /**
     * How the application whose block is `$settings` wants its secrets:
     * `'reveal'`, `'keep'`, or the class to wrap with and whether through
     * `new()`.
     *
     * @param array<string, mixed> $modules
     * @return 'reveal'|'keep'|array{0: class-string, 1: bool}
     * @throws ConversionError
     */
    private static function policyOf(mixed $settings, array $modules): string|array
    {
        if (!is_array($settings) || !array_key_exists(self::POLICY_KEY, $settings)) {
            return 'reveal';
        }
        $said = $settings[self::POLICY_KEY];

        return match (true) {
            $said instanceof CooperAtom && $said->name === 'reveal' => 'reveal',
            $said instanceof CooperAtom && $said->name === 'keep' => 'keep',
            is_string($said) => self::wrapperFor($said, $modules),
            default => throw new ConversionError(
                '"' . self::POLICY_KEY . '" is ' . self::describe($said) . ', which is no policy: '
                . 'write reveal or keep, or a string naming a module whose new wraps each secret'
            ),
        };
    }

    /**
     * The class `$name` names, and whether it wraps through `new()`
     * (preferred, as the references call it) or its constructor.
     *
     * Checked when the policy is read, not when the first secret meets
     * it, so a typo is found the first time the document loads.
     *
     * @param array<string, mixed> $modules
     * @return array{0: class-string, 1: bool}
     * @throws ConversionError
     */
    private static function wrapperFor(string $name, array $modules): array
    {
        $class = self::resolveModule($name, $modules);
        if (!class_exists($class)) {
            throw new ConversionError('"' . self::POLICY_KEY . "\" names {$name}, but no class {$class} could be loaded");
        }

        $reflection = new \ReflectionClass($class);
        if ($reflection->hasMethod('new')) {
            $new = $reflection->getMethod('new');
            if ($new->isStatic() && $new->isPublic() && self::takesOneArgument($new)) {
                return [$class, true];
            }
        }
        $constructor = $reflection->getConstructor();
        if ($reflection->isInstantiable() && $constructor !== null && self::takesOneArgument($constructor)) {
            return [$class, false];
        }

        throw new ConversionError(
            '"' . self::POLICY_KEY . "\" names {$name}, which has no static new and no constructor of one argument "
            . '-- a class wrapping a secret needs one'
        );
    }

    /**
     * `$name` exactly as `!module("...")` would give it back: through
     * the `modules` option, else Cooper's convention. Cooper itself is
     * asked -- with the name passed in through the environment, never
     * spliced into source -- so the two can never disagree about what a
     * module name is.
     *
     * @param array<string, mixed> $modules
     * @throws ConversionError
     */
    private static function resolveModule(string $name, array $modules): string
    {
        $noName = '"' . self::POLICY_KEY . '" names ' . json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ', which is no module name';
        // `${...}` reads an empty value as unset, which would come back as
        // an undefined reference rather than as what is wrong.
        if (trim($name) === '') {
            throw new ConversionError($noName);
        }

        try {
            $loaded = Cooper::loadString(
                "#@version = 1.0\nmodule = !module(\${" . self::MODULE_VARIABLE . "})\n",
                ['dotenv' => false, 'env' => [self::MODULE_VARIABLE => $name], 'modules' => $modules],
            );
        } catch (CooperError $e) {
            throw new ConversionError("{$noName}: {$e->reason}", 0, $e);
        }

        $class = $loaded['module'] ?? null;
        if (!is_string($class)) {
            throw new ConversionError(
                '"' . self::POLICY_KEY . "\" names {$name}, which the modules option maps to "
                . get_debug_type($class) . ', not a class name'
            );
        }

        return $class;
    }

    private static function takesOneArgument(\ReflectionFunctionAbstract $function): bool
    {
        return $function->getNumberOfRequiredParameters() <= 1
            && ($function->getNumberOfParameters() >= 1 || $function->isVariadic());
    }

    /**
     * One value, as application configuration holds it.
     *
     * @param 'reveal'|'keep'|array{0: class-string, 1: bool} $mode
     * @param list<array-key> $path where `$value` is, for error messages
     * @throws ConversionError
     */
    private static function converted(mixed $value, string|array $mode, array $path): mixed
    {
        return match (true) {
            $value instanceof CooperSecret => self::secret($value, $mode, $path),
            $value instanceof CooperBytes => $value->bytes,
            $value instanceof CooperDuration => self::milliseconds($value, $path),
            $value instanceof CooperIPv4, $value instanceof CooperIPv6 => (string) $value,
            $value instanceof CooperTuple => self::walk($value->items, $mode, $path),
            is_array($value) => self::walk($value, $mode, $path),
            default => $value,
        };
    }

    /**
     * @param array<array-key, mixed> $items
     * @param 'reveal'|'keep'|array{0: class-string, 1: bool} $mode
     * @param list<array-key> $path
     * @return array<array-key, mixed>
     * @throws ConversionError
     */
    private static function walk(array $items, string|array $mode, array $path): array
    {
        // Somebody who wrote the policy one level down meant it to do
        // something, and a key that reads as governing a subtree while
        // governing nothing is worse than a refusal naming where it is.
        if (array_key_exists(self::POLICY_KEY, $items)) {
            throw new ConversionError(
                '"' . self::POLICY_KEY . '" belongs at the top of an application\'s block and this one is nested: '
                . 'it governs every depth beneath the application it is written in, so one further down would govern nothing'
                . ' (at ' . implode('.', $path) . ')'
            );
        }

        $out = [];
        foreach ($items as $key => $item) {
            $out[$key] = self::converted($item, $mode, [...$path, $key]);
        }

        return $out;
    }

    /**
     * One secret, as the policy asks for it. A revealed value is
     * converted before it is wrapped: what the application's class
     * receives is a value, not a Cooper measurement.
     *
     * @param 'reveal'|'keep'|array{0: class-string, 1: bool} $mode
     * @param list<array-key> $path
     * @throws ConversionError
     */
    private static function secret(CooperSecret $secret, string|array $mode, array $path): mixed
    {
        if ($mode === 'keep') {
            return $secret;
        }
        $value = self::converted($secret->reveal(), $mode, $path);

        return $mode === 'reveal' ? $value : new WrappedSecret($mode[0], $mode[1], $value);
    }

    /**
     * @param list<array-key> $path
     * @throws ConversionError
     */
    private static function milliseconds(CooperDuration $duration, array $path): int|CooperInteger
    {
        $ns = $duration->nanoseconds;
        $digits = is_int($ns) ? (string) $ns : $ns->digits;
        if (ltrim(bcmod($digits, '1000000', 0), '-') !== '0') {
            throw new ConversionError(
                "a duration of {$digits}ns is not a whole number of milliseconds, which is the unit application "
                . 'configuration holds a duration in (at ' . implode('.', $path) . ')'
            );
        }

        return is_int($ns) ? intdiv($ns, 1_000_000) : CooperInteger::represent(bcdiv($digits, '1000000', 0));
    }

    /**
     * A policy value as the document wrote it, for the refusal.
     */
    private static function describe(mixed $value): string
    {
        return match (true) {
            $value instanceof CooperAtom => $value->name,
            $value === null => 'nil',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => array_is_list($value) ? 'a list' : 'a block',
            $value instanceof CooperSecret => 'a secret',
            default => get_debug_type($value),
        };
    }
}
