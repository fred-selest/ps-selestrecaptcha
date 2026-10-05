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

/**
 * The submissions this module knows how to protect.
 *
 * A target couples three things that must never drift apart: the POST field that
 * identifies the submission server-side, the DOM marker used client-side to find
 * the form, and the v3 action name sent to Google.
 *
 * The POST field is the *authoritative* detector: it is what the core module of
 * the matching PrestaShop version actually reads (Tools::isSubmit()).
 */
enum Target: string
{
    case Contact = 'contact';
    case Registration = 'registration';
    case Newsletter = 'newsletter';
    case Comment = 'comment';

    /**
     * Submit button name read by the core module that owns this form.
     *
     * @var array<int, string>
     */
    public function submitFields(): array
    {
        return match ($this) {
            self::Contact => ['submitMessage', 'submitMessageGuest'],
            self::Registration => ['submitCreate'],
            self::Newsletter => ['submitNewsletter'],
            self::Comment => ['comment_content', 'comment_title'],
        };
    }

    /**
     * CSS selector matching a field that only exists inside the target form.
     * Used to locate the form in the DOM without depending on a theme's
     * element id (ids differ between Classic and Hummingbird).
     */
    public function formMarker(): string
    {
        return match ($this) {
            self::Contact => '[name="message"]',
            self::Registration => '[name="submitCreate"]',
            self::Newsletter => '[name="submitNewsletter"]',
            self::Comment => '[name="comment_content"]',
        };
    }

    /**
     * reCAPTCHA v3 action name. Google restricts actions to [A-Za-z0-9/_].
     */
    public function defaultAction(): string
    {
        return match ($this) {
            self::Contact => 'contact',
            self::Registration => 'account/register',
            self::Newsletter => 'newsletter',
            self::Comment => 'product/review',
        };
    }

    /**
     * The comment endpoint answers JSON over XHR, the other three re-render the page.
     */
    public function isAjax(): bool
    {
        return self::Comment === $this;
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
