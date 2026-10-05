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

namespace SelestRecaptcha\Guard;

use SelestRecaptcha\Config\Settings;

/**
 * Answers one question: is this request an attempt to submit one of the
 * protected forms?
 *
 * Detection is deliberately independent of the theme and of the controller
 * class name: the form handler is identified by the field the core module
 * actually tests with Tools::isSubmit(). A bot that posts straight to
 * controllers/front/contact.php without the browser is caught by the same rule
 * as a visitor clicking the button.
 */
final class SubmissionInspector
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function detect(RequestSnapshot $request): ?Target
    {
        // Tokens are single-use and expire in two minutes: a GET that merely
        // replays a query string is never a legitimate submission.
        if (!$request->isPostRequest) {
            return null;
        }

        foreach (Target::all() as $target) {
            if (!$this->settings->isTargetEnabled($target)) {
                continue;
            }

            foreach ($target->submitFields() as $field) {
                if ($request->isSubmit($field)) {
                    return $target;
                }
            }
        }

        return null;
    }

    /**
     * Every enabled target whose trigger is present. Used for logging only:
     * a request carrying two triggers is a bot oddity worth seeing, but it is
     * not blocked on that basis alone — one token cannot be verified twice.
     *
     * @return array<int, Target>
     */
    public function detectAll(RequestSnapshot $request): array
    {
        if (!$request->isPostRequest) {
            return [];
        }

        $matches = [];
        foreach (Target::all() as $target) {
            if (!$this->settings->isTargetEnabled($target)) {
                continue;
            }

            foreach ($target->submitFields() as $field) {
                if ($request->isSubmit($field)) {
                    $matches[] = $target;
                    break;
                }
            }
        }

        return $matches;
    }
}
