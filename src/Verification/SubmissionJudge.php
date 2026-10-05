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

use SelestRecaptcha\Config\RecaptchaVersion;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Guard\RequestSnapshot;
use SelestRecaptcha\Guard\Target;
use SelestRecaptcha\Log\EventLoggerInterface;
use SelestRecaptcha\Log\VerificationEvent;

/**
 * The decision: does this submission go through?
 *
 * Three rules, in this order, and the order is the whole design:
 *
 *  1. A token verified by Google and matching our own expectations (score,
 *     action, hostname) is accepted. No further question.
 *  2. Google answered "no", or the visitor sent no token at all: refused.
 *     The fail mode does not apply — this is a real answer, not an absence of
 *     one.
 *  3. We never reached Google (timeout, TLS failure, HTTP 5xx, HTML error
 *     page). Here the merchant's fail mode decides, because blocking customers
 *     because Google is down costs real sales.
 */
final class SubmissionJudge
{
    /**
     * A page can carry several widgets; try a handful of tokens before giving
     * up, but never more than this, so a crafted POST cannot turn one request
     * into a hundred calls to Google.
     */
    private const MAX_TOKENS = 3;

    public function __construct(
        private readonly Settings $settings,
        private readonly Verifier $verifier,
        private readonly EventLoggerInterface $logger,
    ) {
    }

    public function judge(RequestSnapshot $request, Target $target): Judgement
    {
        $policy = $this->policy($target);
        $tokens = $request->tokens();
        $attempts = 0;
        $lastResult = null;

        if ($tokens === []) {
            // No widget was answered: a bot posting straight to the form, or a
            // visitor whose JavaScript never ran.
            $lastResult = $this->verifier->verify('', $this->settings->secretKey, $request->remoteIp, $policy);
            $attempts = 0;
        } else {
            foreach (array_slice($tokens, 0, self::MAX_TOKENS) as $token) {
                ++$attempts;
                $lastResult = $this->verifier->verify($token, $this->settings->secretKey, $request->remoteIp, $policy);

                if ($lastResult->isAccepted()) {
                    return $this->finish(
                        $request,
                        $target,
                        $lastResult,
                        true,
                        false,
                        $attempts
                    );
                }
            }
        }

        /** @var VerificationResult $lastResult */
        $failModeApplied = false;

        if ($lastResult->isUnreachable() && 'open' === $this->settings->failMode) {
            $failModeApplied = true;

            return $this->finish($request, $target, $lastResult, true, true, $attempts);
        }

        return $this->finish($request, $target, $lastResult, false, false, $attempts);
    }

    public function policy(Target $target): Policy
    {
        $scoreBased = RecaptchaVersion::isScoreBased($this->settings->version);

        return new Policy(
            // Only v3 carries an action; asking for one on v2 would reject
            // every legitimate visitor, because Google sends none.
            $scoreBased ? $this->settings->target($target)->action : null,
            $scoreBased,
            $this->settings->effectiveMinScore($target),
            $this->settings->effectiveStrictHostname($target),
            $this->settings->allowedHostnames(),
            $this->settings->failMode,
        );
    }

    private function finish(
        RequestSnapshot $request,
        Target $target,
        VerificationResult $result,
        bool $allowed,
        bool $failModeApplied,
        int $attempts
    ): Judgement {
        $this->logger->log(new VerificationEvent(
            $target,
            $result,
            $request->controller,
            $request->remoteIp,
            $failModeApplied,
            $attempts
        ));

        return new Judgement(
            $allowed,
            $result,
            $failModeApplied,
            $result->explanation !== [] ? $result->explanation : ['block'],
            $attempts
        );
    }
}