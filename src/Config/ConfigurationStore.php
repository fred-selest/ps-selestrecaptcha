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

namespace SelestRecaptcha\Config;

/**
 * The only class in the module that talks to \Configuration.
 *
 * Keeping it alone means the rest of src/ is plain PHP: no PrestaShop, no
 * database, no bootstrap — which is what makes the rules testable.
 */
final class ConfigurationStore
{
    public const KEY = 'SELESTRECAPTCHA_SETTINGS';

    public function load(?int $shopId = null): Settings
    {
        // Configuration::get() takes the shop GROUP before the shop:
        // get($key, $idLang, $idShopGroup, $idShop). Passing the shop id in the
        // group slot reads the wrong scope and silently falls back to global.
        $raw = \Configuration::get(self::KEY, null, null, $this->scope($shopId));
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return Settings::defaults();
        }

        return Settings::fromArray($decoded, $this->shopUrls($shopId));
    }

    public function exists(?int $shopId = null): bool
    {
        $raw = \Configuration::get(self::KEY, null, null, $this->scope($shopId));

        return is_string($raw) && $raw !== '';
    }

    public function save(Settings $settings, ?int $shopId = null): bool
    {
        $payload = json_encode($settings->toArray(), JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return false;
        }

        $shopId = $this->scope($shopId);

        // The row is written here rather than through Configuration::updateValue()
        // for three reasons, all of them observed on a real shop:
        //
        //  - updateValue() answers from the cache the shop built when the
        //    request booted (hasKey() never looks at the database), so a write
        //    in the same request as a delete updates nothing and reports success;
        //  - which scope it writes depends on Shop::isFeatureActive(), so the
        //    same call lands on the global row on one shop and on the shop row
        //    on another;
        //  - the shop keeps answering from that stale cache afterwards, which
        //    is how a save that worked looks like it did nothing.
        $this->writeScope($shopId, $payload);

        // MySQL compares NULLs as distinct and ps_configuration has no unique
        // index over (name, id_shop, id_shop_group), so a second save can leave
        // two rows behind and the shop then reads whichever one comes back first.
        $this->dedupeScope($shopId);

        if (method_exists(\Configuration::class, 'resetStaticCache')) {
            \Configuration::resetStaticCache();
        }

        // What is stored is compared as data, not as text: JSON has more than
        // one valid encoding, and a row written by an older version of the
        // module decodes to the same settings.
        return json_decode($this->storedPayload($shopId) ?? 'null', true)
            === json_decode($payload, true);
    }

    /**
     * The scope PrestaShop will actually read.
     *
     * On a shop without the multistore feature every setting lives in the
     * global row: Configuration::get() forces $idShop to 0 when the per-shop
     * cache does not exist, so a row written with an id_shop on such a shop is
     * never read back. The module follows the same rule, so the back office
     * and the front office always look at the same row.
     *
     * @return int|null null means the global scope
     */
    private function scope(?int $shopId): ?int
    {
        if ($shopId === null) {
            return null;
        }

        if (!class_exists('Shop', false) || !\Shop::isFeatureActive()) {
            return null;
        }

        return $shopId > 0 ? $shopId : null;
    }

    private function scopeClause(?int $shopId): string
    {
        return $shopId === null
            ? 'AND id_shop IS NULL AND id_shop_group IS NULL'
            : 'AND id_shop = ' . (int) $shopId;
    }

    /**
     * The id of the oldest row of this scope, or null when the scope is empty.
     */
    private function oldestScopeId(?int $shopId): ?int
    {
        // Db::getValue() appends its own LIMIT 1 and is the one read PrestaShop
        // leaves public: Db::execute() answers with a boolean, and the row
        // readers behind getAll() are protected.
        $id = \Db::getInstance()->getValue(
            'SELECT id_configuration
               FROM `' . _DB_PREFIX_ . 'configuration`
              WHERE name = "' . pSQL(self::KEY) . '" ' . $this->scopeClause($shopId) . '
           ORDER BY id_configuration',
            false
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    private function writeScope(?int $shopId, string $payload): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->oldestScopeId($shopId);

        if ($existing !== null) {
            // The default (true) escapes the value, which the JSON payload needs:
            // it is full of double quotes.
            \Db::getInstance()->update('configuration', [
                'value' => $payload,
                'date_upd' => $now,
            ], 'id_configuration = ' . $existing);

            return;
        }

        \Db::getInstance()->insert('configuration', [
            'name' => self::KEY,
            'value' => $payload,
            'id_shop_group' => null,
            'id_shop' => $shopId,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
    }

    private function dedupeScope(?int $shopId): void
    {
        $keep = $this->oldestScopeId($shopId);

        if ($keep === null) {
            return;
        }

        \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration`
              WHERE name = "' . pSQL(self::KEY) . '" ' . $this->scopeClause($shopId) . '
                AND id_configuration <> ' . $keep
        );
    }

    /**
     * What the database really holds for this scope, read directly and without
     * the query cache: going through Configuration::get() would only prove the
     * cache agrees with itself, and the query cache would do the same for a
     * read taken a moment earlier in the same request.
     */
    private function storedPayload(?int $shopId): ?string
    {
        $value = \Db::getInstance()->getValue(
            'SELECT value
               FROM `' . _DB_PREFIX_ . 'configuration`
              WHERE name = "' . pSQL(self::KEY) . '" ' . $this->scopeClause($shopId) . '
           ORDER BY id_configuration',
            false
        );

        return $value === false || $value === null ? null : (string) $value;
    }

    public function delete(?int $shopId = null): void
    {
        // Uninstalling the module removes the setting from every shop scope.
        \Configuration::deleteByName(self::KEY);
    }

    /**
     * @return array<int, string>
     */
    private function shopUrls(?int $shopId): array
    {
        $urls = [];

        if (function_exists('Shop')) {
            $shop = new \Shop($shopId ?? (int) \Configuration::getContextShopId());

            if (\Validate::isLoadedObject($shop)) {
                $urls[] = (string) $shop->getBaseURL(true, true);
                $urls[] = (string) $shop->physical_url;
            }
        }

        if (defined('_PS_SHOP_DOMAIN_') && _PS_SHOP_DOMAIN_ !== null) {
            $urls[] = 'https://' . _PS_SHOP_DOMAIN_ . '/';
        }

        return array_values(array_filter($urls, static fn (string $url): bool => $url !== ''));
    }
}
