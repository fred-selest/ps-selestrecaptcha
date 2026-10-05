<?php

/**
 * A stand-in for PrestaShop's Db class, holding the configuration table.
 *
 * ConfigurationStore aligns and de-duplicates the rows of its own scope with
 * plain SQL and then reads the result back, so the stub has to keep enough of
 * a table to answer UPDATE, DELETE and SELECT. It is deliberately tiny: the
 * module only ever touches one configuration key, on the columns it writes.
 */

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!function_exists('pSQL')) {
    /**
     * PrestaShop's escaping helper. ConfigurationStore calls it unqualified and
     * falls back to the global function, which only exists inside a shop.
     */
    function pSQL($string, $htmlOK = false, $bqSql = false)
    {
        return addslashes((string) $string);
    }
}

class Db
{
    /**
     * @var array<int, array{id_configuration: int, name: string, value: string, id_shop: int|null}>
     */
    public static array $table = [];

    public static int $nextId = 1;

    /** @var string[] every statement the module issued, in order */
    public static array $executed = [];

    public static function getInstance()
    {
        return new self();
    }

    public function insert($table, $data, $addslashes = true)
    {
        self::$executed[] = 'INSERT ' . $table . ' ' . json_encode($data);

        self::$table[self::$nextId] = [
            'id_configuration' => self::$nextId,
            'name' => (string) ($data['name'] ?? ''),
            'value' => (string) ($data['value'] ?? ''),
            'id_shop' => isset($data['id_shop']) && $data['id_shop'] !== null ? (int) $data['id_shop'] : null,
        ];
        self::$nextId++;

        return true;
    }

    public function update($table, $values, $where = '', $addslashes = true, $useCache = true)
    {
        self::$executed[] = 'UPDATE ' . $table . ' SET ' . json_encode($values) . ' WHERE ' . $where;

        if (!preg_match('/id_configuration = (\d+)/', $where, $m)) {
            return false;
        }

        $id = (int) $m[1];

        if (!isset(self::$table[$id])) {
            return false;
        }

        foreach ($values as $column => $value) {
            if ($column === 'value') {
                self::$table[$id]['value'] = $addslashes ? stripslashes((string) $value) : (string) $value;
            }
        }

        return true;
    }

    public function execute($sql, $use_cache = true)
    {
        $statement = trim(preg_replace('/\s+/', ' ', (string) $sql));
        self::$executed[] = $statement;

        if (preg_match('/^UPDATE .* SET value = "(.*)", date_upd.*WHERE name = "(.*?)" (.*)$/', $statement, $m)) {
            foreach (self::$table as $id => $row) {
                if ($row['name'] === $m[2] && self::scopeMatches($row['id_shop'], $m[3])) {
                    self::$table[$id]['value'] = stripslashes($m[1]);
                }
            }

            return true;
        }

        if (preg_match('/^DELETE FROM .* WHERE name = "(.*?)" (.*?)AND id_configuration <> (\d+)/', $statement, $m)) {
            foreach (self::$table as $id => $row) {
                if ($row['name'] === $m[1] && $id !== (int) $m[3] && self::scopeMatches($row['id_shop'], $m[2])) {
                    unset(self::$table[$id]);
                }
            }

            return true;
        }

        return true;
    }

    /**
     * PrestaShop's getValue() appends its own LIMIT 1 and returns the first
     * column of the first row.
     */
    public function getValue($sql, $useCache = true)
    {
        $statement = trim(preg_replace('/\s+/', ' ', (string) $sql));
        self::$executed[] = $statement;

        if (!preg_match('/^SELECT (\w+) .* WHERE name = "(.*?)" (.*?)ORDER BY id_configuration/', $statement, $m)) {
            return false;
        }

        [, $column, $name, $clause] = $m;

        foreach (self::$table as $row) {
            if ($row['name'] === $name && self::scopeMatches($row['id_shop'], $clause)) {
                return $row[$column] ?? false;
            }
        }

        return false;
    }

    /**
     * Adds a row the way Configuration::updateValue() would.
     */
    public static function insertConfiguration(string $name, string $value, ?int $idShop): int
    {
        $id = self::$nextId++;
        self::$table[$id] = [
            'id_configuration' => $id,
            'name' => $name,
            'value' => $value,
            'id_shop' => $idShop,
        ];

        return $id;
    }

    public static function reset(): void
    {
        self::$table = [];
        self::$nextId = 1;
        self::$executed = [];
    }

    private static function scopeMatches(?int $idShop, string $clause): bool
    {
        if (str_contains($clause, 'id_shop IS NULL')) {
            return $idShop === null;
        }

        return preg_match('/id_shop = (\d+)/', $clause, $m) === 1 && $idShop === (int) $m[1];
    }
}