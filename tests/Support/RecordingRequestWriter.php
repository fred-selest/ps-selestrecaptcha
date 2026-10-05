<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Support;

use SelestRecaptcha\Guard\RequestWriterInterface;

/**
 * Records what the blocker erased, instead of touching superglobals.
 */
final class RecordingRequestWriter implements RequestWriterInterface
{
    /** @var array<int, string> */
    public array $forgotten = [];

    public function forgetField(string $field): void
    {
        $this->forgotten[] = $field;
    }
}