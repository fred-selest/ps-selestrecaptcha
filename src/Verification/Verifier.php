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

use SelestRecaptcha\Http\TransportException;
use SelestRecaptcha\Http\TransportInterface;

/**
 * Turns a token into a decision.
 *
 * Google's siteverify contract (https://developers.google.com/recaptcha/docs/verify):
 *   success     bool
 *   score       float    (v3 only)
 *   action      string   (v3 only)
 *   challenge_ts string
 *   hostname    string
 *   error-codes array
 */
final class Verifier
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $endpoint,
        private readonly int $timeout = 5,
    ) {
    }

    public function verify(string $token, string $secret, ?string $remoteIp, Policy $policy): VerificationResult
    {
        $token = trim($token);

        // A visitor who never answered the captcha sends nothing at all. This
        // is the most common spam signature and must never be softened by the
        // fail mode.
        if ($token === '') {
            return VerificationResult::refused(
                VerificationResult::MISSING_TOKEN,
                explanation: ['block']
            );
        }

        $fields = ['secret' => $secret, 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }

        try {
            $raw = $this->transport->post($this->endpoint, $fields, $this->timeout);
        } catch (TransportException $exception) {
            return VerificationResult::unreachable(
                VerificationResult::UNREACHABLE,
                ['unreachable']
            );
        }

        $decoded = json_decode($raw, true);

        // A body that is not the JSON object Google documents means we did not
        // really talk to Google (captive portal, proxy, quota page).
        if (!is_array($decoded) || !array_key_exists('success', $decoded)) {
            return VerificationResult::unreachable(
                VerificationResult::MALFORMED_ANSWER,
                ['unreachable']
            );
        }

        $score = isset($decoded['score']) && is_numeric($decoded['score'])
            ? (float) $decoded['score']
            : null;
        $action = isset($decoded['action']) && is_string($decoded['action'])
            ? $decoded['action']
            : null;
        $hostname = isset($decoded['hostname']) && is_string($decoded['hostname'])
            ? $decoded['hostname']
            : null;
        $errorCodes = $this->errorCodes($decoded);

        if ($decoded['success'] !== true) {
            return $this->refuseForErrorCodes($errorCodes, $score, $action, $hostname);
        }

        // Google validated the token. The remaining checks are ours: a token is
        // only valid for the action and the hostname it was issued for.
        if ($policy->scoreBased) {
            if ($score === null) {
                return VerificationResult::refused(
                    VerificationResult::REJECTED,
                    action: $action,
                    hostname: $hostname,
                    explanation: ['block']
                );
            }

            if ($score < $policy->minScore) {
                return VerificationResult::refused(
                    VerificationResult::SCORE_TOO_LOW,
                    score: $score,
                    action: $action,
                    hostname: $hostname,
                    explanation: ['score']
                );
            }

            if ($policy->expectedAction !== null
                && ($action === null || strcasecmp($action, $policy->expectedAction) !== 0)
            ) {
                return VerificationResult::refused(
                    VerificationResult::ACTION_MISMATCH,
                    score: $score,
                    action: $action,
                    hostname: $hostname,
                    explanation: ['block']
                );
            }
        }

        if (!$policy->allowsHostname($hostname)) {
            return VerificationResult::refused(
                VerificationResult::HOSTNAME_MISMATCH,
                score: $score,
                action: $action,
                hostname: $hostname,
                explanation: ['block']
            );
        }

        return VerificationResult::accepted($score, $action, $hostname, $errorCodes);
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array<int, string>
     */
    private function errorCodes(array $decoded): array
    {
        $raw = $decoded['error-codes'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $code) {
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param array<int, string> $errorCodes
     */
    private function refuseForErrorCodes(
        array $errorCodes,
        ?float $score,
        ?string $action,
        ?string $hostname
    ): VerificationResult {
        $reason = match (true) {
            in_array('invalid-input-secret', $errorCodes, true) => VerificationResult::INVALID_SECRET,
            in_array('missing-input-secret', $errorCodes, true) => VerificationResult::INVALID_SECRET,
            // Token older than two minutes, or a token replayed: this is the
            // signature of an automated replay.
            in_array('timeout-or-duplicate', $errorCodes, true) => VerificationResult::TOKEN_EXPIRED_OR_REUSED,
            in_array('missing-input-response', $errorCodes, true) => VerificationResult::MISSING_TOKEN,
            in_array('invalid-input-response', $errorCodes, true) => VerificationResult::REJECTED,
            in_array('bad-request', $errorCodes, true) => VerificationResult::BAD_REQUEST,
            default => VerificationResult::REJECTED,
        };

        // A refused secret is a configuration problem, not spam: showing the
        // visitor a captcha error would be a lie, and so would logging it as
        // rejected traffic. It is surfaced as an outage of the config itself.
        if (VerificationResult::INVALID_SECRET === $reason) {
            return VerificationResult::unreachable(
                VerificationResult::INVALID_SECRET,
                ['unreachable']
            );
        }

        return VerificationResult::refused($reason, $score, $action, $hostname, $errorCodes, ['block']);
    }
}