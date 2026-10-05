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
 * What the merchant expects from Google for one submission.
 */
final class Policy
{
    /**
     * @param array<int, string> $allowedHostnames
     */
    public function __construct(
        public readonly ?string $expectedAction = null,
        public readonly bool $scoreBased = false,
        public readonly float $minScore = 0.5,
        public readonly bool $strictHostname = false,
        public readonly array $allowedHostnames = [],
        public readonly string $failMode = 'closed',
    ) {
    }

    /**
     * Hostname comparison: Google answers the domain the key is registered for,
     * which may or may not carry the www prefix. Subdomains of a shop domain
     * count, but "evil-shop.com" must never pass for "shop.com".
     */
    public function allowsHostname(?string $hostname): bool
    {
        if (!$this->strictHostname) {
            return true;
        }

        if ($hostname === null || $hostname === '') {
            return false;
        }

        $hostname = strtolower(rtrim($hostname, '.'));

        foreach ($this->allowedHostnames as $allowed) {
            $allowed = strtolower(rtrim($allowed, '.'));

            if ($hostname === $allowed) {
                return true;
            }

            if (str_ends_with($hostname, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}