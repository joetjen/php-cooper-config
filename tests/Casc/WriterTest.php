<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests\Casc;

use JOetjen\Cooper\Cooper;
use JOetjen\CooperConfig\Casc\Commented;
use JOetjen\CooperConfig\Casc\MapValue;
use JOetjen\CooperConfig\Casc\Raw;
use JOetjen\CooperConfig\Casc\Writer;
use PHPUnit\Framework\TestCase;

/**
 * `Writer`: PHP value trees as CASC source. Every document a test
 * writes is loaded back with `joetjen/cooper` -- the only judge of
 * whether the text is CASC -- with `.env` loading off and the
 * environment given explicitly, so the machine's own never leaks in.
 */
final class WriterTest extends TestCase
{
    /**
     * @param array<string, string> $env
     * @return array<array-key, mixed>
     */
    private static function load(string $source, array $env = []): array
    {
        return Cooper::loadString($source, ['dotenv' => false, 'env' => $env]);
    }

    /**
     * @param array<array-key, mixed> $tree
     */
    private static function assertRoundTrips(array $tree): void
    {
        $source = (new Writer())->write($tree);

        self::assertSame($tree, self::load($source), $source);
    }

    public function testADocumentStartsWithTheVersionHeader(): void
    {
        self::assertSame("#@version = 1.0\n", (new Writer())->write([]));
        self::assertStringStartsWith("#@version = 2.1\n", (new Writer('2.1'))->write(['a' => 1]));
    }

    public function testScalarsAreWrittenAsCascLiterals(): void
    {
        $source = (new Writer())->write(['i' => -17, 'f' => 3.5, 't' => true, 'n' => null, 's' => 'text']);

        self::assertSame(<<<'CASC'
            #@version = 1.0

            i = -17
            f = 3.5
            t = true
            n = nil
            s = "text"

            CASC, $source);
    }

    public function testNestedMapsAreWrittenAsBlocks(): void
    {
        $source = (new Writer())->write(['database' => ['host' => 'localhost', 'pool' => ['size' => 5]], 'debug' => false]);

        self::assertSame(<<<'CASC'
            #@version = 1.0

            database {
              host = "localhost"

              pool {
                size = 5
              }
            }

            debug = false

            CASC, $source);
    }

    public function testListsAreWrittenInlineWhenTheyFit(): void
    {
        self::assertStringContainsString('hosts = ["a", "b", 3]', (new Writer())->write(['hosts' => ['a', 'b', 3]]));
    }

    public function testALongListIsWrittenOneElementPerLine(): void
    {
        $list = array_map(static fn (int $i): string => str_repeat('x', 20) . $i, range(1, 5));

        $source = (new Writer())->write(['v' => $list]);

        self::assertStringContainsString("v = [\n  \"xxxxxxxxxxxxxxxxxxxx1\"\n", $source);
        self::assertSame(['v' => $list], self::load($source));
    }

    public function testAnEmptyArrayIsAnEmptyList(): void
    {
        // A PHP array cannot say whether it is an empty list or an empty
        // map, and both load back as `[]`; a list is the reading assumed.
        $source = (new Writer())->write(['v' => [], 'w' => ['x' => []]]);

        self::assertStringContainsString("v = []\n", $source);
        self::assertSame(['v' => [], 'w' => ['x' => []]], self::load($source));
    }

    public function testAMapValueIsWrittenAsAMapEvenWhenEmpty(): void
    {
        $source = (new Writer())->write(['logger' => new MapValue(), 'l' => [new MapValue()], 'n' => new MapValue(['a', 'b'])]);

        self::assertStringContainsString("\nlogger {}\n", $source);
        self::assertStringContainsString("\nl = [{}]\n", $source);
        self::assertStringContainsString("n {\n  \"0\" = \"a\"\n", $source);
        self::assertSame(['logger' => [], 'l' => [[]], 'n' => ['a', 'b']], self::load($source));
    }

    public function testAnEmptyMapLeavesAMapAlreadyThereAsItIsWhereAnEmptyListReplacesIt(): void
    {
        // Why the hint matters (CASC.md §5.4): a written document is often
        // an overlay, and `{}` merges where `[]` replaces.
        $base = "#@version = 1.0\nw.x = 1\n";
        $overlay = static fn (mixed $w): string => substr((new Writer())->write(['w' => $w]), strlen("#@version = 1.0\n"));

        self::assertSame(['w' => ['x' => 1]], self::load($base . $overlay(new MapValue())));
        self::assertSame(['w' => []], self::load($base . $overlay([])));
    }

