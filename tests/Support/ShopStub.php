<?php

/**
 * A stand-in for PrestaShop's Shop class.
 *
 * ConfigurationStore asks Shop::isFeatureActive() which scope to use, so the
 * tests need to be able to answer both ways. The file has no namespace because
 * the module refers to \Shop.
 */

class Shop
{
    public static bool $featureActive = true;

    public static function isFeatureActive(): bool
    {
        return self::$featureActive;
    }

    public static function reset(): void
    {
        self::$featureActive = true;
    }
}