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

namespace SelestRecaptcha\Http;

/**
 * The only runtime dependency of the module: ext-curl, which every hosting
 * that runs PrestaShop already has.
 */
final class CurlTransport implements TransportInterface
{
    public function post(string $url, array $fields, int $timeoutSeconds): string
    {
        if (!function_exists('curl_init')) {
            throw new TransportException('ext-curl is not available on this server.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Unable to initialise a cURL handle.');
        }

        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            // Google answers in under 200ms; a slow answer is already useless.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'PrestaShop-reCAPTCHA',
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ];

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($response === false || $errorNumber !== 0) {
            // The URL may contain the merchant's proxy host, never a secret: the
            // secret travels in the body.
            throw new TransportException(sprintf(
                'siteverify unreachable (cURL %d): %s',
                $errorNumber,
                $errorMessage
            ));
        }

        if ($status < 200 || $status >= 300) {
            throw new TransportException(sprintf('siteverify answered HTTP %d', $status));
        }

        return is_string($response) ? $response : '';
    }
}
