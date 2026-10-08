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
use JOetjen\Cooper\Value\CooperTime;
use JOetjen\Cooper\Value\CooperTuple;
use JOetjen\CooperConfig\ConversionError;
use JOetjen\CooperConfig\Convert;
use PHPUnit\Framework\TestCase;

/**
 * `Convert`: Cooper's typed literals arrive untagged, because what reads
 * application configuration has never heard of Cooper. Secrets have
 * their own suite (`SecretPolicyTest`).
 */
final class ConvertTest extends TestCase
{
    /**
     * @return array<array-key, mixed>
     */
    private static function converted(string $body): array
    {
        return Convert::toAppConfig(Cooper::loadString("#@version = 1.0\n{$body}", ['dotenv' => false]));
    }

    public function testAByteSizeBecomesItsByteCount(): void
    {
        self::assertSame(['app' => ['max' => 1073741824]], self::converted("app.max = 1GiB\n"));
    }

    public function testAByteSizeBeyondSixtyFourBitsStaysAWholeNumber(): void
    {
        $max = self::converted("max = 100000000000000000000B\n")['max'];

        self::assertInstanceOf(CooperInteger::class, $max);
        self::assertSame('100000000000000000000', $max->digits);
    }

    public function testADurationBecomesWholeMilliseconds(): void
    {
        self::assertSame(['ttl' => 1209600000, 'short' => 500, 'zero' => 0], self::converted("ttl = 14d\nshort = 500ms\nzero = 0s\n"));
    }

    public function testADurationBeyondSixtyFourBitsStaysAWholeNumberOfMilliseconds(): void
    {
        $value = Convert::toAppConfig(['d' => new CooperDuration(new CooperInteger('100000000000000000000000000'))])['d'];

        self::assertInstanceOf(CooperInteger::class, $value);
        self::assertSame('100000000000000000000', $value->digits);
    }

    public function testADurationThatIsNotWholeMillisecondsIsRefusedNamingIt(): void
    {
        // A silently shortened timeout is harder to find than a failed boot.
        try {
            self::converted("app.timeout = 1500us\n");
            self::fail('1500us was rounded');
        } catch (ConversionError $e) {
            self::assertStringContainsString('1500000ns', $e->getMessage());
            self::assertStringContainsString('milliseconds', $e->getMessage());
            self::assertStringContainsString('app.timeout', $e->getMessage());
        }
    }

    public function testAnAddressBecomesItsText(): void
    {
        self::assertSame(
            ['v4' => '10.0.0.1', 'net4' => '10.0.0.0/8', 'v6' => '::1', 'net6' => '2001:db8::/32'],
            self::converted("v4 = 10.0.0.1\nnet4 = 10.0.0.0/8\nv6 = ::1\nnet6 = 2001:db8::/32\n"),
        );
    }

    public function testATupleBecomesAListWithItsElementsConverted(): void
    {
        self::assertSame(['pair' => [1024, 'x', [5000, 1]]], self::converted("pair = (1KiB, \"x\", (5s, 1))\n"));
    }

    public function testMapsAndListsAreWalkedAtEveryDepth(): void
    {
        // A map inside a list, as CASC writes one (`[{ ... }]`) or as a
        // tag or resolver returns one: the walk must reach it all the same.
        $input = ['a' => ['b' => [['c' => new CooperBytes(2048)], [new CooperDuration(1_000_000_000), ['d' => CooperIPv4::parse('127.0.0.1')]]]]];

        self::assertSame(
            ['a' => ['b' => [['c' => 2048], [1000, ['d' => '127.0.0.1']]]]],
            Convert::toAppConfig($input),
        );
    }

    public function testDatesAtomsAndEverythingElsePassThroughAsCooperReturnsThem(): void
    {
        $result = self::converted(<<<'CASC'
            level = info
            day = 1979-05-27
            at = 07:32:00
            local = 1979-05-27T07:32:00
            instant = 1979-05-27T07:32:00Z
            n = nil
            yes = true
            pi = 3.14
            big = 100000000000000000000
            s = "text"
            CASC);

        self::assertEquals(CooperAtom::of('info'), $result['level']);
        self::assertInstanceOf(CooperDate::class, $result['day']);
        self::assertInstanceOf(CooperTime::class, $result['at']);
        self::assertInstanceOf(CooperLocalDateTime::class, $result['local']);
        self::assertInstanceOf(CooperDateTime::class, $result['instant']);
        self::assertNull($result['n']);
        self::assertTrue($result['yes']);
        self::assertSame(3.14, $result['pi']);
        self::assertInstanceOf(CooperInteger::class, $result['big']);
        self::assertSame('text', $result['s']);
    }

    public function testConversionLeavesNoCooperMeasurementAnywhereForArbitraryTrees(): void
    {
        // Property-style (hand-rolled, seeded): random trees of maps,
        // lists, tuples, byte sizes, durations, and addresses convert to
        // the same shape with every leaf untagged by the documented rule.
        mt_srand(2024);

        for ($i = 0; $i < 300; $i++) {
            [$input, $expected] = self::randomPair(3);
            self::assertSame(['v' => $expected], Convert::toAppConfig(['v' => $input]));
        }
    }

    /**
     * A random Cooper value and what conversion must make of it.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private static function randomPair(int $depth): array
    {
        $roll = mt_rand(0, $depth > 0 ? 8 : 5);
        switch ($roll) {
            case 0:
                $n = mt_rand(0, PHP_INT_MAX >> 1);

                return [new CooperBytes($n), $n];
            case 1:
                $ms = mt_rand(0, 1 << 40);

                return [new CooperDuration($ms * 1_000_000), $ms];
            case 2:
                $parts = [mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)];
                $prefix = mt_rand(0, 1) === 1 ? mt_rand(0, 32) : null;
                $text = implode('.', $parts) . ($prefix === null ? '' : "/{$prefix}");

                return [new CooperIPv4($parts, $prefix), $text];
            case 3:
                $v6 = CooperIPv6::parse('2001:db8::' . dechex(mt_rand(0, 0xFFFF)));

                return [$v6, (string) $v6];
            case 4:
                $s = bin2hex(random_bytes(3));

                return [$s, $s];
            case 5:
                $n = mt_rand();

                return [$n, $n];
            case 6:
                $in = [];
                $out = [];
                for ($k = mt_rand(0, 3); $k > 0; $k--) {
                    [$a, $b] = self::randomPair($depth - 1);
                    $in[] = $a;
                    $out[] = $b;
                }

                return [$in, $out];
            case 7:
                $in = [];
                $out = [];
                for ($k = mt_rand(0, 3); $k > 0; $k--) {
                    [$a, $b] = self::randomPair($depth - 1);
                    $in["k{$k}"] = $a;
                    $out["k{$k}"] = $b;
                }

                return [$in, $out];
            default:
                $in = [];
                $out = [];
                for ($k = mt_rand(1, 3); $k > 0; $k--) {
                    [$a, $b] = self::randomPair($depth - 1);
                    $in[] = $a;
                    $out[] = $b;
                }

                return [new CooperTuple($in), $out];
        }
    }
}
