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
 * Applies the refusal to the current request.
 *
 * The trigger field is erased, which makes the owning core module skip the
 * submission, and the reason is handed to the page so the visitor is told what
 * happened instead of watching their form do nothing.
 */
final class SubmissionBlocker
{
    public function __construct(
        private readonly RequestWriterInterface $writer,
        private readonly FlashHolder $flash,
    ) {
    }

    /**
     * @return array<int, string> the fields erased from the request
     */
    public function block(Target $target, string $message): array
    {
        $erased = $target->submitFields();

        foreach ($erased as $field) {
            $this->writer->forgetField($field);
        }

        $this->flash->set($target->isAjax() ? 'ajax' : 'error', $message);

        return $erased;
    }
}