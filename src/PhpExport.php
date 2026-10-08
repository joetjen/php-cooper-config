<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig;

use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperBytes;
use JOetjen\Cooper\Value\CooperDate;
use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperIPv4;
use JOetjen\Cooper\Value\CooperIPv6;
use JOetjen\Cooper\Value\CooperLocalDateTime;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\Cooper\Value\CooperTime;
use JOetjen\Cooper\Value\CooperTuple;

/**
 * Writes a converted configuration as a PHP expression that rebuilds it
 * -- the body of the compiled cache file.
 *
 * **Code, not data.** Scalars and arrays are written as literals, which
 * PHP compiles to constant arrays opcache keeps in shared memory. An
 * object is written as the constructor call that made it
 * (`new \JOetjen\Cooper\Value\CooperDate(1979, 5, 27)`) rather than
 * through `var_export()`'s `__set_state()`, which Cooper's value classes
 * do not implement, or `unserialize()`, which would put the one PHP
 * primitive known for object-injection attacks on every request's path.
 * An application's secret class (`WrappedSecret`) is written as the very
 * call a fresh load makes.
 *
 * Only what this class knows how to rebuild is written; anything else --
 * an object from a consumer tag, a closure -- is `NotExportable`, and
 * that configuration is simply not compiled. An enum case is the one
 * foreign value admitted: it is rebuilt by name, never constructed.
 *
 * Every string is written through `var_export()`, a single-quoted
 * literal in which only `\` and `'` are special, so no value from a
 * document can ever become syntax.
 *
 * @internal
 */
final class PhpExport
{
    private const VALUE = '\\JOetjen\\Cooper\\Value\\';

    private function __construct()
    {
    }

    /**
     * @throws NotExportable when `$value` holds something this cannot rebuild
     */
    public static function export(mixed $value): string
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => var_export($value, true),
            is_float($value) => self::float($value),
            is_array($value) => self::array($value),
            is_object($value) => self::object($value),
            default => throw new NotExportable('cannot write a ' . get_debug_type($value) . ' as PHP'),
        };
    }

    private static function float(float $value): string
    {
        // `var_export()` writes `INF`/`NAN` unqualified, which inside a
        // namespaced file would still resolve -- but say what is meant.
        return match (true) {
            is_nan($value) => '\\NAN',
            is_infinite($value) => $value > 0 ? '\\INF' : '-\\INF',
            default => var_export($value, true),
        };
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function array(array $value): string
    {
        $items = [];
        foreach ($value as $key => $item) {
            $items[] = var_export($key, true) . ' => ' . self::export($item);
        }

        return '[' . implode(', ', $items) . ']';
    }

    private static function object(object $value): string
    {
        $v = self::VALUE;

        return match (true) {
            $value instanceof WrappedSecret => self::wrapped($value),
            $value instanceof CooperSecret => "new {$v}CooperSecret(" . self::export($value->reveal()) . ', ' . self::export($value->redacted) . ')',
            $value instanceof CooperInteger => "new {$v}CooperInteger(" . var_export($value->digits, true) . ')',
            $value instanceof CooperAtom => "{$v}CooperAtom::of(" . var_export($value->name, true) . ')',
            $value instanceof CooperTuple => "new {$v}CooperTuple(" . self::export($value->items) . ')',
            $value instanceof CooperDuration => "new {$v}CooperDuration(" . self::export($value->nanoseconds) . ')',
            $value instanceof CooperBytes => "new {$v}CooperBytes(" . self::export($value->bytes) . ')',
            $value instanceof CooperIPv4 => "new {$v}CooperIPv4(" . self::export($value->address) . ', ' . self::export($value->prefix) . ')',
            $value instanceof CooperIPv6 => "new {$v}CooperIPv6(" . self::export($value->address) . ', ' . self::export($value->prefix) . ')',
            $value instanceof CooperDate => "new {$v}CooperDate({$value->year}, {$value->month}, {$value->day})",
            $value instanceof CooperTime => "new {$v}CooperTime({$value->hour}, {$value->minute}, {$value->second}, {$value->microsecond}, {$value->precision})",
            $value instanceof CooperLocalDateTime => "new {$v}CooperLocalDateTime(" . self::export($value->date) . ', ' . self::export($value->time) . ')',
            $value instanceof CooperDateTime => "new {$v}CooperDateTime(new \\DateTimeImmutable("
                . var_export($value->instant->format('Y-m-d\TH:i:s.uP'), true) . "), {$value->precision})",
            $value instanceof \UnitEnum => '\\' . $value::class . '::' . $value->name,
            default => throw new NotExportable('cannot write a ' . get_debug_type($value) . ' as PHP'),
        };
    }

    private static function wrapped(WrappedSecret $secret): string
    {
        // The class came from a `class_exists()` check, but it is written
        // into code: refuse anything that is not plainly a class name.
        if (preg_match('/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/', $secret->class) !== 1) {
            throw new NotExportable("cannot write a call to {$secret->class}");
        }
        $class = '\\' . ltrim($secret->class, '\\');
        $value = self::export($secret->value);

        return $secret->viaNew ? "{$class}::new({$value})" : "new {$class}({$value})";
    }
}
