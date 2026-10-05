<?php
/**
 * Installs, enables and configures the module on a real shop, from the CLI.
 *
 *   php tools/install-module.php
 *
 * Everything the module does at install time is exercised here for real: hook
 * registration, the configuration row, the version check against the shop it is
 * being installed into, and the back office self test.
 *
 * The shop context is built by hand because Module::install() reaches for
 * Context::getContext()->language, which is null in a bare CLI script.
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

/** @var Context $context */
$context = Context::getContext();
$context->shop = new Shop(1);
$languageId = (int) (Configuration::get('PS_LANG_DEFAULT') ?: Configuration::get('PS_LANG_DEFAULT_LANGUAGE'));
$context->language = new Language($languageId);

if (!Validate::isLoadedObject($context->language)) {
    fwrite(STDERR, "Could not load the default language of the shop\n");
    exit(1);
}
$context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$context->employee = new Employee(1);

$moduleName = 'selestrecaptcha';
$modulePath = _PS_MODULE_DIR_ . $moduleName;

if (!is_dir($modulePath)) {
    fwrite(STDERR, "Module directory not found: $modulePath\n");
    exit(1);
}

require_once $modulePath . '/' . $moduleName . '.php';

$module = Module::getInstanceByName($moduleName);

if ($module === null) {
    fwrite(STDERR, "Cannot instantiate the module\n");
    exit(1);
}

echo 'shop: PS ' . _PS_VERSION_ . ' / PHP ' . PHP_VERSION . "\n";

if (!Module::isInstalled($moduleName)) {
    $errors = [];

    if (!$module->install()) {
        fwrite(STDERR, 'install FAILED: ' . implode(' | ', array_merge($errors, $module->getErrors())) . "\n");
        exit(1);
    }

    echo "install: ok\n";
    $module = Module::getInstanceByName($moduleName);
}

if (!Module::isInstalled($moduleName)) {
    fwrite(STDERR, "The module is still not registered in the shop\n");
    exit(1);
}

echo 'module version: ' . $module->version . "\n";

if (!$module->enable()) {
    fwrite(STDERR, "enable FAILED\n");
    exit(1);
}

echo "enabled: yes\n";

$hooks = Db::getInstance()->executeS(
    'SELECT h.name FROM `' . _DB_PREFIX_ . 'hook` h
     INNER JOIN `' . _DB_PREFIX_ . 'hook_module` hm ON hm.id_hook = h.id_hook
     WHERE hm.id_module = ' . (int) $module->id . '
     ORDER BY h.name'
);

echo 'hooks: ' . implode(', ', array_column($hooks, 'name')) . "\n";

$store = new SelestRecaptcha\Config\ConfigurationStore();
$current = $store->load();

$updated = SelestRecaptcha\Config\Settings::fromArray(array_merge($current->toArray(), [
    'enabled' => '1',
    'version' => getenv('SR_VERSION') ?: SelestRecaptcha\Config\RecaptchaVersion::V2_CHECKBOX,
    'site_key' => getenv('SR_SITE_KEY') ?: 'site-key-for-the-test-shop',
    'secret_key' => getenv('SR_SECRET') ?: 'selest-fake-secret-key-000000000000',
    'endpoint' => getenv('SR_ENDPOINT') ?: 'http://127.0.0.1:18093/siteverify',
    'log_events' => '1',
]));

if (!$store->save($updated)) {
    fwrite(STDERR, "Could not save the settings\n");
    exit(1);
}

echo 'settings: ' . json_encode($updated->toArray(), JSON_UNESCAPED_SLASHES) . "\n";

$outcome = (new SelestRecaptcha\Diagnostics\KeyChecker(new SelestRecaptcha\Http\CurlTransport()))->check($updated);
echo 'key check: ' . $outcome . "\n";

echo "OK\n";