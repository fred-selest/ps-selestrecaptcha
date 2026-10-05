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
 * Immutable copy of what the current request carries.
 *
 * The module reads the superglobals once, here, so every decision afterwards is
 * made against a value that cannot change underneath it, and so the whole
 * decision layer can be tested without a web server.
 */
final class RequestSnapshot
{
    public const TOKEN_FIELD = 'grecaptcha-response';

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     */
    public function __construct(
        public readonly string $controller = '',
        public readonly array $post = [],
        public readonly array $get = [],
        public readonly bool $isAjax = false,
        public readonly string $remoteIp = '',
        public readonly string $hostname = '',
        public readonly bool $isPostRequest = true,
    ) {
    }

    /**
     * Mirrors Tools::isSubmit(): a field counts as submitted when it is present
     * in $_POST or $_GET.
     */
    public function isSubmit(string $field): bool
    {
        return array_key_exists($field, $this->post) || array_key_exists($field, $this->get);
    }

    public function hasPostField(string $field): bool
    {
        return array_key_exists($field, $this->post);
    }

    /**
     * A page can hold several widgets (contact + newsletter in the footer), so
     * the browser may send one or several tokens.
     *
     * @return array<int, string>
     */
    public function tokens(): array
    {
        $raw = $this->post[self::TOKEN_FIELD] ?? $this->get[self::TOKEN_FIELD] ?? null;

        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (!is_array($raw)) {
            return [];
        }

        $tokens = [];
        foreach ($raw as $candidate) {
            // A bot can post an array or an object here; only a real token is a
            // string worth spending a siteverify call on.
            if (is_string($candidate)) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $tokens[] = $candidate;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    public function value(string $field): mixed
    {
        return $this->post[$field] ?? $this->get[$field] ?? null;
    }
}