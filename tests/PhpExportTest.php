<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests;

use JOetjen\Cooper\Cooper;
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
use JOetjen\Cooper\Values;
use JOetjen\CooperConfig\NotExportable;
use JOetjen\CooperConfig\PhpExport;
use JOetjen\CooperConfig\Tests\Support\ConstructedSecret;
use JOetjen\CooperConfig\Tests\Support\Level;
use JOetjen\CooperConfig\Tests\Support\OwnedSecret;
use JOetjen\CooperConfig\WrappedSecret;
use PHPUnit\Framework\TestCase;

/**
 * `PhpExport`: a converted configuration written as PHP source that
 * rebuilds it -- what the compiled cache stores. Every value Cooper can
 * produce must come back equal, and anything else must be refused
 * rather than written as something it is not.
 */
final class PhpExportTest extends TestCase
{
    private static function roundTrip(mixed $value): mixed
    {
        return eval('return ' . PhpExport::export($value) . ';');
    }

    public function testScalarsComeBackIdentical(): void
    {
        foreach ([null, true, false, 0, -1, PHP_INT_MAX, PHP_INT_MIN, 0.1, -0.0, 1e300, INF, -INF, '', 'text'] as $value) {
            self::assertSame($value, self::roundTrip($value), var_export($value, true));
        }
        self::assertNan(self::roundTrip(NAN));
    }

    public function testAnyStringComesBackByteForByte(): void
    {
        // Quotes, backslashes, a closing tag, and NUL are all data, never
        // syntax.
        $nasty = "it's \\ \"quoted\" ?> <?php \0 \n \r \$x {\$y} \u{1F600}";

        self::assertSame($nasty, self::roundTrip($nasty));
    }

    public function testArraysKeepTheirKeysAndOrder(): void
    {
        $value = ['b' => 1, 'a' => [3, 2, 1], 7 => 'seven', '' => 'empty key', "q'k" => ['x' => null]];

        self::assertSame($value, self::roundTrip($value));
    }

    public function testEveryCooperValueComesBackEqual(): void
    {
        $values = Cooper::loadString(<<<'CASC'
            #@version = 1.0
            atom = info
            big = 100000000000000000000
            duration = 1500us
            bytes = 1KiB
            bigBytes = 100000000000000000000B
            v4 = 10.0.0.0/8
            v6 = 2001:db8::/32
            day = 0000-02-29
            at = 07:32:00.120
            local = 1979-05-27T07:32:00.5
            instant = 1979-05-27T07:32:00.120+02:00
            tuple = (1, "a", (2, 3))
            *secret = "hunter2"
            partial = "user:%{secret}@host"
            CASC, ['dotenv' => false]);

        $back = self::roundTrip($values);

        self::assertTrue(Values::equals($values, $back));
        self::assertSame('hunter2', $back['secret']->reveal());
        self::assertSame((string) $values['partial'], (string) $back['partial']);
        self::assertSame($values['partial']->reveal(), $back['partial']->reveal());
        self::assertSame($values['instant']->toIso8601(), $back['instant']->toIso8601());
        self::assertSame(3, $back['instant']->precision);
    }

    public function testAnAtomComesBackAsTheSameInternedAtom(): void
    {
        self::assertSame(CooperAtom::of('info'), self::roundTrip(CooperAtom::of('info')));
    }

    public function testAWrappedSecretIsRebuiltThroughItsNew(): void
    {
        $back = self::roundTrip(new WrappedSecret(OwnedSecret::class, true, 'hunter2'));

        self::assertInstanceOf(OwnedSecret::class, $back);
        self::assertSame('hunter2', $back->value);
    }

    public function testAWrappedSecretIsRebuiltThroughItsConstructor(): void
    {
        $back = self::roundTrip(new WrappedSecret(ConstructedSecret::class, false, ['nested' => 1]));

        self::assertInstanceOf(ConstructedSecret::class, $back);
        self::assertSame(['nested' => 1], $back->value);
    }

    public function testAnEnumCaseComesBackAsTheSameCase(): void
    {
        self::assertSame(Level::High, self::roundTrip(Level::High));
    }

    public function testAnyOtherObjectIsRefusedNamingItsType(): void
    {
        try {
            PhpExport::export(['a' => [new \ArrayObject()]]);
            self::fail('an ArrayObject was exported');
        } catch (NotExportable $e) {
            self::assertStringContainsString('ArrayObject', $e->getMessage());
        }
    }

    public function testAClosureOrResourceIsRefused(): void
    {
        $this->expectException(NotExportable::class);

        PhpExport::export(static fn (): int => 1);
    }

    public function testArbitraryValuesSurviveTheRoundTrip(): void
    {
        // Property-style (hand-rolled, seeded): random nested values built
        // from everything the compiled cache may hold come back equal.
        mt_srand(77);

        for ($i = 0; $i < 300; $i++) {
            $value = self::randomValue(3);
            self::assertTrue(Values::equals($value, self::roundTrip($value)), var_export($value, true));
        }
    }

    private static function randomValue(int $depth): mixed
    {
        $roll = mt_rand(0, $depth > 0 ? 17 : 13);

        return match ($roll) {
            0 => null,
            1 => mt_rand(0, 1) === 1,
            2 => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            3 => mt_rand() / mt_getrandmax() * 10 ** mt_rand(-300, 300),
            4 => mt_rand(0, 3) === 0 ? '' : random_bytes(mt_rand(1, 12)),
            5 => CooperAtom::of('a' . mt_rand(0, 9)),
            6 => new CooperInteger((string) mt_rand(1, 9) . str_repeat('0', 25)),
            7 => new CooperDuration(mt_rand(0, PHP_INT_MAX)),
            8 => new CooperBytes(mt_rand(0, PHP_INT_MAX)),
            9 => new CooperIPv4([mt_rand(0, 255), 0, 0, mt_rand(0, 255)], mt_rand(0, 1) === 1 ? 24 : null),
            10 => new CooperIPv6([mt_rand(0, 0xFFFF), 0, 0, 0, 0, 0, 0, 1], null),
            11 => new CooperDate(mt_rand(0, 9999), mt_rand(1, 12), mt_rand(1, 28)),
            12 => new CooperLocalDateTime(new CooperDate(2020, 1, 2), new CooperTime(mt_rand(0, 23), 5, 6, mt_rand(0, 999999), 6)),
            13 => CooperDateTime::parse(sprintf('2001-02-03T04:05:%02d.%03dZ', mt_rand(0, 59), mt_rand(0, 999))),
            14 => new CooperSecret(self::randomValue($depth - 1), mt_rand(0, 1) === 1 ? 'shown [~~REDACTED~~]' : null),
            15 => new CooperTuple([self::randomValue($depth - 1), self::randomValue($depth - 1)]),
            16 => [self::randomValue($depth - 1), self::randomValue($depth - 1)],
            default => ['k' . mt_rand(0, 9) => self::randomValue($depth - 1), 'z' => self::randomValue($depth - 1)],
        };
    }
}
