<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Config\TargetSettings;
use SelestRecaptcha\Guard\RequestSnapshot;
use SelestRecaptcha\Guard\SubmissionInspector;
use SelestRecaptcha\Guard\Target;

final class SubmissionInspectorTest extends TestCase
{
    public function testContactSubmissionIsDetected(): void
    {
        self::assertSame(
            Target::Contact,
            $this->inspector()->detect($this->post(['submitMessage' => 'Submit message', 'message' => 'hi']))
        );
    }

    public function testRegistrationSubmissionIsDetected(): void
    {
        self::assertSame(
            Target::Registration,
            $this->inspector()->detect($this->post(['submitCreate' => '1', 'email' => 'a@b.test']))
        );
    }

    public function testNewsletterSubmissionIsDetected(): void
    {
        self::assertSame(
            Target::Newsletter,
            $this->inspector()->detect($this->post(['submitNewsletter' => 'ok', 'email' => 'a@b.test']))
        );
    }

    public function testReviewSubmissionIsDetected(): void
    {
        self::assertSame(
            Target::Comment,
            $this->inspector()->detect($this->post(['comment_content' => 'buy cheap', 'id_product' => '1']))
        );
    }

    public function testAnOrdinaryPageViewIsNotADetection(): void
    {
        self::assertNull($this->inspector()->detect($this->post(['email' => 'a@b.test'])));
    }

    public function testAGetRequestIsNeverASubmission(): void
    {
        // Someone replaying a query string must not reach the handler twice.
        $request = new RequestSnapshot('contact', [], ['submitMessage' => '1'], false, '', '', false);

        self::assertNull($this->inspector()->detect($request));
    }

    public function testDisabledTargetIsNotDetected(): void
    {
        $inspector = $this->inspector(['newsletter' => ['enabled' => false]]);

        self::assertNull($inspector->detect($this->post(['submitNewsletter' => 'ok'])));
    }

    public function testDisabledModuleDetectsNothingAtAll(): void
    {
        $settings = Settings::fromArray([
            'enabled' => false,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
        ]);

        self::assertNull((new SubmissionInspector($settings))->detect($this->post(['submitMessage' => '1'])));
    }

    public function testDetectAllReportsEveryTriggerPresent(): void
    {
        $matches = $this->inspector()->detectAll($this->post([
            'submitMessage' => '1',
            'comment_content' => 'spam',
        ]));

        self::assertSame([Target::Contact, Target::Comment], $matches);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function post(array $post): RequestSnapshot
    {
        return new RequestSnapshot('contact', $post, [], false, '', '', true);
    }

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    private function inspector(array $overrides = []): SubmissionInspector
    {
        $targets = [];

        foreach (Target::all() as $target) {
            $targets[$target->value] = TargetSettings::fromArray(
                array_merge(['enabled' => true, 'action' => $target->defaultAction()], $overrides[$target->value] ?? []),
                $target->defaultAction()
            );
        }

        return new SubmissionInspector(Settings::fromArray([
            'enabled' => true,
            'site_key' => 'site-key-for-tests-000000',
            'secret_key' => 'secret-key-for-tests-00000',
            'targets' => $targets,
        ]));
    }
}