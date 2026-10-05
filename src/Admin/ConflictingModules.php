<?php

declare(strict_types=1);

namespace SelestRecaptcha\Admin;

/**
 * Finds the other reCAPTCHA modules a merchant may still have installed.
 *
 * Two modules doing the same job on the same forms is worse than either one:
 * the visitor gets two captchas, one of them is not checked, and nobody knows
 * which one is actually protecting the shop. This is how the author finds out
 * that an earlier install is still out there — the notice in the back office
 * and the installation ping are the two halves of the same question.
 */
final class ConflictingModules
{
    /**
     * Technical names of modules that put a reCAPTCHA on PrestaShop forms.
     *
     * @var array<int, string>
     */
    public const KNOWN = [
        'psrecaptcha',
        'recaptcha',
        'grecaptcha',
        'captcha',
        'blockrecaptcha',
    ];

    private string $self;

    /**
     * @var array<string, bool> technical name => active
     */
    private array $installed;

    /**
     * @param array<string, bool>|null $installed null asks PrestaShop itself
     */
    public function __construct(string $self, ?array $installed = null)
    {
        $this->self = $self;
        $this->installed = $installed ?? self::readInstalledModules();
    }

    /**
     * @return array<int, string> the other modules that are installed and on
     */
    public function active(): array
    {
        $found = [];

        foreach (self::KNOWN as $name) {
            if ($name === $this->self || !($this->installed[$name] ?? false)) {
                continue;
            }

            $found[] = $name;
        }

        return $found;
    }

    /**
     * The query, exposed so a test can check it: pSQL() escapes but does not
     * quote, and an unquoted IN list is a SQL error rather than an empty result.
     */
    public static function buildQuery(): string
    {
        $names = array_map(
            static fn (string $name): string => "'" . pSQL($name) . "'",
            self::KNOWN
        );

        return 'SELECT name, active FROM `' . _DB_PREFIX_ . 'module` '
            . 'WHERE name IN (' . implode(', ', $names) . ')';
    }

    /**
     * @return array<string, bool>
     */
    private static function readInstalledModules(): array
    {
        // Only the database and the prefix are needed here: asking whether the
        // Module class is loaded is a trap, it is not loaded yet in a CLI run.
        if (!class_exists('Db') || !defined('_DB_PREFIX_')) {
            return [];
        }

        try {
            $rows = \Db::getInstance()->executeS(self::buildQuery());
        } catch (\Throwable $error) {
            return [];
        }

        $installed = [];

        foreach ((array) $rows as $row) {
            $installed[(string) $row['name']] = (bool) $row['active'];
        }

        return $installed;
    }
}