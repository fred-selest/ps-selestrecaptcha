<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Admin\ConflictingModules;

final class ConflictingModulesTest extends TestCase
{
    public function testAnInstalledAndActiveOtherModuleIsFound(): void
    {
        $conflicts = new ConflictingModules('selestrecaptcha', ['psrecaptcha' => true]);

        self::assertSame(['psrecaptcha'], $conflicts->active());
    }

    public function testTheModuleItselfIsNeverReported(): void
    {
        $conflicts = new ConflictingModules('psrecaptcha', ['psrecaptcha' => true]);

        self::assertSame([], $conflicts->active());
    }

    public function testAnInstalledButDisabledModuleIsNotReported(): void
    {
        // A disabled module renders nothing, so there is nothing to warn about.
        $conflicts = new ConflictingModules('selestrecaptcha', ['psrecaptcha' => false]);

        self::assertSame([], $conflicts->active());
    }

    public function testUnrelatedModulesAreNotReported(): void
    {
        $conflicts = new ConflictingModules('selestrecaptcha', [
            'ps_emailsubscription' => true,
            'contactform' => true,
            'ps_checkout' => true,
        ]);

        self::assertSame([], $conflicts->active());
    }

    public function testEveryKnownNameIsRecognised(): void
    {
        $installed = array_fill_keys(ConflictingModules::KNOWN, true);

        self::assertSame(
            ConflictingModules::KNOWN,
            (new ConflictingModules('selestrecaptcha', $installed))->active()
        );
    }

    public function testTheQueryQuotesEveryName(): void
    {
        $query = ConflictingModules::buildQuery();

        // pSQL() escapes but does not quote: without the quotes the IN list is a
        // SQL error, and a silent empty result looks like "no conflict found".
        foreach (ConflictingModules::KNOWN as $name) {
            self::assertStringContainsString("'" . $name . "'", $query);
        }

        self::assertMatchesRegularExpression("/WHERE name IN \('.*'(, '.*')+\)/", $query);
    }

    public function testWithoutPrestaShopTheAnswerIsEmptyRatherThanAFatal(): void
    {
        // No database, no _DB_PREFIX_: the back office must still render.
        self::assertSame([], (new ConflictingModules('selestrecaptcha'))->active());
    }
}