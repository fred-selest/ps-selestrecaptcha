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
 * Request-scoped holder for the reason a submission was turned down.
 *
 * Carried in the page instead of a cookie: the message is rendered by the same
 * header that renders the captcha, so there is no cookie path, no expiry race
 * and nothing for a bot to replay.
 */
final class FlashHolder
{
    private ?string $message = null;
    private ?string $type = null;

    public function set(string $type, string $message): void
    {
        $this->type = $type;
        $this->message = $message;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function hasMessage(): bool
    {
        return $this->message !== null && $this->message !== '';
    }

    public function clear(): void
    {
        $this->message = null;
        $this->type = null;
    }
}