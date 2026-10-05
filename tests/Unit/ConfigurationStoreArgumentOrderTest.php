<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SelestRecaptcha\Config\RecaptchaVersion;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Config\ConfigurationStore;

require_once __DIR__ . '/../Support/ConfigurationStub.php';
require_once __DIR__ . '/../Support/DbStub.php';
require_once __DIR__ . '/../Support/ShopStub.php';

/**
 * ConfigurationStore is the only class that talks to PrestaShop, so it is the
 * only place where an argument-order mistake can silently read the wrong row.
 *
 * PrestaShop's signatures are:
 *   Configuration::get($key, $idLang, $idShopGroup, $idShop, $default)
 *   Configuration::updateValue($key, $values, $html, $idShopGroup, $idShop)
 *
 * The shop GROUP comes before the shop. Getting that wrong compiles, runs and
 * quietly falls back to the global configuration on a multistore install, so
 * the order is pinned here instead of left to a comment.
 */
final class ConfigurationStoreArgumentOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::reset();
        \Db::reset();
        \Shop::reset();
    }

    public function testLoadAsksForTheShopInTheFourthSlotNotTheThird(): void
    {
        (new ConfigurationStore())->load(7);

        self::assertSame(
            [ConfigurationStore::KEY, null, null, 7],
            \Configuration::$lastGet,
            'the shop id must be passed as $idShop, not as $idShopGroup'
        );
    }

    public function testExistsAsksForTheShopInTheFourthSlot(): void
    {
        (new ConfigurationStore())->exists(4);

        self::assertSame([ConfigurationStore::KEY, null, null, 4], \Configuration::$lastGet);
    }

    public function testAShopSaveLandsInThatShop(): void
    {
        (new ConfigurationStore())->save(Settings::defaults(), 3);

        $row = \Db::$table[array_key_first(\Db::$table)] ?? null;

        self::assertIsArray($row);
        self::assertSame(3, $row['id_shop'], 'a shop save must not fall back to the global scope');
        self::assertSame(ConfigurationStore::KEY, $row['name']);
    }

    public function testGlobalScopePassesNoShopAtAll(): void
    {
        (new ConfigurationStore())->save(Settings::defaults(), null);

        $row = \Db::$table[array_key_first(\Db::$table)] ?? null;

        self::assertIsArray($row);
        self::assertNull($row['id_shop'], 'a global save must not name a shop');
    }

    public function testASecondSaveUpdatesTheRowInsteadOfAddingOne(): void
    {
        $store = new ConfigurationStore();
        $store->save(Settings::fromArray(['min_score' => 0.3]), 1);
        $store->save(Settings::fromArray(['min_score' => 0.4]), 1);

        self::assertCount(1, \Db::$table);
        self::assertSame(0.4, $store->load(1)->minScore);
    }

    public function testADuplicatedScopeIsLeftWithASingleRow(): void
    {
        // MySQL treats NULLs as distinct, so a second save can leave two global
        // rows behind; the shop then reads whichever one comes back first.
        \Db::insertConfiguration(ConfigurationStore::KEY, json_encode(Settings::defaults()->toArray()), null);
        \Db::insertConfiguration(ConfigurationStore::KEY, json_encode(Settings::defaults()->toArray()), null);

        $wanted = Settings::fromArray(['min_score' => 0.8]);
        $store = new ConfigurationStore();

        self::assertTrue($store->save($wanted, null));
        self::assertCount(1, \Db::$table);
        self::assertSame(0.8, $store->load(null)->minScore);
        self::assertSame(
            $wanted->toArray(),
            json_decode(reset(\Db::$table)['value'], true),
            'JSON has more than one encoding, so the row is compared as data'
        );
    }

    public function testWithoutTheMultistoreFeatureEverythingLivesInTheGlobalRow(): void
    {
        // PrestaShop reads the whole configuration from the global row when the
        // per-shop cache does not exist, so a row written with an id_shop on such
        // a shop would never be read back.
        \Shop::$featureActive = false;

        $store = new ConfigurationStore();

        self::assertTrue($store->save(Settings::defaults(), 1));
        self::assertCount(1, \Db::$table);
        self::assertNull(reset(\Db::$table)['id_shop']);
    }

    public function testAShopSaveNeverTouchesTheGlobalScope(): void
    {
        \Db::insertConfiguration(ConfigurationStore::KEY, json_encode(Settings::defaults()->toArray()), null);

        (new ConfigurationStore())->save(Settings::defaults(), 5);

        $joined = implode("\n", \Db::$executed);

        self::assertStringNotContainsString('id_shop IS NULL', $joined);
        self::assertCount(2, \Db::$table, 'the global row must be left alone');
    }

    public function testTheShopCacheIsResetSoTheNextReadSeesTheSave(): void
    {
        $store = new ConfigurationStore();
        $store->save(Settings::fromArray(['enabled' => true]), 6);

        // Before the save the cached value is the one from the boot snapshot.
        self::assertTrue($store->load(6)->enabled);

        $store->save(Settings::fromArray(['enabled' => false]), 6);

        self::assertFalse($store->load(6)->enabled, 'a reset cache is what makes the save visible');
        self::assertTrue(\Configuration::$wasReset);
    }

    public function testAStaleShopWriteStillReachesTheDatabase(): void
    {
        // PrestaShop keeps answering from the cache it built when the process
        // booted. The module must not trust it: what matters is the row.
        $store = new ConfigurationStore();
        $wanted = Settings::fromArray(['min_score' => 0.9]);

        self::assertTrue($store->save($wanted, 5));
        self::assertSame(
            json_encode($wanted->toArray(), JSON_UNESCAPED_UNICODE),
            reset(\Db::$table)['value']
        );
    }

    public function testEachShopReadsItsOwnStoredPayload(): void
    {
        $store = new ConfigurationStore();

        $store->save(Settings::fromArray([
            'enabled' => true,
            'version' => RecaptchaVersion::V2_CHECKBOX,
            'secret_key' => 'shop-one-secret',
        ]), 1);


        $store->save(Settings::fromArray([
            'enabled' => false,
            'version' => RecaptchaVersion::V3,
            'secret_key' => 'shop-two-secret',
        ]), 2);

        self::assertTrue($store->load(1)->enabled);
        self::assertSame(RecaptchaVersion::V2_CHECKBOX, $store->load(1)->version);
        self::assertSame('shop-one-secret', $store->load(1)->secretKey);

        self::assertFalse($store->load(2)->enabled);
        self::assertSame(RecaptchaVersion::V3, $store->load(2)->version);
        self::assertSame('shop-two-secret', $store->load(2)->secretKey);
    }
}