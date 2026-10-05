<?php
/**
 * Copyright since 2026 Selest
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you wish to redistribute this file, please do so only under the terms
 * of the AFL-3.0 license. All other rights are reserved.
 *
 * @author    Fred Selest
 * @copyright 2026 Selest
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace SelestRecaptcha\Verification;

/**
 * Outcome of judging one submission.
 */
final class Judgement
{
    /**
     * @param array<int, string> $messageKeys
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly VerificationResult $result,
        public readonly bool $failModeApplied = false,
        public readonly array $messageKeys = ['block'],
        public readonly int $tokenAttempts = 0,
    ) {
    }

    /**
     * First configured message key, falling back to the generic refusal.
     */
    public function messageKey(): string
    {
        return $this->messageKeys[0] ?? 'block';
    }
}