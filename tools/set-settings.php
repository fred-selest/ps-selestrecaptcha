<?php
/**
 * Applies a partial configuration from the command line.
 *
 *   php tools/set-settings.php '{"version":"v3","min_score":0.7}'
 *
 * Writes through the module's own store, so PrestaShop's configuration cache
 * is invalidated exactly as it is when the merchant saves the back office form.
 * Going around it with a raw SQL UPDATE leaves the shop serving the previous
 * value from cache, which makes a test lie.
 */

$rootDir = dirname(__DIR__, 3);

require_once $rootDir . '/config/config.inc.php';

// The kernels live in app/ and are not autoloaded by the shop itself.
if (is_dir($rootDir . '/app')) {
    require_once $rootDir . '/app/AppKernel.php';

    if (is_file($rootDir . '/app/FrontKernel.php')) {
        require_once $rootDir . '/app/FrontKernel.php';
    }
}
require_once _PS_MODULE_DIR_ . 'selestrecaptcha/src/autoload.php';


// PS 9.x boots the Symfony container through FrontKernel; PS 8.2 through the
// legacy AppKernel. Both are needed because Module::install() refreshes the
// translation tables through the translator.
if (class_exists('FrontKernel')) {
    $kernel = new FrontKernel(getenv('PS_ENV') ?: 'prod', false);
} elseif (class_exists('AppKernel')) {
    $kernel = new AppKernel(getenv('PS_ENV') ?: 'prod', false);
}

if (isset($kernel)) {
    // PrestaShop reads the container through the global $kernel.
    $GLOBALS['kernel'] = $kernel;
    $kernel->boot();
}

$context = Context::getContext();
$context->shop = new Shop((int) (getenv('PS_SHOP_ID') ?: Shop::getContextShopID()));
$languageId = (int) (Configuration::get('PS_LANG_DEFAULT') ?: Configuration::get('PS_LANG_DEFAULT_LANGUAGE'));
$context->language = new Language($languageId);

if (!Validate::isLoadedObject($context->language)) {
    fwrite(STDERR, "Could not load the default language of the shop\n");
    exit(1);
}
$context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$context->employee = new Employee(1);

$overrides = json_decode((string) ($argv[1] ?? '{}'), true);

if (!is_array($overrides)) {
    fwrite(STDERR, "Usage: set-settings.php '{\"key\": value}'\n");
    exit(1);
}

$store = new SelestRecaptcha\Config\ConfigurationStore();
$current = $store->load($context->shop->id);

$updated = SelestRecaptcha\Config\Settings::fromArray(
    array_merge($current->toArray(), $overrides),
    $current->shopUrls
);

if (!$store->save($updated, $context->shop->id)) {
    fwrite(STDERR, "Could not save the settings\n");
    exit(1);
}

echo json_encode($updated->toArray(), JSON_UNESCAPED_SLASHES), "\n";