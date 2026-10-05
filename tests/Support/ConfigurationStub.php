<?php

/**
 * A stand-in for PrestaShop's Configuration class, used by the unit tests that
 * need to prove how the module calls it without a shop or a database.
 *
 * It keeps the argument order of the real class on purpose:
 *   Configuration::get($key, $idLang, $idShopGroup, $idShop, $default)
 *   Configuration::updateValue($key, $values, $html, $idShopGroup, $idShop)
 *
 * It also keeps the real class's worst habit: get() and hasKey() answer from a
 * cache built when the process booted, never from the database. That is what
 * makes a stale-cache update silently update zero rows, and the tests need to
 * be able to reproduce it.
 *
 * The file has no namespace: the class must be \Configuration, because that is
 * what ConfigurationStore refers to.
 */

class Configuration
{
    /** @var array<string, string> the snapshot PrestaShop would have booted with */
    public static ?array $cache = null;

    /** @var array<int, mixed> */
    public static array $lastGet = [];

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        self::$lastGet = [$key, $idLang, $idShopGroup, $idShop];

        $cache = self::cache();

        if (isset($cache[$key . ':' . (string) $idShop])) {
            return $cache[$key . ':' . (string) $idShop];
        }

        return $default === false ? false : $default;
    }

    /** @var bool whether resetStaticCache() was called since the cache was built */
    public static bool $wasReset = false;

    public static function resetStaticCache()
    {
        self::$cache = null;
        self::$wasReset = true;
    }

    public static function getContextShopId()
    {
        return 1;
    }

    public static function deleteByName($name)
    {
        foreach (\Db::$table as $id => $row) {
            if ($row['name'] === $name) {
                unset(\Db::$table[$id]);
            }
        }

        return true;
    }

    public static function reset(): void
    {
        \Db::reset();
        self::$cache = null;
        self::$wasReset = false;
        self::$lastGet = [];
    }

    /**
     * @return array<string, string>
     */
    private static function cache(): array
    {
        // The real class loads the whole configuration table on first use and
        // then answers from that array for the rest of the process.
        return self::$cache ??= self::readTable();
    }

    /**
     * @return array<string, string>
     */
    private static function readTable(): array
    {
        $values = [];

        foreach (\Db::$table as $row) {
            $values[$row['name'] . ':' . (string) $row['id_shop']] = $row['value'];
        }

        return $values;
    }
}