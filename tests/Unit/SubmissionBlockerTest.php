<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Diagnostics\KeyChecker;
use SelestRecaptcha\Guard\FlashHolder;
use SelestRecaptcha\Guard\RequestSnapshot;
use SelestRecaptcha\Guard\SubmissionBlocker;
use SelestRecaptcha\Guard\Target;
use SelestRecaptcha\Log\VerificationEvent;
use SelestRecaptcha\Verification\VerificationResult;
use SelestRecaptcha\Tests\Support\CollectingEventLogger;
use SelestRecaptcha\Tests\Support\FakeTransport;
use SelestRecaptcha\Tests\Support\RecordingRequestWriter;

final class SubmissionBlockerTest extends TestCase
{
    public function testContactBlockErasesTheSubmitFieldAndKeepsTheMessage(): void
    {
        $writer = new RecordingRequestWriter();
        $flash = new FlashHolder();

        $erased = (new SubmissionBlocker($writer, $flash))->block(Target::Contact, 'Prove it.');

        self::assertSame(['submitMessage', 'submitMessageGuest'], $erased);
        self::assertSame(['submitMessage', 'submitMessageGuest'], $writer->forgotten);
        self::assertSame('Prove it.', $flash->message());
        self::assertSame('error', $flash->type());
    }

    public function testEveryTargetHasATriggerToErase(): void
    {
        foreach (Target::all() as $target) {
            $writer = new RecordingRequestWriter();

            (new SubmissionBlocker($writer, new FlashHolder()))->block($target, 'nope');

            self::assertSame($target->submitFields(), $writer->forgotten);
            self::assertNotEmpty($writer->forgotten, $target->value . ' erases nothing');
        }
    }

    public function testAjaxTargetIsMarkedAsAjax(): void
    {
        $flash = new FlashHolder();

        (new SubmissionBlocker(new RecordingRequestWriter(), $flash))->block(Target::Comment, 'nope');

        self::assertSame('ajax', $flash->type());
    }

    public function testFlashHolderStartsEmpty(): void
    {
        $flash = new FlashHolder();

        self::assertFalse($flash->hasMessage());
        self::assertNull($flash->message());

        $flash->clear();
        self::assertFalse($flash->hasMessage());
    }

    public function testRequestSnapshotCollectsTokensFromAnArrayField(): void
    {
        $request = new RequestSnapshot('contact', ['grecaptcha-response' => ['a', '', 'b', 42]], [], false, '', '', true);

        self::assertSame(['a', 'b'], $request->tokens());
    }

    public function testRequestSnapshotDeduplicatesTokens(): void
    {
        $request = new RequestSnapshot('contact', ['grecaptcha-response' => ['same', 'same']], [], false, '', '', true);

        self::assertSame(['same'], $request->tokens());
    }

    public function testRequestSnapshotIgnoresNonStringTokens(): void
    {
        $request = new RequestSnapshot('contact', ['grecaptcha-response' => [['nested'], null, 1]], [], false, '', '', true);

        self::assertSame([], $request->tokens());
    }

    public function testRequestSnapshotFallsBackToTheQueryString(): void
    {
        $request = new RequestSnapshot('contact', [], ['grecaptcha-response' => 'from-get'], false, '', '', false);

        self::assertSame(['from-get'], $request->tokens());
    }

    public function testRequestSnapshotHasNoTokenWhenTheFieldIsAbsent(): void
    {
        self::assertSame([], (new RequestSnapshot())->tokens());
    }

    public function testIsSubmitMirrorsToolsIsSubmit(): void
    {
        $request = new RequestSnapshot('contact', ['a' => 1], ['b' => 2], false, '', '', true);

        self::assertTrue($request->isSubmit('a'));
        self::assertTrue($request->isSubmit('b'));
        self::assertFalse($request->isSubmit('c'));
    }
}

final class KeyCheckerTest extends TestCase
{
    public function testValidSecretIsReportedAsWorking(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":false,"error-codes":["invalid-input-response"]}';

        self::assertSame(KeyChecker::OK, (new KeyChecker($transport))->check($this->settings()));
    }

    public function testWrongSecretIsReported(): void
    {
        $transport = new FakeTransport();
        $transport->body = '{"success":false,"error-codes":["invalid-input-secret"]}';

        self::assertSame(KeyChecker::WRONG_SECRET, (new KeyChecker($transport))->check($this->settings()));
    }

    public function testUnreachableEndpointIsReported(): void
    {
        $transport = new FakeTransport();
        $transport->shouldThrow = true;

        self::assertSame(KeyChecker::UNREACHABLE, (new KeyChecker($transport))->check($this->settings()));
    }

    public function testMissingKeysAreReportedWithoutCallingGoogle(): void
    {
        $transport = new FakeTransport();

        $settings = $this->settings();
        $settings = \SelestRecaptcha\Config\Settings::fromArray(['enabled' => true]);

        self::assertSame(KeyChecker::NOT_CONFIGURED, (new KeyChecker($transport))->check($settings));
        self::assertSame(0, $transport->callCount());
    }

    private function settings(): \SelestRecaptcha\Config\Settings
    {
        return \SelestRecaptcha\Config\Settings::fromArray([
            'enabled' => true,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
        ]);
    }
}