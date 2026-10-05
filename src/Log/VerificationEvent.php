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

namespace SelestRecaptcha\Log;

use SelestRecaptcha\Guard\Target;
use SelestRecaptcha\Verification\VerificationResult;

/**
 * One decision, as recorded for the merchant.
 *
 * Deliberately absent: the token and the secret. Neither is ever written to a
 * log, an exception message or a database row.
 */
final class VerificationEvent
{
    public function __construct(
        public readonly Target $target,
        public readonly VerificationResult $result,
        public readonly string $controller = '',
        public readonly string $ip = '',
        public readonly bool $failModeApplied = false,
        public readonly int $tokenAttempts = 1,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target->value,
            'accepted' => $this->result->isAccepted(),
            'reason' => $this->result->reason,
            'score' => $this->result->score,
            'action' => $this->result->action,
            'hostname' => $this->result->hostname,
            'error_codes' => $this->result->errorCodes,
            'controller' => $this->controller,
            'ip' => $this->ip,
            'fail_mode_applied' => $this->failModeApplied,
            'token_attempts' => $this->tokenAttempts,
            'reached_google' => $this->result->reachedGoogle,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return [
            'target' => $this->result->isAccepted() ? 'accepted' : 'refused',
            'reason' => $this->result->reason,
            'score' => $this->result->score,
            'action' => $this->result->action,
            'hostname' => $this->result->hostname,
            'error_codes' => $this->result->errorCodes,
            'controller' => $this->controller,
            'ip' => $this->ip,
            'fail_mode_applied' => $this->failModeApplied,
            'token_attempts' => $this->tokenAttempts,
            'reached_google' => $this->result->reachedGoogle,
        ];
    }
}