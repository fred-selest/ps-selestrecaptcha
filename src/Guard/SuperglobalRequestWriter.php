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
 * Reads and writes the live request.
 */
final class SuperglobalRequestWriter implements RequestWriterInterface
{
    public function forgetField(string $field): void
    {
        // Touching the superglobals is the point of this class, and it happens
        // in no other place. Tools::isSubmit() reads these exact arrays, which
        // is why erasing the field is enough to make the core handler skip.
        unset($_POST[$field], $_GET[$field]);
    }
}