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

namespace SelestRecaptcha\Diagnostics;

use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Http\TransportInterface;
use SelestRecaptcha\Verification\Policy;
use SelestRecaptcha\Verification\VerificationResult;
use SelestRecaptcha\Verification\Verifier;

/**
 * Back-office self test: "are my keys actually working from this server?".
 *
 * Google checks the secret before the token, so a deliberately bogus token
 * tells us three different things:
 *
 *   invalid-input-secret / bad-request  → the secret is wrong
 *   invalid-input-response               → the secret is accepted, the shop can
 *                                         reach Google
 *   nothing at all (timeout, TLS, 5xx)  → the shop cannot reach the endpoint
 *
 * That last case is the one merchants cannot diagnose on their own, and it is
 * exactly what fails closed in production.
 */
final class KeyChecker
{
    public const OK = 'ok';
    public const WRONG_SECRET = 'wrong_secret';
    public const UNREACHABLE = 'unreachable';
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(private readonly TransportInterface $transport)
    {
    }

    public function check(Settings $settings): string
    {
        if ($settings->siteKey === '' || $settings->secretKey === '') {
            return self::NOT_CONFIGURED;
        }

        $verifier = new Verifier($this->transport, $settings->endpoint, $settings->timeout);
        $result = $verifier->verify(
            'selestrecaptcha-diagnostic-token',
            $settings->secretKey,
            null,
            new Policy(failMode: 'closed')
        );

        if ($result->isUnreachable()) {
            return VerificationResult::INVALID_SECRET === $result->reason
                ? self::WRONG_SECRET
                : self::UNREACHABLE;
        }

        // Any answer that is not "wrong secret" proves Google read our secret.
        return self::OK;
    }
}