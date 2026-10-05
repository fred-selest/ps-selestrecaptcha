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
 * Seam between the verifier and the network.
 *
 * Without it the only way to exercise the verification rules is to reach the
 * real siteverify endpoint, which means the rules are never tested.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $fields form-encoded body
     *
     * @throws TransportException on any network, TLS, timeout or transport-level failure
     */
    public function post(string $url, array $fields, int $timeoutSeconds): string;
}
