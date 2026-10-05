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

use SelestRecaptcha\Guard\Target;

/**
 * Per-target settings: the merchant can protect one form without the others.
 */
final class TargetSettings
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly string $action = '',
        /** Null means "inherit the global threshold". */
        public readonly ?float $minScore = null,
        public readonly ?bool $strictHostname = null,
    ) {
    }

    /**
     * Accepts either a raw array (from JSON, from the back office) or an
     * already-built instance.
     *
     * Falling back to defaults for anything else would silently re-enable a
     * form the merchant had switched off, which is worse than any exception.
     */
    public static function coerce(mixed $raw, string $fallbackAction): self
    {
        if ($raw instanceof self) {
            return $raw;
        }

        return self::fromArray(is_array($raw) ? $raw : [], $fallbackAction);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw, string $fallbackAction): self
    {
        return new self(
            self::toBool($raw['enabled'] ?? true),
            self::toAction($raw['action'] ?? $fallbackAction, $fallbackAction),
            self::toNullableFloat($raw['min_score'] ?? null),
            isset($raw['strict_hostname']) ? self::toBool($raw['strict_hostname']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'action' => $this->action,
            'min_score' => $this->minScore,
            'strict_hostname' => $this->strictHostname,
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    /**
     * Google only accepts [A-Za-z0-9/_] in an action name. An invalid one is
     * silently dropped server-side by Google, so the token would always come
     * back with a mismatched action and every legitimate visitor would be
     * rejected: we repair it here instead.
     */
    private static function toAction(mixed $value, string $fallback): string
    {
        if (!is_string($value)) {
            return $fallback;
        }

        $clean = preg_replace('/[^A-Za-z0-9_\/]/', '', $value) ?? '';
        $clean = trim($clean, '/');

        if ($clean === '') {
            return $fallback;
        }

        // Google rejects actions longer than 100 characters.
        if (strlen($clean) > 100) {
            $clean = substr($clean, 0, 100);
        }

        return $clean;
    }
}