<?php

declare(strict_types=1);

namespace SelestRecaptcha\Stats;

use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Http\TransportInterface;

/**
 * One anonymous ping per installation or upgrade, so the author knows how many
 * shops run the module.
 *
 * What is deliberately NOT sent: the shop URL, the shop name, the admin's
 * e-mail, the IP address, the customer data. What is sent says "a PrestaShop
 * shop installed this version of the module", and the author can count those.
 *
 * Two rules make this acceptable to ship:
 *  - it is off until the merchant turns it on, from the back office, where the
 *    payload is written out in front of them;
 *  - it never breaks anything. A statistics endpoint that is slow, down or
 *    misconfigured is swallowed: an installation must not fail because a
 *    counter did not answer.
 */
final class InstallReporter
{
    public const EVENT_INSTALL = 'install';
    public const EVENT_UPGRADE = 'upgrade';

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $timeoutSeconds = 3
    ) {
    }

    /**
     * @return bool true when the endpoint answered
     */
    public function report(string $event, Settings $settings, string $moduleVersion): bool
    {
        if (!$settings->statsEnabled || $settings->statsEndpoint === '') {
            return false;
        }

        try {
            $this->transport->post($settings->statsEndpoint, $this->payload($event, $moduleVersion), $this->timeoutSeconds);

            return true;
        } catch (\Throwable $error) {
            // A counter is not worth an exception: the shop is installing.
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public function payload(string $event, string $moduleVersion): array
    {
        return [
            'event' => $event,
            'module' => 'selestrecaptcha',
            'module_version' => $moduleVersion,
            'prestashop_version' => $this->constant('_PS_VERSION_'),
            'php_version' => PHP_VERSION,
            'locale' => $this->locale(),
            'protected_forms' => (string) $this->enabledTargetCount(),
        ];
    }

    private function constant(string $name): string
    {
        return defined($name) ? (string) constant($name) : 'unknown';
    }

    private function locale(): string
    {
        if (!class_exists('Context', false)) {
            return 'unknown';
        }

        $context = \Context::getContext();

        if (isset($context->language) && isset($context->language->locale)) {
            return (string) $context->language->locale;
        }

        return 'unknown';
    }

    private function enabledTargetCount(): int
    {
        if (!class_exists('Context', false)) {
            return 0;
        }

        $settings = (new \SelestRecaptcha\Config\ConfigurationStore())
            ->load((int) \Shop::getContextShopID());

        return count(array_filter(
            $settings->targets,
            static fn ($target): bool => $target->enabled
        ));
    }
}