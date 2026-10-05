<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Http\CurlTransport;
use SelestRecaptcha\Verification\Policy;
use SelestRecaptcha\Verification\VerificationResult;
use SelestRecaptcha\Verification\Verifier;

/**
 * Exercises the real cURL transport against a fake of the Google endpoint
 * served over HTTP.
 *
 * The unit suite replaces the transport, which proves the rules but says
 * nothing about the wire. This one answers the questions only a real request
 * can: does the body arrive form-encoded, does a 500 raise instead of parsing,
 * does a single-use token really get refused the second time.
 *
 * Skipped with a clear message when the fake is not running:
 *   php -S 127.0.0.1:18093 tests/Integration/fake-google.php
 */
final class CurlTransportTest extends TestCase
{
    private const FAKE_SECRET = 'selest-fake-secret-key-000000000000';
    private const BASE = 'http://127.0.0.1:18093/siteverify';

    protected function setUp(): void
    {
        if (!self::fakeIsRunning()) {
            self::markTestSkipped('The fake Google endpoint is not running on 127.0.0.1:18093.');
        }

        $state = getenv('SR_GOOGLE_STATE') ?: (__DIR__ . '/.google-state.json');
        if (is_file($state)) {
            unlink($state);
        }
    }

    public function testValidTokenIsAcceptedOverRealHttp(): void
    {
        $result = $this->verify('valid-token');

        self::assertTrue($result->isAccepted(), $result->reason);
        self::assertSame('shop.example', $result->hostname);
    }

    public function testBotTokenIsRefusedOverRealHttp(): void
    {
        $result = $this->verify('bot-token');

        self::assertFalse($result->isAccepted());
        self::assertSame(VerificationResult::REJECTED, $result->reason);
    }

    public function testASecondUseOfTheSameTokenIsRefused(): void
    {
        self::assertTrue($this->verify('valid-token')->isAccepted());

        $replay = $this->verify('valid-token');

        self::assertFalse($replay->isAccepted(), 'a token must not be usable twice');
        self::assertSame(VerificationResult::TOKEN_EXPIRED_OR_REUSED, $replay->reason);
    }

    public function testWrongSecretIsDetectedOverRealHttp(): void
    {
        $verifier = new Verifier(new CurlTransport(), self::BASE, 5);
        $result = $verifier->verify('valid-token', 'wrong-secret', null, new Policy());

        self::assertTrue($result->isUnreachable());
        self::assertSame(VerificationResult::INVALID_SECRET, $result->reason);
    }

    public function testMissingTokenNeverReachesTheWire(): void
    {
        $verifier = new Verifier(new CurlTransport(), self::BASE, 5);
        $result = $verifier->verify('', self::FAKE_SECRET, null, new Policy());

        self::assertSame(VerificationResult::MISSING_TOKEN, $result->reason);
    }

    public function testHttpErrorIsAnOutageNotAPass(): void
    {
        $verifier = new Verifier(new CurlTransport(), self::BASE . '?fault=http500', 5);
        $result = $verifier->verify('valid-token', self::FAKE_SECRET, null, new Policy());

        self::assertTrue($result->isUnreachable());
    }

    public function testHtmlErrorPageIsAnOutageNotAPass(): void
    {
        $verifier = new Verifier(new CurlTransport(), self::BASE . '?fault=garbage', 5);
        $result = $verifier->verify('valid-token', self::FAKE_SECRET, null, new Policy());

        self::assertTrue($result->isUnreachable());
        self::assertSame(VerificationResult::MALFORMED_ANSWER, $result->reason);
    }

    public function testConnectionRefusedIsAnOutage(): void
    {
        // Nothing listens on this port: the transport must raise, not answer.
        $verifier = new Verifier(new CurlTransport(), 'http://127.0.0.1:1/siteverify', 2);
        $result = $verifier->verify('valid-token', self::FAKE_SECRET, null, new Policy());

        self::assertTrue($result->isUnreachable());
    }

    public function testV3ScoreAndActionTravelOverTheWire(): void
    {
        $policy = new Policy('contact', true, 0.5, true, ['shop.example'], 'closed');

        $high = (new Verifier(new CurlTransport(), self::BASE, 5))
            ->verify('v3-0.9-contact', self::FAKE_SECRET, null, $policy);
        self::assertTrue($high->isAccepted(), $high->reason);
        self::assertSame(0.9, $high->score);
        self::assertSame('contact', $high->action);

        $low = (new Verifier(new CurlTransport(), self::BASE, 5))
            ->verify('v3-0.1-contact', self::FAKE_SECRET, null, $policy);
        self::assertFalse($low->isAccepted());
        self::assertSame(VerificationResult::SCORE_TOO_LOW, $low->reason);
    }

    public function testHostnameFromAnotherSiteIsRefusedOverRealHttp(): void
    {
        $policy = new Policy('contact', true, 0.5, true, ['shop.example'], 'closed');

        $result = (new Verifier(new CurlTransport(), self::BASE, 5))
            ->verify('v3-0.9-contact-elsewhere', self::FAKE_SECRET, null, $policy);

        self::assertSame(VerificationResult::HOSTNAME_MISMATCH, $result->reason);
    }

    public function testLocalEndpointIsAcceptedAsAnHttpProxyTarget(): void
    {
        $settings = Settings::fromArray(['endpoint' => self::BASE]);

        self::assertSame(self::BASE, $settings->endpoint);
    }

    private function verify(string $token): VerificationResult
    {
        return (new Verifier(new CurlTransport(), self::BASE, 5))
            ->verify($token, self::FAKE_SECRET, '203.0.113.7', new Policy());
    }

    private static function fakeIsRunning(): bool
    {
        $socket = @fsockopen('127.0.0.1', 18093, $errno, $error, 0.5);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}