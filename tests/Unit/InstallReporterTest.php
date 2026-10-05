<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Stats\InstallReporter;
use SelestRecaptcha\Tests\Support\FakeTransport;

final class InstallReporterTest extends TestCase
{
    public function testNothingIsSentWhileTheFeatureIsOff(): void
    {
        $transport = new FakeTransport();
        $reporter = new InstallReporter($transport);

        $settings = Settings::fromArray([
            'stats_enabled' => false,
            'stats_endpoint' => 'https://stats.example.test/ping',
        ]);

        self::assertFalse($reporter->report(InstallReporter::EVENT_INSTALL, $settings, '1.0.0'));
        self::assertSame([], $transport->calls);
    }

    public function testNothingIsSentWithoutAnEndpoint(): void
    {
        $transport = new FakeTransport();
        $reporter = new InstallReporter($transport);

        $settings = Settings::fromArray(['stats_enabled' => true, 'stats_endpoint' => '']);

        self::assertFalse($reporter->report(InstallReporter::EVENT_INSTALL, $settings, '1.0.0'));
        self::assertSame([], $transport->calls);
    }

    public function testThePingSaysWhatItIsAndNothingElse(): void
    {
        $transport = new FakeTransport();
        $reporter = new InstallReporter($transport);

        $settings = Settings::fromArray([
            'stats_enabled' => true,
            'stats_endpoint' => 'https://stats.example.test/ping',
        ]);

        self::assertTrue($reporter->report(InstallReporter::EVENT_UPGRADE, $settings, '1.2.3'));

        $call = $transport->calls[0];
        self::assertSame('https://stats.example.test/ping', $call['url']);

        $fields = $call['fields'];
        self::assertSame('upgrade', $fields['event']);
        self::assertSame('selestrecaptcha', $fields['module']);
        self::assertSame('1.2.3', $fields['module_version']);

        foreach (['secret_key', 'site_key', 'url', 'domain', 'email', 'ip', 'shop_name'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $fields, 'the ping must not carry ' . $forbidden);
        }

        foreach (array_keys($fields) as $name) {
            self::assertDoesNotMatchRegularExpression('/@[a-z0-9.-]+\.[a-z]{2,}/i', (string) $fields[$name], 'no domain in ' . $name);
        }
    }

    public function testAStatisticsEndpointThatFailsNeverPropagates(): void
    {
        $transport = new FakeTransport();
        $transport->shouldThrow = true;

        $reporter = new InstallReporter($transport);
        $settings = Settings::fromArray([
            'stats_enabled' => true,
            'stats_endpoint' => 'https://stats.example.test/ping',
        ]);

        // An installation must not fail because a counter did not answer.
        self::assertFalse($reporter->report(InstallReporter::EVENT_INSTALL, $settings, '1.0.0'));
    }

    public function testAStatisticsEndpointThatIsNotHttpsIsIgnored(): void
    {
        $transport = new FakeTransport();

        $settings = Settings::fromArray([
            'stats_enabled' => true,
            // Plain HTTP to a public host: refused, exactly like the
            // verification endpoint.
            'stats_endpoint' => 'http://stats.example.test/ping',
        ]);

        self::assertSame('', $settings->statsEndpoint);

        $reporter = new InstallReporter($transport);
        self::assertFalse($reporter->report(InstallReporter::EVENT_INSTALL, $settings, '1.0.0'));
        self::assertSame([], $transport->calls);
    }

    public function testLoopbackIsAllowedSoAShopCanKeepTheCounterItself(): void
    {
        $settings = Settings::fromArray([
            'stats_enabled' => true,
            'stats_endpoint' => 'http://127.0.0.1:9000/ping',
        ]);

        self::assertSame('http://127.0.0.1:9000/ping', $settings->statsEndpoint);
    }

    public function testAnUnusableEndpointSwitchesTheFeatureOff(): void
    {
        $settings = Settings::fromArray([
            'stats_enabled' => true,
            'stats_endpoint' => 'https://stats.example.test@evil.example/ping',
        ]);

        self::assertSame('', $settings->statsEndpoint);
    }

    public function testBuildingThePayloadTouchesNoNetwork(): void
    {
        $transport = new FakeTransport();
        $transport->shouldThrow = true;

        $payload = (new InstallReporter($transport))->payload('install', '1.0.0');

        self::assertIsArray($payload);
        self::assertSame([], $transport->calls);
    }
}