<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Config\RecaptchaVersion;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Config\TargetSettings;
use SelestRecaptcha\Guard\Target;
use SelestRecaptcha\Widget\WidgetConfig;

final class SettingsTest extends TestCase
{
    public function testDefaultsProtectEveryTargetButAreDisabled(): void
    {
        $settings = Settings::defaults();

        self::assertFalse($settings->enabled, 'a fresh install must not block anything');
        self::assertFalse($settings->isUsable());
        self::assertSame(RecaptchaVersion::V2_CHECKBOX, $settings->version);
        self::assertSame(0.5, $settings->minScore);
        self::assertSame('closed', $settings->failMode);

        foreach (Target::all() as $target) {
            self::assertTrue($settings->target($target)->enabled);
            self::assertSame($target->defaultAction(), $settings->target($target)->action);
        }
    }

    public function testActionNameIsReducedToWhatGoogleAccepts(): void
    {
        $settings = Settings::fromArray([
            'enabled' => true,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
            'targets' => [
                'contact' => ['action' => 'contact form!!'],
                'newsletter' => ['action' => '<script>alert(1)</script>'],
                'comment' => ['action' => '   '],
                'registration' => ['action' => '/leading/and/trailing/'],
            ],
        ]);

        self::assertSame('contactform', $settings->target(Target::Contact)->action);
        // Only [A-Za-z0-9/_] survives, and the slashes that framed the tag are
        // trimmed: 'scriptalert1/script' is still a legal action name, which is
        // all this sanitiser promises.
        self::assertSame('scriptalert1/script', $settings->target(Target::Newsletter)->action);
        self::assertSame('product/review', $settings->target(Target::Comment)->action, 'a blank action falls back to the target default');
        self::assertSame('leading/and/trailing', $settings->target(Target::Registration)->action);
    }

    public function testDisabledTargetSurvivesAHandOffOfBuiltInstances(): void
    {
        // Re-building Settings from TargetSettings objects must not fall back
        // to defaults: that would quietly switch a form back on.
        $targets = [];
        foreach (Target::all() as $target) {
            $targets[$target->value] = new TargetSettings(
                $target !== Target::Newsletter,
                $target->defaultAction()
            );
        }

        $settings = Settings::fromArray([
            'enabled' => true,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
            'targets' => $targets,
        ]);

        self::assertFalse($settings->isTargetEnabled(Target::Newsletter));
        self::assertTrue($settings->isTargetEnabled(Target::Contact));
    }

    public function testUnreadableTargetDataKeepsTheProtectiveDefault(): void
    {
        $settings = Settings::fromArray([
            'enabled' => true,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
            'targets' => ['contact' => 'not-an-array'],
        ]);

        // When the stored value cannot be read, protection stays on: a spam
        // wave is worse than a captcha the merchant can see and remove.
        self::assertTrue($settings->isTargetEnabled(Target::Contact));
    }

    public function testScoreIsClampedToTheDocumentedRange(): void
    {
        $settings = Settings::fromArray(['min_score' => '4.2']);

        self::assertSame(1.0, $settings->minScore);

        $settings = Settings::fromArray(['min_score' => '-3']);

        self::assertSame(0.0, $settings->minScore);

        $settings = Settings::fromArray(['min_score' => 'not a number']);

        self::assertSame(0.5, $settings->minScore);
    }

    public function testUnknownVersionFallsBackToTheDefault(): void
    {
        self::assertSame(
            RecaptchaVersion::V2_CHECKBOX,
            Settings::fromArray(['version' => 'v9_magic'])->version
        );
    }

    public function testMalformedKeysAreTreatedAsAbsent(): void
    {
        $settings = Settings::fromArray([
            'enabled' => true,
            'site_key' => '<script>',
            'secret_key' => '',
        ]);

        self::assertSame('', $settings->siteKey);
        self::assertFalse($settings->isUsable());
    }

