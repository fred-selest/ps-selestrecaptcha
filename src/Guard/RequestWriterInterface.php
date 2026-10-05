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
 * The write side of the request: the only place the module mutates the request
 * in flight, so tests can observe it instead of poking superglobals.
 */
interface RequestWriterInterface
{
    /**
     * Removes a submitted field from the request.
     *
     * The core form handlers (contactform, ps_emailsubscription, productcomments)
     * decide whether to act on Tools::isSubmit('submitXxx'). Erasing the field
     * is what makes the handler skip the submission, and the rest of the POST —
     * the visitor's message, their email — stays available to the re-rendered
     * form.
     */
    public function forgetField(string $field): void;
}