<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Config\RecaptchaVersion;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Config\TargetSettings;
use SelestRecaptcha\Verification\Policy;
use SelestRecaptcha\Verification\VerificationResult;
use SelestRecaptcha\Verification\Verifier;
use SelestRecaptcha\Tests\Support\FakeTransport;

final class VerifierTest extends TestCase
{
    private const SECRET = 'a-secret-key';

    public function testMissingTokenIsRefusedWithoutCallingGoogle(): void
    {
        $transport = new FakeTransport();
        $verifier = new Verifier($transport, 'https://example.test/verify', 5);

        $result = $verifier->verify('   ', self::SECRET, '1.2.3.4', new Policy());

        self::assertFalse($result->isAccepted());
        self::assertSame(VerificationResult::MISSING_TOKEN, $result->reason);
        self::assertSame(0, $transport->callCount(), 'a missing token must not cost a Google call');
    }

    public function testValidV2TokenIsAccepted(): void
    {
        $transport = new FakeTransport();
        $transport->body = json_encode([
            'success' => true,
            'challenge_ts' => '2026-10-05T10:00:00Z',
            'hostname' => 'shop.example',
        ]);

        $result = $this->verify($transport, 'token');

        self::assertTrue($result->isAccepted());
        self::assertSame(VerificationResult::OK, $result->reason);
        self::assertSame('shop.example', $result->hostname);
        self::assertSame(self::SECRET, $transport->lastFields()['secret']);
        self::assertSame('token', $transport->lastFields()['response']);
        self::assertSame('1.2.3.4', $transport->lastFields()['remoteip']);
    }

    public function testRemoteIpIsOmittedWhenAbsent(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true}';

        $this->verify($transport, 'token', null);

