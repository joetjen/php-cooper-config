<?php

declare(strict_types=1);

namespace JOetjen\CooperConfig\Tests;

use JOetjen\Cooper\Cooper;
use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperDuration;
use JOetjen\Cooper\Value\CooperSecret;
use JOetjen\CooperConfig\Config;
use JOetjen\CooperConfig\ConfigError;
use JOetjen\CooperConfig\ConversionError;
use JOetjen\CooperConfig\Convert;
use JOetjen\CooperConfig\Tests\Support\ConfigTestCase;
use JOetjen\CooperConfig\Tests\Support\ConstructedSecret;
use JOetjen\CooperConfig\Tests\Support\OwnedSecret;

/**
 * `cooper-secrets`: each application -- a top-level block -- says how
 * its own secrets arrive. `reveal` (the default), `keep`, or a module
 * name whose `new()` (else constructor) wraps each revealed value.
 */
final class SecretPolicyTest extends ConfigTestCase
{
    /**
     * @param array<string, mixed> $modules
     * @return array<array-key, mixed>
     */
    private static function converted(string $body, array $modules = []): array
    {
        return Convert::toAppConfig(Cooper::loadString(self::casc($body), ['dotenv' => false]), $modules);
    }

    private static function refusal(string $body, array $modules = []): ConversionError
    {
        try {
            self::converted($body, $modules);
        } catch (ConversionError $e) {
            return $e;
        }
        self::fail('the policy was accepted');
    }

    public function testWithoutAPolicyASecretIsRevealed(): void
    {
        self::assertSame(['app' => ['password' => 'hunter2']], self::converted("app.*password = \"hunter2\"\n"));
    }

    public function testRevealRevealsAndTheKeyIsRemoved(): void
    {
        $result = self::converted("app {\n  cooper-secrets = reveal\n  *password = \"hunter2\"\n}\n");

        self::assertSame(['app' => ['password' => 'hunter2']], $result);
    }

    public function testARevealedSecretIsConvertedLikeAnyValue(): void
    {
        self::assertSame(['app' => ['ttl' => 5000]], self::converted("app.*ttl = 5s\n"));
    }

    public function testKeepLeavesTheSecretWrappedAndTheKeyIsRemoved(): void
    {
        $result = self::converted("app {\n  cooper-secrets = keep\n  *password = \"hunter2\"\n  port = 1KiB\n}\n");

        self::assertSame(['password', 'port'], array_keys($result['app']));
        self::assertInstanceOf(CooperSecret::class, $result['app']['password']);
        self::assertSame('hunter2', $result['app']['password']->reveal());
        self::assertSame(1024, $result['app']['port']);
    }

    public function testAKeptSecretIsLeftAsCooperReturnedIt(): void
    {
        // Like the references: `keep` hands over the secret untouched,
        // its value unconverted, for code that reveals it deliberately.
        $result = self::converted("app {\n  cooper-secrets = keep\n  *ttl = 5s\n}\n");

        self::assertInstanceOf(CooperDuration::class, $result['app']['ttl']->reveal());
    }

    public function testAModuleNameWrapsEachSecretThroughItsStaticNew(): void
    {
        $result = self::converted("app {\n  cooper-secrets = \"JOetjen.CooperConfig.Tests.Support.OwnedSecret\"\n  *password = \"hunter2\"\n}\n");

        self::assertInstanceOf(OwnedSecret::class, $result['app']['password']);
        self::assertSame('hunter2', $result['app']['password']->value);
    }

    public function testAClassWithoutNewIsWrappedThroughItsConstructor(): void
    {
        $result = self::converted("app {\n  cooper-secrets = \"JOetjen.CooperConfig.Tests.Support.ConstructedSecret\"\n  *password = \"hunter2\"\n}\n");

        self::assertInstanceOf(ConstructedSecret::class, $result['app']['password']);
        self::assertSame('hunter2', $result['app']['password']->value);
    }

    public function testTheModulesOptionMapsTheNameExactlyAsModuleDoes(): void
    {
        $result = self::converted(
            "app {\n  cooper-secrets = \"Secret\"\n  *password = \"hunter2\"\n}\n",
            ['Secret' => OwnedSecret::class],
        );

        self::assertInstanceOf(OwnedSecret::class, $result['app']['password']);
    }

    public function testAWrappedSecretIsConvertedBeforeItIsWrapped(): void
    {
        $result = self::converted("app {\n  cooper-secrets = \"Secret\"\n  *ttl = 5s\n}\n", ['Secret' => OwnedSecret::class]);

        self::assertSame(5000, $result['app']['ttl']->value);
    }

    public function testThePolicyGovernsEveryDepthOfItsApplicationAndNoOther(): void
    {
        $result = self::converted(<<<'CASC'
            app {
              cooper-secrets = keep
              db.*password = "deep"
              replicas = [%{app.db.password}]
            }
            other.*password = "revealed"
            CASC);

        self::assertInstanceOf(CooperSecret::class, $result['app']['db']['password']);
        self::assertInstanceOf(CooperSecret::class, $result['app']['replicas'][0]);
        self::assertSame('revealed', $result['other']['password']);
    }

