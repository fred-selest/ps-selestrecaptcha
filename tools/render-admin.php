<?php
/**
 * Renders the back-office configuration page of the module.
 *
 *   php tools/render-admin.php [--save] [--test]
 *
 * The merchant spends all their time on this screen, so it is exercised like
 * any other code path: as it is, after a save, and after pressing "test the
 * keys".
 *
 * PrestaShop only renders a module page from inside an admin controller, and
 * it decides which Smarty plugin set to load by looking at _PS_ADMIN_DIR_. The
 * constant is therefore defined here, which is what gives the harness the same
 * environment the back office has. PrestaShop also renames the admin folder
 * during installation, so the folder is looked up rather than guessed.
 */

$rootDir = dirname(__DIR__, 3);
$adminDir = null;

foreach (glob($rootDir . '/admin*', GLOB_ONLYDIR) ?: [] as $candidate) {
    if (is_file($candidate . '/index.php') && is_dir($candidate . '/themes')) {
        $adminDir = $candidate;
    }
}

if ($adminDir === null) {
    fwrite(STDERR, "No admin directory in $rootDir\n");
    exit(1);
}

define('_PS_ADMIN_DIR_', $adminDir);

require_once $rootDir . '/config/config.inc.php';
require_once $rootDir . '/app/AppKernel.php';

if (is_file($rootDir . '/app/FrontKernel.php')) {
    require_once $rootDir . '/app/FrontKernel.php';
}

// PrestaShop reads the container through the global $kernel.
if (class_exists('FrontKernel')) {
    $kernel = new FrontKernel(getenv('PS_ENV') ?: 'prod', false);
} elseif (class_exists('AppKernel')) {
    $kernel = new AppKernel(getenv('PS_ENV') ?: 'prod', false);
}

$GLOBALS['kernel'] = $kernel;
$kernel->boot();

require_once _PS_MODULE_DIR_ . 'selestrecaptcha/src/autoload.php';

$context = Context::getContext();
$context->shop = new Shop((int) (getenv('PS_SHOP_ID') ?: Shop::getContextShopID()));

$languageId = (int) (Configuration::get('PS_LANG_DEFAULT') ?: Configuration::get('PS_LANG_DEFAULT_LANGUAGE'));
$context->language = new Language($languageId ?: 1);
$context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
$context->employee = new Employee((int) (getenv('PS_EMPLOYEE_ID') ?: 1));

// AdminController points Smarty at the admin theme before rendering anything;
// without it the helper templates cannot be found.
$adminTheme = Configuration::get('PS_ADMIN_THEME') ?: 'default';
$context->smarty->setTemplateDir($adminDir . '/themes/' . $adminTheme . '/template/');
$context->smarty->setCompileDir($adminDir . '/themes/' . $adminTheme . '/template/cache/');

if (!Validate::isLoadedObject($context->employee)) {
    fwrite(STDERR, "No employee in this shop; the back office cannot be rendered.\n");
    exit(1);
}

$options = getopt('', ['save', 'test', 'dump:']);
$save = array_key_exists('save', $options);
$test = array_key_exists('test', $options);

require_once _PS_MODULE_DIR_ . 'selestrecaptcha/selestrecaptcha.php';

/** @var Selestrecaptcha $module */
$module = Selestrecaptcha::getInstanceByName('selestrecaptcha');

if ($module === null) {
    fwrite(STDERR, "The module is not installed in this shop.\n");
    exit(1);
}

// getContent() reads its input through Tools, so the simulated submission has
// to go through the same door as a real form post.
$_POST = [];
$_GET = [];

if ($save) {
    $current = (new SelestRecaptcha\Config\ConfigurationStore())->load($context->shop->id);
    $raw = $current->toArray();

    $_POST['submitSettings'] = '1';
    $_POST[SelestRecaptcha\Admin\SettingsForm::INPUT] = [
        'enabled' => $raw['enabled'] ? 1 : 0,
        'version' => $raw['version'],
        'site_key' => $raw['site_key'],
        // Left empty on purpose: an empty password field means "unchanged".
        'secret_key' => '',
        'min_score' => (string) $raw['min_score'],
        'fail_mode' => $raw['fail_mode'],
        'strict_hostname' => $raw['strict_hostname'] ? 1 : 0,
        'log_events' => $raw['log_events'] ? 1 : 0,
        'endpoint' => $raw['endpoint'],
        'timeout' => (string) $raw['timeout'],
        'messages' => $raw['messages'],
        'targets' => $raw['targets'],
    ];
}

if ($test) {
    $_POST[Selestrecaptcha::ADMIN_TEST_BUTTON] = '1';
}

$html = $module->getContent();

printf("rendered %d bytes\n", strlen($html));

if (isset($options['dump'])) {
    file_put_contents($options['dump'], $html);
    printf("  written to %s\n", $options['dump']);
}

$expected = [
    'selestrecaptcha[version]' => 'the version selector',
    'selestrecaptcha[site_key]' => 'the site key field',
    'selestrecaptcha[secret_key]' => 'the secret key field',
    'selestrecaptcha[min_score]' => 'the score threshold',
    'selestrecaptcha[fail_mode]' => 'the failure mode',
    'selestrecaptcha[targets][contact][action]' => 'the contact form action',
    'selestrecaptcha[targets][newsletter][enabled]' => 'the newsletter switch',
    'selestrecaptcha[targets][comment][min_score]' => 'the review threshold',
    'selestrecaptcha_test' => 'the key self test',
    'selestrecaptcha[messages][block]' => 'the visitor message',
];

$failures = 0;

foreach ($expected as $needle => $label) {
    $ok = str_contains($html, $needle);
    $failures += $ok ? 0 : 1;
    printf("  %-52s %s\n", $label, $ok ? 'present' : 'MISSING');
}

$echoesSecret = (bool) preg_match('/name="selestrecaptcha\[secret_key\]"[^>]*value="[^"]+"/', $html);
printf("  %-52s %s\n", 'the secret key is not echoed back', $echoesSecret ? 'LEAKED' : 'masked');
$failures += $echoesSecret ? 1 : 0;

foreach (['Settings saved.', 'Google accepted the secret key', 'Google refused the secret key', 'could not be reached'] as $notice) {
    if (str_contains($html, $notice)) {
        printf("  notice: %s\n", $notice);
    }
}

if ($save) {
    $saved = (new SelestRecaptcha\Config\ConfigurationStore())->load($context->shop->id);
    printf("  stored version: %s\n", $saved->version);
}

printf("%s\n", $failures === 0 ? 'OK' : $failures . ' problem(s)');

exit($failures === 0 ? 0 : 1);