        self::assertArrayNotHasKey('remoteip', $transport->lastFields());
    }

    public function testInvalidInputResponseIsADefinitiveRefusal(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":false,"error-codes":["invalid-input-response"]}';

        $result = $this->verify($transport, 'token');

        self::assertFalse($result->isAccepted());
        self::assertSame(VerificationResult::REJECTED, $result->reason);
        self::assertTrue($result->isDefinitiveRefusal());
        self::assertSame(['invalid-input-response'], $result->errorCodes);
    }

    public function testReplayIsReportedAsExpiredOrReused(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":false,"error-codes":["timeout-or-duplicate"]}';

        $result = $this->verify($transport, 'token');

        self::assertSame(VerificationResult::TOKEN_EXPIRED_OR_REUSED, $result->reason);
        self::assertFalse($result->isUnreachable(), 'Google answered: the fail mode must not apply');
    }

    public function testInvalidSecretIsTreatedAsAnOutageNotAsSpam(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":false,"error-codes":["invalid-input-secret"]}';

        $result = $this->verify($transport, 'token');

        self::assertTrue($result->isUnreachable(), 'a wrong secret is a configuration fault');
        self::assertSame(VerificationResult::INVALID_SECRET, $result->reason);
    }

    public function testV3ScoreBelowThresholdIsRefused(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true,"score":0.1,"action":"contact","hostname":"shop.example"}';

        $result = $this->verify(
            $transport,
            'token',
            null,
            new Policy('contact', true, 0.5, false, [], 'closed')
        );

        self::assertFalse($result->isAccepted());
        self::assertSame(VerificationResult::SCORE_TOO_LOW, $result->reason);
        self::assertSame(0.1, $result->score);
        self::assertSame(['score'], $result->explanation);
    }

    public function testV3ActionMismatchIsRefused(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true,"score":0.9,"action":"checkout","hostname":"shop.example"}';

        $result = $this->verify(
            $transport,
            'token',
            null,
            new Policy('contact', true, 0.5, false, [], 'closed')
        );

        self::assertSame(VerificationResult::ACTION_MISMATCH, $result->reason);
    }

    public function testV3ActionComparisonIsCaseInsensitiveLikeGoogle(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true,"score":0.9,"action":"Contact","hostname":"shop.example"}';

        $result = $this->verify(
            $transport,
            'token',
            null,
            new Policy('contact', true, 0.5, false, [], 'closed')
        );

        self::assertTrue($result->isAccepted());
    }

    public function testV2DoesNotRequireAnAction(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true,"hostname":"shop.example"}';

        // scoreBased false: asking for an action on v2 would reject everyone.
        $result = $this->verify($transport, 'token', null, new Policy('contact', false));

        self::assertTrue($result->isAccepted());
    }

    public function testV3WithoutScoreIsRefused(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true,"action":"contact"}';

        $result = $this->verify(
            $transport,
            'token',
            null,
            new Policy('contact', true, 0.5, false, [], 'closed')
        );

        self::assertFalse($result->isAccepted());
    }

    public function testStrictHostnameAcceptsSubdomainsButNotLookalikes(): void
    {
        $policy = new Policy(null, false, 0.5, true, ['shop.example']);

        self::assertTrue($policy->allowsHostname('shop.example'));
        self::assertTrue($policy->allowsHostname('www.shop.example'));
        self::assertTrue($policy->allowsHostname('SHOP.EXAMPLE'));
        self::assertFalse($policy->allowsHostname('evil-shop.example'));
        self::assertFalse($policy->allowsHostname('shop.example.evil.com'));
        self::assertFalse($policy->allowsHostname(''));
        self::assertFalse($policy->allowsHostname(null));
    }

    public function testHostnameFromGoogleIsCheckedWhenStrict(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true,"hostname":"evil.example"}';

        $result = $this->verify(
            $transport,
            'token',
            null,
            new Policy(null, false, 0.5, true, ['shop.example'], 'closed')
        );

        self::assertSame(VerificationResult::HOSTNAME_MISMATCH, $result->reason);
    }

    public function testTransportFailureIsUnreachable(): void
    {
        $transport = new FakeTransport();
        $transport->shouldThrow = true;

        $result = $this->verify($transport, 'token');

        self::assertTrue($result->isUnreachable());
        self::assertFalse($result->isDefinitiveRefusal());
        self::assertSame(['unreachable'], $result->explanation);
    }

    public function testNonGoogleBodyIsUnreachableNotAccepted(): void
    {
        $transport = new FakeTransport();
        $transport->body = '<html><body>Service unavailable</body></html>';

        $result = $this->verify($transport, 'token');

        self::assertFalse($result->isAccepted(), 'a captive portal must never read as a pass');
        self::assertTrue($result->isUnreachable());
        self::assertSame(VerificationResult::MALFORMED_ANSWER, $result->reason);
    }

    public function testJsonWithoutSuccessKeyIsUnreachable(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"foo":"bar"}';

        $result = $this->verify($transport, 'token');

        self::assertTrue($result->isUnreachable());
    }

    public function testSuccessStringIsNotAValidSuccess(): void
    {
        // Google sends a boolean. A body saying "success":"true" is not the
        // documented contract and must not pass the gate.
        $transport = new FakeTransport();
        $transport->body = '{"success":"true"}';

        $result = $this->verify($transport, 'token');

        self::assertFalse($result->isAccepted());
    }

    public function testErrorCodesGivenAsAHostileTypeDoNotBreakParsing(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":false,"error-codes":{"a":"b"}}';

        $result = $this->verify($transport, 'token');

        self::assertFalse($result->isAccepted());
        // The field must never make the verifier trust an answer it does not
        // understand; the refusal stands whatever the shape was.
        self::assertSame(VerificationResult::REJECTED, $result->reason);
    }

    public function testScoreIsClampedByThePolicyNotByTheAnswer(): void
    {
        $transport = new FakeTransport();
        // A tampered answer claiming a perfect score still has to clear the
        // threshold the merchant chose.
        $transport->body = '{"success":true,"score":1,"action":"contact","hostname":"shop.example"}';

        $result = $this->verify(
            $transport,
            'token',
            null,
            new Policy('contact', true, 0.7, false, [], 'closed')
        );

        self::assertTrue($result->isAccepted());
        self::assertSame(1.0, $result->score);
    }

    private function verify(
        FakeTransport $transport,
        string $token,
        ?string $ip = '1.2.3.4',
        ?Policy $policy = null
    ): VerificationResult {
        return (new Verifier($transport, 'https://example.test/verify', 5))
            ->verify($token, self::SECRET, $ip, $policy ?? new Policy());
    }
}