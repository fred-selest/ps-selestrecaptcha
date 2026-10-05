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

namespace SelestRecaptcha\Config;

/**
 * reCAPTCHA flavours supported by the module.
 */
final class RecaptchaVersion
{
    public const V2_CHECKBOX = 'v2_checkbox';
    public const V2_INVISIBLE = 'v2_invisible';
    public const V3 = 'v3';

    /** @var array<int, string> */
    public const ALL = [self::V2_CHECKBOX, self::V2_INVISIBLE, self::V3];

    public static function isValid(string $version): bool
    {
        return in_array($version, self::ALL, true);
    }

    /**
     * v3 is score-based; the two v2 flavours only answer pass/fail.
     */
    public static function isScoreBased(string $version): bool
    {
        return self::V3 === $version;
    }

    /**
     * v3 and v2-invisible need a token generated at submit time, the v2
     * checkbox answers before the visitor presses the button.
     */
    public static function needsSubmitToken(string $version): bool
    {
        return self::V3 === $version || self::V2_INVISIBLE === $version;
    }
}