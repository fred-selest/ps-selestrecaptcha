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

namespace SelestRecaptcha\Widget;

use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Guard\Target;

/**
 * Everything the front office script is allowed to know.
 *
 * The site key is public by design. The secret key is not part of this object
 * and no code path can put it here.
 */
final class WidgetConfig
{
    /**
     * @param array<string, array{form: string, action: string, ajax: bool}> $targets
     * @param array<string, string> $messages
     */
    public function __construct(
        public readonly string $version,
        public readonly string $siteKey,
        public readonly string $scriptUrl,
        public readonly string $language,
        public readonly array $targets,
        public readonly array $messages,
        public readonly ?string $flashMessage = null,
        public readonly bool $testKeys = false,
    ) {
    }

    public static function fromSettings(Settings $settings, string $scriptUrl, string $language): self
    {
        $targets = [];

        foreach (Target::all() as $target) {
            if (!$settings->isTargetEnabled($target)) {
                continue;
            }

            $targets[$target->value] = [
                'form' => $target->formMarker(),
                'action' => $settings->target($target)->action,
                'ajax' => $target->isAjax(),
            ];
        }

        return new self(
            $settings->version,
            $settings->siteKey,
            $scriptUrl,
            $language,
            $targets,
            $settings->messages,
            null,
            $settings->usesTestKeys(),
        );
    }

    public function withFlashMessage(?string $message): self
    {
        return new self(
            $this->version,
            $this->siteKey,
            $this->scriptUrl,
            $this->language,
            $this->targets,
            $this->messages,
            $message,
            $this->testKeys,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'version' => $this->version,
            'siteKey' => $this->siteKey,
            'script' => $this->scriptUrl,
            'language' => $this->language,
            'targets' => $this->targets,
            'messages' => $this->messages,
            'tokenField' => 'grecaptcha-response',
        ];

        if ($this->flashMessage !== null && $this->flashMessage !== '') {
            $data['flash'] = $this->flashMessage;
        }

        return $data;
    }

    /**
     * JSON safe to inline in a <script> block: no closing tag, no quote or
     * ampersand can escape the string literals.
     */
    public function toJson(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return $json === false ? '{}' : $json;
    }
}