    public function testEndpointMustBeHttpsUnlessItIsLoopback(): void
    {
        self::assertSame(
            'https://recaptcha-proxy.example/verify',
            Settings::fromArray(['endpoint' => 'https://recaptcha-proxy.example/verify'])->endpoint
        );

        self::assertSame(
            Settings::ENDPOINT,
            Settings::fromArray(['endpoint' => 'http://evil.example/verify'])->endpoint,
            'plain HTTP to a remote host is refused'
        );

        self::assertSame(
            Settings::ENDPOINT,
            Settings::fromArray(['endpoint' => 'file:///etc/passwd'])->endpoint
        );

        self::assertSame(
            Settings::ENDPOINT,
            Settings::fromArray(['endpoint' => 'https://trusted.example@evil.example/'])->endpoint,
            'credentials in the URL are refused'
        );

        self::assertSame(
            'http://127.0.0.1:18093/siteverify',
            Settings::fromArray(['endpoint' => 'http://127.0.0.1:18093/siteverify'])->endpoint,
            'loopback stays available for local testing'
        );
    }

    public function testTimeoutIsBounded(): void
    {
        self::assertSame(1, Settings::fromArray(['timeout' => 0])->timeout);
        self::assertSame(15, Settings::fromArray(['timeout' => 900])->timeout);
        self::assertSame(5, Settings::fromArray(['timeout' => 'abc'])->timeout);
    }

    public function testTestKeysAreDetected(): void
    {
        $settings = Settings::fromArray([
            'enabled' => true,
            'site_key' => Settings::TEST_SITE_KEY,
            'secret_key' => Settings::TEST_SECRET_KEY,
        ]);

        self::assertTrue($settings->usesTestKeys());
        self::assertTrue($settings->isUsable());
    }

    public function testPerTargetThresholdOverridesTheGlobalOne(): void
    {
        $settings = Settings::fromArray([
            'enabled' => true,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
            'min_score' => 0.5,
            'targets' => ['comment' => ['min_score' => 0.9]],
        ]);

        self::assertSame(0.9, $settings->effectiveMinScore(Target::Comment));
        self::assertSame(0.5, $settings->effectiveMinScore(Target::Contact));
    }

    public function testRoundTripThroughArrayKeepsEverything(): void
    {
        $settings = Settings::fromArray([
            'enabled' => true,
            'version' => RecaptchaVersion::V3,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
            'min_score' => 0.7,
            'fail_mode' => 'open',
            'strict_hostname' => true,
            'log_events' => false,
            'targets' => ['contact' => ['enabled' => false, 'action' => 'contact_us']],
            'messages' => ['block' => 'Prove it.'],
        ]);

        $rebuilt = Settings::fromArray($settings->toArray());

        self::assertEquals($settings, $rebuilt);
    }

    public function testHostnamesComeFromTheShopUrls(): void
    {
        $settings = Settings::fromArray([], [
            'https://www.boutique.example/',
            'https://boutique.example/',
        ]);

        $hostnames = $settings->allowedHostnames();

        self::assertContains('www.boutique.example', $hostnames);
        self::assertContains('boutique.example', $hostnames);
        self::assertContains('localhost', $hostnames, 'a shop under construction must still pass');
    }

    public function testWidgetConfigNeverCarriesTheSecret(): void
    {
        $settings = Settings::fromArray([
            'enabled' => true,
            'version' => RecaptchaVersion::V3,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
        ]);

        $json = WidgetConfig::fromSettings($settings, Settings::SCRIPT, 'en')->toJson();

        self::assertStringNotContainsString('secret-key-for-tests', $json);
        self::assertStringContainsString('site-key-for-tests-000000', $json);
        self::assertJson($json);
    }

    public function testWidgetConfigJsonCannotBreakOutOfTheScriptTag(): void
    {
        $settings = Settings::fromArray([
            'enabled' => true,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
            'messages' => ['block' => '</script><script>alert(1)</script>'],
        ]);

        $json = WidgetConfig::fromSettings($settings, Settings::SCRIPT, 'en')->withFlashMessage('</script>')->toJson();

        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<', $json);
    }
}