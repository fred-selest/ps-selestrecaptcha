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
 * Immutable snapshot of the module configuration.
 *
 * Every value crossing the module boundary goes through fromArray(), which is
 * the single place where untrusted input (a Configuration row, a POST from the
 * back office) is turned into typed, range-checked data.
 */
final class Settings
{
    /**
     * Google's universal test keys. They accept any token and always answer
     * success — handy on staging, dangerous in production.
     */
    public const TEST_SITE_KEY = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';
    public const TEST_SECRET_KEY = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';

    public const ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';
    public const SCRIPT = 'https://www.google.com/recaptcha/api.js';

    /**
     * @param array<string, TargetSettings> $targets
     * @param array<string, string> $messages
     * @param array<int, string> $shopUrls Public URLs of this shop, used to build
     *                                    the hostname allow-list.
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $version = RecaptchaVersion::V2_CHECKBOX,
        public readonly string $siteKey = '',
        public readonly string $secretKey = '',
        public readonly float $minScore = 0.5,
        /** open = a Google outage lets the submission through; closed = blocks it. */
        public readonly string $failMode = 'closed',
        public readonly bool $strictHostname = false,
        public readonly bool $logEvents = true,
        public readonly string $endpoint = self::ENDPOINT,
        public readonly int $timeout = 5,
        /** @var array<string, TargetSettings> */
        public readonly array $targets = [],
        /** @var array<string, string> */
        public readonly array $messages = [],
        /** @var array<int, string> */
        public readonly array $shopUrls = [],
    ) {
    }

    public static function defaults(): self
    {
        $targets = [];
        foreach (Target::all() as $target) {
            $targets[$target->value] = new TargetSettings(true, $target->defaultAction());
        }

        return new self(targets: $targets, messages: self::defaultMessages());
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, string> $shopUrls
     */
    public static function fromArray(array $raw, array $shopUrls = []): self
    {
        $defaults = self::defaults();
        $rawTargets = is_array($raw['targets'] ?? null) ? $raw['targets'] : [];

        $targets = [];
        foreach (Target::all() as $target) {
            $targets[$target->value] = TargetSettings::coerce(
                $rawTargets[$target->value] ?? null,
                $target->defaultAction()
            );
        }

        $version = (string) ($raw['version'] ?? $defaults->version);

        $messages = $defaults->messages;
        if (is_array($raw['messages'] ?? null)) {
            /** @var array<string, string> $rawMessages */
            $rawMessages = $raw['messages'];
            foreach ($rawMessages as $key => $message) {
                if (is_string($message) && $message !== '') {
                    $messages[$key] = $message;
                }
            }
        }

        return new self(
            enabled: self::toBool($raw['enabled'] ?? false),
            version: RecaptchaVersion::isValid($version) ? $version : $defaults->version,
            siteKey: self::toKey($raw['site_key'] ?? ''),
            secretKey: self::toKey($raw['secret_key'] ?? ''),
            minScore: self::toScore($raw['min_score'] ?? $defaults->minScore),
            failMode: in_array((string) ($raw['fail_mode'] ?? ''), ['open', 'closed'], true)
                ? (string) $raw['fail_mode']
                : $defaults->failMode,
            strictHostname: self::toBool($raw['strict_hostname'] ?? false),
            logEvents: self::toBool($raw['log_events'] ?? true),
            // Allows a proxy endpoint: Google is not always reachable from the
            // shop's country, and some merchants must not call it directly.
            endpoint: self::toEndpoint($raw['endpoint'] ?? self::ENDPOINT),
            timeout: self::toTimeout($raw['timeout'] ?? 5),
            targets: $targets,
            messages: $messages,
            shopUrls: $shopUrls,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $targets = [];
        foreach ($this->targets as $key => $target) {
            $targets[$key] = $target->toArray();
        }

        return [
            'enabled' => $this->enabled,
            'version' => $this->version,
            'site_key' => $this->siteKey,
            'secret_key' => $this->secretKey,
            'min_score' => $this->minScore,
            'fail_mode' => $this->failMode,
            'strict_hostname' => $this->strictHostname,
            'log_events' => $this->logEvents,
            'endpoint' => $this->endpoint,
            'timeout' => $this->timeout,
            'targets' => $targets,
            'messages' => $this->messages,
        ];
    }

    public function target(Target $target): TargetSettings
    {
        return $this->targets[$target->value] ?? new TargetSettings(true, $target->defaultAction());
    }

    public function isTargetEnabled(Target $target): bool
    {
        return $this->enabled && $this->target($target)->enabled;
    }

    /**
     * A protection that cannot work: no keys, or a v3 key set on a checkbox.
     */
    public function isUsable(): bool
    {
        return $this->enabled
            && $this->siteKey !== ''
            && $this->secretKey !== ''
            && RecaptchaVersion::isValid($this->version);
    }

    /**
     * Configuration screen and front office both warn when the merchant ships
     * Google's test keys: the captcha always passes, so spam gets through.
     */
    public function usesTestKeys(): bool
    {
        return self::TEST_SITE_KEY === $this->siteKey || self::TEST_SECRET_KEY === $this->secretKey;
    }

    /**
     * Effective threshold for a target: its own override, else the global one.
     */
    public function effectiveMinScore(Target $target): float
    {
        $own = $this->target($target)->minScore;

        return $own ?? $this->minScore;
    }

    public function effectiveStrictHostname(Target $target): bool
    {
        $own = $this->target($target)->strictHostname;

        return $own ?? $this->strictHostname;
    }

    /**
     * Hostnames legitimately used by the shop. In local mode the shop runs on
     * localhost or 127.0.0.1, which Google reports back with the port.
     *
     * @return array<int, string>
     */
    public function allowedHostnames(): array
    {
        $hostnames = [];
        foreach ($this->urls() as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hostnames[] = strtolower($host);
            }
        }

        $hostnames[] = 'localhost';
        $hostnames[] = '127.0.0.1';

        return array_values(array_unique($hostnames));
    }

    /**
     * @return array<int, string>
     */
    public function urls(): array
    {
        return $this->shopUrls;
    }

    /**
     * @return array<string, string>
     */
    public static function defaultMessages(): array
    {
        return [
            'block' => 'Please complete the captcha to continue.',
            'score' => 'Your submission was flagged as automated. Please try again.',
            'unreachable' => 'The captcha service is temporarily unavailable. Please try again.',
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private static function toKey(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // Keys are 40-char base64-ish strings; anything else is a typo and would
        // only produce a cryptic Google error later on.
        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_\-]{10,60}$/', $value) ? $value : '';
    }

    private static function toScore(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.5;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private static function toTimeout(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 5;
        }

        return (int) max(1, min(15, (int) $value));
    }

    private static function toEndpoint(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return self::ENDPOINT;
        }

        $host = parse_url($value, PHP_URL_HOST);
        $scheme = parse_url($value, PHP_URL_SCHEME);
        $user = parse_url($value, PHP_URL_USER);

        if (!is_string($host) || $host === '' || !is_string($scheme)) {
            return self::ENDPOINT;
        }

        // "https://trusted.example@evil.example/" reads as the real host at a
        // glance but talks to evil.example: refuse anything with credentials.
        if (is_string($user) && $user !== '') {
            return self::ENDPOINT;
        }

        // Plain HTTP is accepted for the loopback interface only, so the module
        // can be exercised end to end against a local fake of the Google API
        // without opening a general http:// hole in a shipped module.
        $isLoopback = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);

        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
            return self::ENDPOINT;
        }

        return $value;
    }
}