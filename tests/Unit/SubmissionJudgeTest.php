<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Config\RecaptchaVersion;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Config\TargetSettings;
use SelestRecaptcha\Guard\RequestSnapshot;
use SelestRecaptcha\Guard\SubmissionInspector;
use SelestRecaptcha\Guard\Target;
use SelestRecaptcha\Http\CurlTransport;
use SelestRecaptcha\Tests\Support\CollectingEventLogger;
use SelestRecaptcha\Verification\SubmissionJudge;
use SelestRecaptcha\Verification\VerificationResult;
use SelestRecaptcha\Verification\Verifier;
use SelestRecaptcha\Tests\Support\FakeTransport;

final class SubmissionJudgeTest extends TestCase
{
    public function testAcceptedTokenLetsTheSubmissionThrough(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true}';
        $logger = new CollectingEventLogger();

        $judgement = $this->judge($transport, $logger)->judge(
            $this->request(['submitMessage' => '1', 'grecaptcha-response' => 'good']),
            Target::Contact
        );

        self::assertTrue($judgement->allowed);
        self::assertFalse($judgement->failModeApplied);
        self::assertSame(1, $logger->count());
        self::assertTrue($logger->last()->result->isAccepted());
    }

    public function testBotWithoutTokenIsRefusedEvenWhenFailModeIsOpen(): void
    {
        $transport = new FakeTransport();
        $transport->shouldThrow = true; // would be "unreachable" if we asked
        $logger = new CollectingEventLogger();

        $judgement = $this->judge($transport, $logger, 'open')->judge(
            $this->request(['submitMessage' => '1']),
            Target::Contact
        );

        self::assertFalse($judgement->allowed);
        self::assertSame(VerificationResult::MISSING_TOKEN, $judgement->result->reason);
        self::assertSame(0, $transport->callCount());
    }

    public function testOutageWithFailModeOpenLetsTheSubmissionThrough(): void
    {
        $transport = new FakeTransport();
        $transport->shouldThrow = true;
        $logger = new CollectingEventLogger();

        $judgement = $this->judge($transport, $logger, 'open')->judge(
            $this->request(['submitMessage' => '1', 'grecaptcha-response' => 'x']),
            Target::Contact
        );

        self::assertTrue($judgement->allowed);
        self::assertTrue($judgement->failModeApplied);
    }

    public function testOutageWithFailModeClosedBlocksTheSubmission(): void
    {
        $transport = new FakeTransport();
        $transport->shouldThrow = true;

        $judgement = $this->judge($transport, new CollectingEventLogger(), 'closed')->judge(
            $this->request(['submitMessage' => '1', 'grecaptcha-response' => 'x']),
            Target::Contact
        );

        self::assertFalse($judgement->allowed);
        self::assertFalse($judgement->failModeApplied);
    }

    public function testDefinitiveRefusalIgnoresFailModeOpen(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":false,"error-codes":["invalid-input-response"]}';

        $judgement = $this->judge($transport, new CollectingEventLogger(), 'open')->judge(
            $this->request(['submitMessage' => '1', 'grecaptcha-response' => 'bot']),
            Target::Contact
        );

        self::assertFalse($judgement->allowed);
        self::assertFalse($judgement->failModeApplied);
    }

    public function testSeveralTokensAreTriedUntilOneIsAccepted(): void
    {
        $transport = new FakeTransport();
        // The first token is a stale one from the newsletter widget on the same
        // page; the second is the one the contact form just solved.
        $transport->queue = [
            '{"success":false,"error-codes":["invalid-input-response"]}',
            '{"success":true,"hostname":"shop.example"}',
        ];

        $judgement = $this->judge($transport, new CollectingEventLogger())->judge(
            $this->request([
                'submitMessage' => '1',
                'grecaptcha-response' => ['stale', 'fresh'],
            ]),
            Target::Contact
        );

        self::assertTrue($judgement->allowed);
        self::assertSame(2, $transport->callCount(), 'both tokens must be offered to Google');
        self::assertSame(2, $judgement->tokenAttempts);
    }

    public function testTokenBudgetIsCapped(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":false,"error-codes":["invalid-input-response"]}';

        $judgement = $this->judge($transport, new CollectingEventLogger())->judge(
            $this->request([
                'submitMessage' => '1',
                'grecaptcha-response' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
            ]),
            Target::Contact
        );

        self::assertSame(3, $transport->callCount(), 'a crafted POST must not become a Google flood');
    }

    public function testV3PolicyCarriesTheActionOfTheTarget(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true,"score":0.9,"action":"newsletter","hostname":"shop.example"}';

        $judgement = $this->judge($transport, new CollectingEventLogger())->judge(
            $this->request(['submitNewsletter' => '1', 'grecaptcha-response' => 'token']),
            Target::Newsletter
        );

        self::assertTrue($judgement->allowed);
    }

    public function testV3TokenForAnotherActionIsRefused(): void
    {
        $transport = new FakeTransport();
        // A token minted for the contact form, replayed on the review form.
        $transport->body = '{"success":true,"score":0.9,"action":"contact","hostname":"shop.example"}';

        $v3Judge = new SubmissionJudge(
            $this->settings('v3'),
            new Verifier($transport, 'https://example.test/verify', 5),
            new CollectingEventLogger()
        );

        $judgement = $v3Judge->judge(
            $this->request(['comment_content' => 'spam', 'grecaptcha-response' => 'token']),
            Target::Comment
        );

        self::assertFalse($judgement->allowed);
        self::assertSame(VerificationResult::ACTION_MISMATCH, $judgement->result->reason);
    }

    public function testPerTargetScoreOverridesTheGlobalThreshold(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":true,"score":0.6,"action":"contact","hostname":"shop.example"}';

        $settings = $this->settings('v3', [
            // Reviews are held to a higher bar than the contact form.
            'comment' => ['min_score' => 0.9],
        ]);

        $judge = new SubmissionJudge($settings, new Verifier($transport, 'https://example.test/verify', 5), new CollectingEventLogger());

        $contact = $judge->judge(
            $this->request(['submitMessage' => '1', 'grecaptcha-response' => 't']),
            Target::Contact
        );
        $comment = $judge->judge(
            $this->request(['comment_content' => 'spam', 'grecaptcha-response' => 't']),
            Target::Comment
        );

        self::assertTrue($contact->allowed, '0.6 clears the global 0.5');
        self::assertFalse($comment->allowed, '0.6 does not clear the 0.9 set for reviews');
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array<string, mixed>
     */
    private function request(array $post): RequestSnapshot
    {
        return new RequestSnapshot('contact', $post, [], false, '203.0.113.7', 'shop.example', true);
    }

    private function judge(
        FakeTransport $transport,
        CollectingEventLogger $logger,
        string $failMode = 'closed'
    ): SubmissionJudge {
        return new SubmissionJudge(
            $this->settings('v2_checkbox', [], $failMode),
            new Verifier($transport, 'https://example.test/verify', 5),
            $logger
        );
    }

    /**
     * @param array<string, array<string, mixed>> $targetOverrides
     */
    private function settings(string $version, array $targetOverrides = [], string $failMode = 'closed'): Settings
    {
        $targets = [];

        foreach (Target::all() as $target) {
            $targets[$target->value] = TargetSettings::fromArray(
                array_merge(['enabled' => true, 'action' => $target->defaultAction()], $targetOverrides[$target->value] ?? []),
                $target->defaultAction()
            );
        }

        return Settings::fromArray([
            'enabled' => true,
            'version' => $version,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
            'min_score' => 0.5,
            'fail_mode' => $failMode,
            'targets' => $targets,
        ], ['https://shop.example/']);
    }
}
