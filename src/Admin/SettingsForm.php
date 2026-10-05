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

namespace SelestRecaptcha\Admin;

use SelestRecaptcha\Config\RecaptchaVersion;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Guard\Target;

/**
 * Builds the back-office form and reads it back.
 *
 * Read/write of Settings itself is in Settings::fromArray(); this class only
 * decides how the settings look in the back office.
 */
final class SettingsForm
{
    public const INPUT = 'selestrecaptcha';
    public const TEST_BUTTON = 'selestrecaptcha_test';

    public function __construct(private readonly \Module $module)
    {
    }

    /**
     * The structure HelperForm::generateForm() expects: one entry per
     * fieldset, each with the inputs and the values to show in them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function formArray(Settings $settings): array
    {
        $fieldsets = [];

        $fieldsets['general'] = [
            'form' => [
                'legend' => [
                    'title' => $this->t('Activation', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'icon' => 'icon-shield',
                ],
                'input' => [
                [
                    'type' => 'switch',
                    'label' => $this->t('Protect the shop forms', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'name' => self::INPUT . '[enabled]',
                    'is_bool' => true,
                    'values' => [
                        ['id' => 'enabled_on', 'value' => 1, 'label' => $this->t('Yes', \Selestrecaptcha::TRANSLATION_DOMAIN)],
                        ['id' => 'enabled_off', 'value' => 0, 'label' => $this->t('No', \Selestrecaptcha::TRANSLATION_DOMAIN)],
                    ],
                ],
                [
                    'type' => 'radio',
                    'name' => self::INPUT . '[version]',
                    'label' => $this->t('reCAPTCHA version', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'is_bool' => false,
                    'values' => [
                        ['id' => 'v2_checkbox', 'value' => RecaptchaVersion::V2_CHECKBOX, 'label' => $this->t('v2 — "I am not a robot" checkbox', \Selestrecaptcha::TRANSLATION_DOMAIN)],
                        ['id' => 'v2_invisible', 'value' => RecaptchaVersion::V2_INVISIBLE, 'label' => $this->t('v2 — invisible', \Selestrecaptcha::TRANSLATION_DOMAIN)],
                        ['id' => 'v3', 'value' => RecaptchaVersion::V3, 'label' => $this->t('v3 — invisible, scored', \Selestrecaptcha::TRANSLATION_DOMAIN)],
                    ],
                ],
                [
                    'type' => 'text',
                    'name' => self::INPUT . '[site_key]',
                    'label' => $this->t('Site key', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'size' => 45,
                ],
                [
                    'type' => 'password',
                    'name' => self::INPUT . '[secret_key]',
                    'label' => $this->t('Secret key', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'size' => 45,
                ],
                [
                    'type' => 'text',
                    'name' => self::INPUT . '[min_score]',
                    'label' => $this->t('Minimum score (v3)', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'hint' => $this->t('Between 0 and 1. Google suggests starting at 0.5.', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'size' => 6,
                ],
                [
                    'type' => 'radio',
                    'name' => self::INPUT . '[fail_mode]',
                    'label' => $this->t('When Google cannot be reached', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'values' => [
                        ['id' => 'fail_closed', 'value' => 'closed', 'label' => $this->t('Refuse the submission', \Selestrecaptcha::TRANSLATION_DOMAIN)],
                        ['id' => 'fail_open', 'value' => 'open', 'label' => $this->t('Accept the submission', \Selestrecaptcha::TRANSLATION_DOMAIN)],
                    ],
                ],
                [
                    'type' => 'switch',
                    'name' => self::INPUT . '[strict_hostname]',
                    'is_bool' => true,
                    'label' => $this->t('Check the hostname Google reports', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'hint' => $this->t('Refuses tokens solved on a domain other than this shop.', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'values' => [
                        ['id' => 'hostname_on', 'value' => 1],
                        ['id' => 'hostname_off', 'value' => 0],
                    ],
                ],
                [
                    'type' => 'switch',
                    'name' => self::INPUT . '[log_events]',
                    'is_bool' => true,
                    'label' => $this->t('Log every decision', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'hint' => $this->t('Channel "selestrecaptcha" in the PrestaShop logs. The visitor IP is logged; tokens and secrets never are.', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'values' => [
                        ['id' => 'log_on', 'value' => 1],
                        ['id' => 'log_off', 'value' => 0],
                    ],
                ],
            ],
            ],
        ];

        $targetFields = [];
        foreach (Target::all() as $target) {
            $targetSettings = $settings->target($target);

            $targetFields[] = [
                'type' => 'switch',
                'name' => self::INPUT . '[targets][' . $target->value . '][enabled]',
                'is_bool' => true,
                'label' => $this->t($this->label($target), \Selestrecaptcha::TRANSLATION_DOMAIN),
                'values' => [
                    ['id' => $target->value . '_on', 'value' => 1],
                    ['id' => $target->value . '_off', 'value' => 0],
                ],
            ];

            $targetFields[] = [
                'type' => 'text',
                'name' => self::INPUT . '[targets][' . $target->value . '][action]',
                'label' => $this->t('Action name (v3)', \Selestrecaptcha::TRANSLATION_DOMAIN),
                'hint' => $target->defaultAction(),
                'size' => 20,
            ];

            $targetFields[] = [
                'type' => 'text',
                'name' => self::INPUT . '[targets][' . $target->value . '][min_score]',
                'label' => $this->t('Minimum score (blank = global)', \Selestrecaptcha::TRANSLATION_DOMAIN),
                'size' => 6,
            ];
        }

        $fieldsets['targets'] = [
            'form' => [
                'legend' => [
                    'title' => $this->t('Protected forms', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'icon' => 'icon-list',
                ],
                'input' => $targetFields,
            ],
        ];

        $fieldsets['messages'] = [
            'form' => [
                'legend' => [
                    'title' => $this->t('Messages shown to the visitor', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'icon' => 'icon-comments',
                ],
                'input' => [
                [
                    'type' => 'text',
                    'name' => self::INPUT . '[messages][block]',
                    'label' => $this->t('Captcha not completed', \Selestrecaptcha::TRANSLATION_DOMAIN),
                ],
                [
                    'type' => 'text',
                    'name' => self::INPUT . '[messages][score]',
                    'label' => $this->t('Score too low', \Selestrecaptcha::TRANSLATION_DOMAIN),
                ],
                [
                    'type' => 'text',
                    'name' => self::INPUT . '[messages][unreachable]',
                    'label' => $this->t('Google unreachable', \Selestrecaptcha::TRANSLATION_DOMAIN),
                ],
            ],
            ],
        ];

        $fieldsets['advanced'] = [
            'form' => [
                'legend' => [
                    'title' => $this->t('Advanced', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                [
                    'type' => 'text',
                    'name' => self::INPUT . '[endpoint]',
                    'label' => $this->t('Verification endpoint', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'hint' => $this->t('HTTPS only. Point it at a proxy when Google is unreachable from your country.', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'size' => 60,
                ],
                [
                    'type' => 'text',
                    'name' => self::INPUT . '[timeout]',
                    'label' => $this->t('Timeout (seconds)', \Selestrecaptcha::TRANSLATION_DOMAIN),
                    'size' => 4,
                ],
                ],
            ],
        ];

        // HelperForm only draws the buttons declared in the fieldset itself:
        // every panel gets a Save button, and the key self test sits next to the
        // keys it checks.
        foreach ($fieldsets as $key => $fieldset) {
            $fieldsets[$key]['form']['submit'] = [
                'name' => \Selestrecaptcha::ADMIN_SAVE_BUTTON,
                'title' => $this->t('Save', \Selestrecaptcha::TRANSLATION_DOMAIN),
                'icon' => 'process-icon-save',
            ];
        }

        $fieldsets['general']['form']['buttons'] = [[
            'name' => self::TEST_BUTTON,
            'type' => 'submit',
            'title' => $this->t('Test the keys', \Selestrecaptcha::TRANSLATION_DOMAIN),
            'class' => 'btn btn-default pull-left',
            'icon' => 'icon-refresh',
        ]];

        return $fieldsets;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(Settings $settings): array
    {
        $raw = $settings->toArray();
        $values = [
            self::INPUT . '[enabled]' => $raw['enabled'] ? 1 : 0,
            self::INPUT . '[version]' => $raw['version'],
            self::INPUT . '[site_key]' => $raw['site_key'],
            self::INPUT . '[secret_key]' => $raw['secret_key'],
            self::INPUT . '[min_score]' => $raw['min_score'],
            self::INPUT . '[fail_mode]' => $raw['fail_mode'],
            self::INPUT . '[strict_hostname]' => $raw['strict_hostname'] ? 1 : 0,
            self::INPUT . '[log_events]' => $raw['log_events'] ? 1 : 0,
            self::INPUT . '[endpoint]' => $raw['endpoint'],
            self::INPUT . '[timeout]' => $raw['timeout'],
            self::INPUT . '[messages][block]' => $raw['messages']['block'] ?? '',
            self::INPUT . '[messages][score]' => $raw['messages']['score'] ?? '',
            self::INPUT . '[messages][unreachable]' => $raw['messages']['unreachable'] ?? '',
        ];

        foreach (Target::all() as $target) {
            $targetRaw = $raw['targets'][$target->value] ?? [];
            $values[self::INPUT . '[targets][' . $target->value . '][enabled]'] = !empty($targetRaw['enabled']) ? 1 : 0;
            $values[self::INPUT . '[targets][' . $target->value . '][action]'] = $targetRaw['action'] ?? $target->defaultAction();
            $values[self::INPUT . '[targets][' . $target->value . '][min_score]'] = $targetRaw['min_score'];
        }

        return $values;
    }

    /**
     * Module::trans() is protected, so another class cannot call it on the
     * module instance; the module's translator is the public door.
     */
    private function t(string $string, string $domain): string
    {
        return $this->module->getTranslator()->trans($string, [], $domain);
    }

    private function label(Target $target): string
    {
        return match ($target) {
            Target::Contact => $this->t('Contact form', \Selestrecaptcha::TRANSLATION_DOMAIN),
            Target::Registration => $this->t('Account creation', \Selestrecaptcha::TRANSLATION_DOMAIN),
            Target::Newsletter => $this->t('Newsletter registration', \Selestrecaptcha::TRANSLATION_DOMAIN),
            Target::Comment => $this->t('Product reviews', \Selestrecaptcha::TRANSLATION_DOMAIN),
        };
    }
}