    public function testAPolicyAtTheRootIsRefused(): void
    {
        $e = self::refusal("cooper-secrets = keep\napp.port = 1\n");

        self::assertSame(
            '"cooper-secrets" is at the root of the document, where a key names an application: '
            . 'write it at the top of the block of each application whose secrets it governs',
            $e->getMessage(),
        );
    }

    public function testANestedPolicyIsRefusedNamingWhereItIs(): void
    {
        $e = self::refusal("app.db {\n  cooper-secrets = keep\n}\n");

        self::assertStringStartsWith(
            '"cooper-secrets" belongs at the top of an application\'s block and this one is nested: '
            . 'it governs every depth beneath the application it is written in, so one further down would govern nothing',
            $e->getMessage(),
        );
        self::assertStringContainsString('app.db', $e->getMessage());
    }

    public function testAPolicyNestedInAListIsRefusedToo(): void
    {
        // A map inside a list, as CASC writes one (`[{ ... }]`) or a tag
        // returns one.
        try {
            Convert::toAppConfig(['app' => ['items' => [[Convert::POLICY_KEY => CooperAtom::of('keep')]]]]);
            self::fail('a policy inside a list was accepted');
        } catch (ConversionError $e) {
        }

        self::assertStringContainsString('is nested', $e->getMessage());
    }

    public function testAPolicyWrittenInAListElementBlockIsRefused(): void
    {
        $e = self::refusal("app.items = [{ cooper-secrets = keep }]\n");

        self::assertStringContainsString('is nested', $e->getMessage());
    }

    public function testAnUnknownPolicyIsRefused(): void
    {
        foreach (['hide' => 'hide', '1' => '1', 'true' => 'true'] as $written => $shown) {
            $e = self::refusal("app.cooper-secrets = {$written}\n");

            self::assertSame(
                "\"cooper-secrets\" is {$shown}, which is no policy: write reveal or keep, "
                . 'or a string naming a module whose new wraps each secret',
                $e->getMessage(),
            );
        }
    }

    public function testAModuleNameThatIsNotPascalCaseIsRefused(): void
    {
        $e = self::refusal("app.cooper-secrets = \"my.secret\"\n");

        self::assertStringStartsWith('"cooper-secrets" names "my.secret", which is no module name', $e->getMessage());
    }

    public function testAModuleNamingNoClassIsRefused(): void
    {
        $e = self::refusal("app.cooper-secrets = \"No.Such.Secret\"\n");

        self::assertSame(
            '"cooper-secrets" names No.Such.Secret, but no class No\\Such\\Secret could be loaded',
            $e->getMessage(),
        );
    }

    public function testAClassWithoutANewOrConstructorOfOneArgumentIsRefused(): void
    {
        $e = self::refusal("app.cooper-secrets = \"JOetjen.CooperConfig.Tests.Support.UnwrappableSecret\"\n");

        self::assertSame(
            '"cooper-secrets" names JOetjen.CooperConfig.Tests.Support.UnwrappableSecret, which has no static new '
            . 'and no constructor of one argument -- a class wrapping a secret needs one',
            $e->getMessage(),
        );
    }

    public function testAModuleTheModulesOptionMapsToSomethingOtherThanAClassIsRefused(): void
    {
        $e = self::refusal("app.cooper-secrets = \"Secret\"\n", ['Secret' => 42]);

        self::assertSame('"cooper-secrets" names Secret, which the modules option maps to int, not a class name', $e->getMessage());
    }

    public function testThePolicyIsRefusedEvenWithoutAnySecretToApplyItTo(): void
    {
        // A typo is found the first time the document loads, not the
        // first time somebody adds a secret.
        self::refusal("app.cooper-secrets = \"No.Such.Secret\"\napp.port = 1\n");
        self::addToAssertionCount(1);
    }

    public function testThroughLoadARefusalIsALoadError(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("cooper-secrets = keep\n")]);

        try {
            self::loadProject($root);
            self::fail('a root policy loaded');
        } catch (ConfigError $e) {
            self::assertStringContainsString('at the root of the document', $e->getMessage());
            self::assertInstanceOf(ConversionError::class, $e->getPrevious());
        }
        self::assertFalse(Config::isLoaded());
    }

    public function testThroughLoadTheModulesOptionReachesThePolicy(): void
    {
        $root = $this->project(['config/config.casc' => self::casc("app {\n  cooper-secrets = \"Secret\"\n  *pw = \"x\"\n}\n")]);

        self::loadProject($root, ['modules' => ['Secret' => ConstructedSecret::class]]);

        self::assertInstanceOf(ConstructedSecret::class, Config::get('app.pw'));
    }
}
