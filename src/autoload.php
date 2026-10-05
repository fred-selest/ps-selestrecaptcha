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

/**
 * Runtime autoloader.
 *
 * A PrestaShop module is copied into modules/ and installed by the merchant,
 * who has no reason to run `composer install`. The module therefore carries no
 * Composer dependency at runtime and loads its own classes. Composer's
 * autoloader, when it happens to be there (dev checkout, CI), wins for the
 * test namespaces.
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'SelestRecaptcha\\Tests\\' => __DIR__ . '/../tests/',
        'SelestRecaptcha\\' => __DIR__ . '/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
});
