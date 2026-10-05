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
 * Why a submission was accepted or refused.
 *
 * The distinction that matters: `accepted` is what Google said, `unreachable`
 * is what we could not find out. They lead to different behaviour (fail mode),
 * so they are not the same value.
 */
final class VerificationResult
{
    public const OK = 'ok';
    public const MISSING_TOKEN = 'missing_token';
    public const INVALID_SECRET = 'invalid_secret';
    public const TOKEN_EXPIRED_OR_REUSED = 'token_expired_or_reused';
    public const BAD_REQUEST = 'bad_request';
    public const REJECTED = 'rejected';
    public const SCORE_TOO_LOW = 'score_too_low';
    public const ACTION_MISMATCH = 'action_mismatch';
    public const HOSTNAME_MISMATCH = 'hostname_mismatch';
    public const MALFORMED_ANSWER = 'malformed_answer';
    public const UNREACHABLE = 'unreachable';

    /**
     * @param array<int, string> $errorCodes error-codes as sent by Google
     * @param array<int, string> $explanation merchant-facing, translated at render time
     */
    private function __construct(
        public readonly bool $accepted,
        public readonly string $reason,
        public readonly ?float $score = null,
        public readonly ?string $action = null,
        public readonly ?string $hostname = null,
        public readonly array $errorCodes = [],
        public readonly array $explanation = [],
        /** True when Google answered, false when we never reached it. */
        public readonly bool $reachedGoogle = true,
    ) {
    }

    /**
     * @param array<int, string> $errorCodes
     * @param array<int, string> $explanation
     */
    public static function accepted(
        ?float $score = null,
        ?string $action = null,
        ?string $hostname = null,
        array $errorCodes = []
    ): self {
        return new self(true, self::OK, $score, $action, $hostname, $errorCodes);
    }

    /**
     * @param array<int, string> $errorCodes
     * @param array<int, string> $explanation
     */
    public static function refused(
        string $reason,
        ?float $score = null,
        ?string $action = null,
        ?string $hostname = null,
        array $errorCodes = [],
        array $explanation = []
    ): self {
        return new self(false, $reason, $score, $action, $hostname, $errorCodes, $explanation);
    }

    /**
     * We never got an answer: timeouts, TLS failure, HTTP 5xx, non-JSON body.
     *
     * @param array<int, string> $explanation
     */
    public static function unreachable(string $reason = self::UNREACHABLE, array $explanation = []): self
    {
        return new self(false, $reason, null, null, null, [], $explanation, false);
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    /**
     * True when the refusal is definitive (Google said no, or the visitor sent
     * no token at all) and the fail mode must not apply.
     */
    public function isDefinitiveRefusal(): bool
    {
        return !$this->accepted && $this->reachedGoogle;
    }

    public function isUnreachable(): bool
    {
        return !$this->reachedGoogle;
    }
}