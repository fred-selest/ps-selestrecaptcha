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

/**
 * Writes verification events to the PrestaShop log channel
 * (Information > Logs > PrestaShop logs, channel "selestrecaptcha").
 *
 * Refusals are warnings, accepted submissions are debug-level noise: nobody
 * needs 400 lines saying "captcha passed" per day.
 */
final class PsEventLogger implements EventLoggerInterface
{
    private const CHANNEL = 'selestrecaptcha';

    public function __construct(private readonly \Module $module)
    {
    }

    public function log(VerificationEvent $event): void
    {
        $logger = $this->module->context->logger ?? null;

        if ($logger === null) {
            return;
        }

        $context = $event->logContext();

        if ($event->result->isAccepted()) {
            $logger->debug('reCAPTCHA accepted', $context, [self::CHANNEL]);

            return;
        }

        // A configuration fault is louder than spam: it blocks real customers.
        $level = !$event->result->reachedGoogle ? 'error' : 'warning';
        $logger->log($level, 'reCAPTCHA refused: ' . $event->result->reason, $context, [self::CHANNEL]);
    }
}