    public function testFloatsKeepTheirTypeAndEveryDigit(): void
    {
        self::assertRoundTrips(['v' => [1.0, -0.5, 0.1, 1.0e25, 1.5e-7, 1.7976931348623157e308, INF, -INF]]);
    }

    public function testNanHasNoCascSpelling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('v');

        (new Writer())->write(['v' => NAN]);
    }

    public function testTheWidestIntegersRoundTrip(): void
    {
        self::assertRoundTrips(['v' => [PHP_INT_MAX, PHP_INT_MIN, 0]]);
    }

    public function testAStringIsDoubleQuotedWithItsEscapes(): void
    {
        $source = (new Writer())->write(['v' => "a \"q\" \\ \n\r\t\x01\x7f é"]);

        self::assertStringContainsString('v = "a \"q\" \\\\ \n\r\t\u0001\u007f é"', $source);
        self::assertSame(['v' => "a \"q\" \\ \n\r\t\x01\x7f é"], self::load($source));
    }

    public function testAStringHoldingAReferenceOpenerIsSingleQuotedSoNothingIsInterpolated(): void
    {
        foreach (['${HOME}', '@{name}', '%{a.b}', '!{vault:x}', 'say !upcase(hi)'] as $text) {
            $source = (new Writer())->write(['v' => $text]);

            self::assertStringContainsString("v = '{$text}'", $source, $text);
            self::assertSame(['v' => $text], self::load($source, ['HOME' => 'nope']), $text);
        }
    }

    public function testAStringNeedingBothQuotesStillIsNotInterpolated(): void
    {
        // Neither quoting fits: single quotes cannot hold `'`, and double
        // quotes interpolate after escapes are processed, so no escape
        // keeps `${` literal. The opener's first character is
        // interpolated from a private variable instead.
        $text = "it's \${HOME}, @{x}, %{y}, !{z}, !Tag(1) and a\nnewline";

        self::assertRoundTrips(['v' => $text, 'k' => [$text => 1]]);
    }

    public function testTheHelperVariablesAreDeclaredOnlyWhenNeeded(): void
    {
        self::assertStringNotContainsString('@*', (new Writer())->write(['v' => '${x}', 'w' => "it's"]));
        self::assertStringContainsString("@*casc_writer_dollar = '\$'", (new Writer())->write(['v' => "it's \${x}"]));
    }

    public function testIdentifierKeysAreBareAndEveryOtherKeyIsQuoted(): void
    {
        $source = (new Writer())->write([
            'plain' => 1, 'kebab-case' => 2, 'snake_case' => 3, 'enabled?' => 4, 'ready!' => 5,
            'Content-Type' => 6, 'with space' => 7, 'a.b' => 8, '1' => 9, '' => 10, '*secret' => 11, '${x}' => 12,
        ]);

        foreach (['plain = 1', 'kebab-case = 2', 'snake_case = 3', 'enabled? = 4', 'ready! = 5', 'Content-Type = 6',
            '"with space" = 7', '"a.b" = 8', '"1" = 9', '"" = 10', '"*secret" = 11', "'\${x}' = 12"] as $line) {
            self::assertStringContainsString("\n{$line}\n", $source, $line);
        }
    }

    public function testReservedWordsAreQuotedAsKeys(): void
    {
        $source = (new Writer())->write(['nil' => 1, 'true' => 2, 'false' => 3, 'inf' => 4, 'import' => 5, 'for' => 6]);

        self::assertStringContainsString('"nil" = 1', $source);
        self::assertSame(['nil' => 1, 'true' => 2, 'false' => 3, 'inf' => 4, 'import' => 5, 'for' => 6], self::load($source));
    }

    public function testKeysKeepTheOrderTheyWereGiven(): void
    {
        $source = (new Writer())->write(['z' => 1, 'a' => 2, 'm' => 3]);

        self::assertSame(['z', 'a', 'm'], array_keys(self::load($source)));
    }

    public function testACommentIsWrittenAboveItsKey(): void
    {
        $source = (new Writer())->write([
            'app' => [
                'url' => new Commented('https://example.com', 'was: env("APP_URL")'),
                'name' => new Commented(['short' => 'x'], "two\nlines"),
            ],
        ]);

        self::assertStringContainsString("  # was: env(\"APP_URL\")\n  url = \"https://example.com\"\n", $source);
        self::assertStringContainsString("  # two\n  # lines\n  name {\n", $source);
        self::assertSame(['app' => ['url' => 'https://example.com', 'name' => ['short' => 'x']]], self::load($source));
    }

    public function testACommentNeverDisablesTheLineItIsOn(): void
    {
        // `#key` is a disabled statement (CASC.md §3.2), `# key` a comment.
        $source = (new Writer())->write(['v' => new Commented(1, "key = 2\n\n*x = 3")]);

        self::assertStringContainsString("# key = 2\n#\n# *x = 3\nv = 1\n", $source);
        self::assertSame(['v' => 1], self::load($source));
    }

    public function testTheDocumentCanCarryALeadingComment(): void
    {
        $source = (new Writer())->write(['a' => 1], "Generated from config/app.php\nby cooper:import");

        self::assertStringStartsWith("#@version = 1.0\n\n# Generated from config/app.php\n# by cooper:import\n\na = 1\n", $source);
    }

    public function testRawCascIsPassedThroughAsWritten(): void
    {
        $source = (new Writer())->write([
            'host' => new Raw('${DB_HOST:"127.0.0.1"}'),
            'port' => new Raw('!int(${PORT})'),
            'store' => new Raw('!module("Foo.Bar")'),
            'ttl' => new Raw('5m'),
            'mixed' => [new Raw('info'), 'x'],
        ]);

        self::assertStringContainsString('host = ${DB_HOST:"127.0.0.1"}', $source);
        self::assertStringContainsString('port = !int(${PORT})', $source);
        $loaded = self::load($source, ['PORT' => '8080']);
        self::assertSame('127.0.0.1', $loaded['host']);
        self::assertSame(8080, $loaded['port']);
        self::assertSame('Foo\\Bar', $loaded['store']);
        self::assertSame('x', $loaded['mixed'][1]);
    }

    public function testAnEmptyRawFragmentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Raw('  ');
    }

    public function testAListOfMapsIsWrittenAsAListOfBlocks(): void
    {
        // A list element may be a block (CASC.md §6.10).
        $tree = ['servers' => [['host' => 'a', 'port' => 1], ['host' => 'b'], 'spare']];

        $source = (new Writer())->write($tree);

        self::assertStringContainsString("\nservers = [{ host = \"a\", port = 1 }, { host = \"b\" }, \"spare\"]\n", $source);
        self::assertSame($tree, self::load($source));
    }

    public function testALongListOfMapsIsWrittenOneBlockPerLine(): void
    {
        $tree = ['access_control' => [
            ['path' => '^/admin', 'roles' => ['ROLE_ADMIN']],
            ['path' => '^/api', 'roles' => ['ROLE_API'], 'pool' => ['size' => 5]],
        ]];

        $source = (new Writer())->write($tree);

        self::assertSame(<<<'CASC'
            #@version = 1.0

            access_control = [
              { path = "^/admin", roles = ["ROLE_ADMIN"] }
              {
                path = "^/api"
                roles = ["ROLE_API"]

                pool {
                  size = 5
                }
              }
            ]

            CASC, $source);
        self::assertSame($tree, self::load($source));
    }

    public function testAMapInsideAListInsideAListRoundTrips(): void
    {
        self::assertRoundTrips(['servers' => ['list' => [[['host' => 'a']], [[]]]]]);
    }

    public function testACommentOnAnEntryOfAMapInAListIsWrittenAboveIt(): void
    {
        $source = (new Writer())->write(['v' => [['a' => new Commented(1, 'x')]]]);

        self::assertStringContainsString("v = [\n  {\n    # x\n    a = 1\n  }\n]\n", $source);
        self::assertSame(['v' => [['a' => 1]]], self::load($source));
    }

    public function testAKeyThatWouldBeInterpolatedAndHoldsADotIsRefused(): void
    {
        // Such a key can only be written interpolated, and an
        // interpolated key segment may not hold a `.` (CASC.md §4.2).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no CASC spelling');

        (new Writer())->write(["it's \${a}.b" => 1]);
    }

    public function testACommentInsideAListIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Writer())->write(['v' => [new Commented(1, 'x')]]);
    }

    public function testAValueWithNoCascSpellingIsRefusedNamingWhere(): void
    {
        foreach ([new \stdClass(), fopen('php://memory', 'r'), "\xff\xfe"] as $value) {
            try {
                (new Writer())->write(['a' => ['b' => $value]]);
                self::fail('expected an InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('a.b', $e->getMessage());
            }
        }
    }

    public function testRandomTreesReadBackAsTheSameValues(): void
    {
        // Property (hand-rolled, seeded, per AGENTS.md): for any tree of
        // the values the writer accepts, loading what it wrote gives
        // that tree back.
        mt_srand(4242);
        for ($run = 0; $run < 400; $run++) {
            $tree = self::randomMap(3);
            if ($tree instanceof MapValue) {
                $tree = $tree->entries;
            }
            $source = (new Writer())->write($tree);

            self::assertSame(self::plain($tree), self::load($source), "run {$run}:\n{$source}");
        }
    }

    /**
     * What `$value` loads back as: a `MapValue` is the array it holds.
     */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof MapValue) {
            $value = $value->entries;
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }

    /**
     * A map, now and then an empty `MapValue`, which is written `{}`.
     *
     * @return array<array-key, mixed>|MapValue
     */
    private static function randomMap(int $depth): array|MapValue
    {
        if (mt_rand(0, 9) === 0) {
            return new MapValue();
        }
        $map = [];
        for ($i = mt_rand(0, 5); $i > 0; $i--) {
            $map[self::randomKey()] = $depth > 0 && mt_rand(0, 3) === 0 ? self::randomMap($depth - 1) : self::randomValue($depth);
        }

        return $map;
    }

    private static function randomValue(int $depth): mixed
    {
        return match (mt_rand(0, 9)) {
            0 => null,
            1 => mt_rand(0, 1) === 1,
            2 => [PHP_INT_MIN, PHP_INT_MAX, 0, -1, mt_rand(-1000000, 1000000)][mt_rand(0, 4)],
            3 => [INF, -INF, 0.0, -0.0, 1.0e300, mt_rand() / mt_getrandmax(), (mt_rand() - mt_getrandmax() / 2) * 1.0e-9][mt_rand(0, 6)],
            4, 5, 6 => self::randomString(),
            default => $depth > 0 ? self::randomList($depth - 1) : self::randomString(),
        };
    }

    /**
     * A list whose elements may be maps (CASC.md §6.10) and lists, at
     * any depth.
     *
     * @return list<mixed>
     */
    private static function randomList(int $depth): array
    {
        $list = [];
        for ($i = mt_rand(0, 4); $i > 0; $i--) {
            $list[] = match (true) {
                mt_rand(0, 4) === 0 => self::randomMap($depth),
                $depth > 0 && mt_rand(0, 4) === 0 => self::randomList($depth - 1),
                default => self::randomValue(0),
            };
        }

        return $list;
    }

    private static function randomKey(): string
    {
        $special = ['nil', 'true', 'false', 'inf', 'import', 'for', 'in', 'as', '1', '01', '-1', '', 'a.b', '*x', '~y', '#z', 'enabled?'];

        do {
            $key = mt_rand(0, 3) === 0 ? $special[mt_rand(0, count($special) - 1)] : self::randomString(1);
        } while (self::unwritableKey($key));

        return $key;
    }

    /**
     * The one key the writer refuses (see
     * `testAKeyThatWouldBeInterpolatedAndHoldsADotIsRefused`).
     */
    private static function unwritableKey(string $key): bool
    {
        return str_contains($key, '.')
            && preg_match('/[@$%!]\{|![A-Za-z][A-Za-z0-9_+\-]*[?!]?\(/', $key) === 1
            && (str_contains($key, "'") || preg_match('/[\x00-\x1f\x7f]/', $key) === 1);
    }

    /**
     * Text drawn mostly from the characters CASC treats specially.
     */
    private static function randomString(int $min = 0): string
    {
        $pieces = ['a', 'Z', '0', ' ', '_', '-', '.', "'", '"', '\\', '$', '@', '%', '!', '{', '}', '(', ')', ':', '#', '*',
            "\n", "\t", "\r", "\x01", "\x7f", 'é', '😀', '${X}', '@{v}', '%{a}', '!{r:x}', '!up(x)', '\\n', '\\u0041', '"""'];
        $text = '';
        for ($i = mt_rand($min, 8); $i > 0; $i--) {
            $text .= $pieces[mt_rand(0, count($pieces) - 1)];
        }

        return $text;
    }
}
