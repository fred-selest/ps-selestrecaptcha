<?php
/**
 * Selest reCAPTCHA — Google anti-spam for PrestaShop 8.2 LTS and 9.x.
 *
 * @author    Fred Selest
 * @copyright 2026 Selest
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

use SelestRecaptcha\Admin\ConflictingModules;
use SelestRecaptcha\Admin\SettingsForm;
use SelestRecaptcha\Config\ConfigurationStore;
use SelestRecaptcha\Config\RecaptchaVersion;
use SelestRecaptcha\Config\Settings;
use SelestRecaptcha\Diagnostics\KeyChecker;
use SelestRecaptcha\Guard\FlashHolder;
use SelestRecaptcha\Guard\RequestSnapshot;
use SelestRecaptcha\Guard\SubmissionBlocker;
use SelestRecaptcha\Guard\SubmissionInspector;
use SelestRecaptcha\Guard\SuperglobalRequestWriter;
use SelestRecaptcha\Guard\Target;
use SelestRecaptcha\Http\CurlTransport;
use SelestRecaptcha\Log\EventLoggerInterface;
use SelestRecaptcha\Log\NullEventLogger;
use SelestRecaptcha\Log\PsEventLogger;
use SelestRecaptcha\Stats\InstallReporter;
use SelestRecaptcha\Verification\Judgement;
use SelestRecaptcha\Verification\SubmissionJudge;
use SelestRecaptcha\Verification\Verifier;
use SelestRecaptcha\Widget\WidgetConfig;

class Selestrecaptcha extends Module
{
    public const TRANSLATION_DOMAIN = 'Modules.Selestrecaptcha.Admin';
    public const FRONT_DOMAIN = 'Modules.Selestrecaptcha.Shop';

    public const ADMIN_SAVE_BUTTON = 'submitSettings';
    public const ADMIN_TEST_BUTTON = 'selestrecaptcha_test';
    public const ADMIN_STATS_BUTTON = 'selestrecaptcha_stats';

    /** Front controller hook that runs before any core handler acts on the POST. */
    public const GUARD_HOOK = 'actionFrontControllerInitBefore';

    private ?Settings $settings = null;
    private ?FlashHolder $flash = null;

    public function __construct()
    {
        $this->name = 'selestrecaptcha';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Fred Selest';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->confirmUninstall = false;

        // PS 8.2 LTS is the floor: it is the branch that still receives
        // security fixes, and it is the same branch shops actually stay on.
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('reCAPTCHA anti-spam', [], self::TRANSLATION_DOMAIN);
        $this->description = $this->trans(
            'Google reCAPTCHA for the contact form, account creation, newsletter and product reviews.',
            [],
            self::TRANSLATION_DOMAIN
        );
        $this->registerHook($this->hookNames());
    }

    /* --------------------------------------------------------------------- */
    /* Lifecycle                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * PrestaShop reads the return value: anything falsy is reported to the
     * merchant as a failed installation.
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // registerHook() is a no-op when called from the constructor of a module
        // that has no id yet, which is exactly the case during a fresh install:
        // Module::install() does not register hooks either. Without this the
        // module installs and then never runs.
        $this->registerHook($this->hookNames());

        $store = new ConfigurationStore();

        if (!$store->exists()) {
            $settings = Settings::defaults($this->translatedMessages());
            $store->save($settings);
        } else {
            $settings = $store->load($this->currentShopId());
        }

        // Opt-in, and never able to fail the installation.
        (new InstallReporter(new CurlTransport()))
            ->report(InstallReporter::EVENT_INSTALL, $settings, $this->version);

        return true;
    }

    /**
     * Called by PrestaShop when a newer version replaces the installed one.
     */
    public function upgrade($version)
    {
        (new InstallReporter(new CurlTransport()))
            ->report(InstallReporter::EVENT_UPGRADE, $this->settings(), $this->version);

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function hookNames(): array
    {
        return [
            self::GUARD_HOOK,
            'actionFrontControllerSetMedia',
            'registerGDPRConsent',
        ];
    }

    public function uninstall()
    {
        (new ConfigurationStore())->delete();

        return parent::uninstall();
    }

    /* --------------------------------------------------------------------- */
    /* Front office: the guard                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Runs before FrontController does anything with the POST, which is what
     * makes it a real gate: contactform and ps_emailsubscription process their
     * forms from a display hook, i.e. much later.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionFrontControllerInitBefore($params = []): void
    {
        // The guard only ever runs for shop visitors.
        if (!$this->active()) {
            return;
        }

        $request = $this->requestSnapshot();
        $target = (new SubmissionInspector($this->settings()))->detect($request);

        if ($target === null) {
            return;
        }

        $judgement = $this->judge()->judge($request, $target);

        if ($judgement->allowed) {
            return;
        }

        $this->applyBlock($target, $judgement, $request);
    }

    private function applyBlock(Target $target, Judgement $judgement, RequestSnapshot $request): void
    {
        $message = $this->message($judgement);

        (new SubmissionBlocker(new SuperglobalRequestWriter(), $this->flashHolder()))->block($target, $message);

        if ($target->isAjax() && $request->isAjax) {
            $this->exitWithAjaxError($message);
        }
    }

    /**
     * The review endpoint's own JavaScript renders `errors` when the answer has
     * success:false, and falls back to a generic banner on any HTTP failure
     * status. Answering 200 with its own envelope is what puts the real reason
     * in front of the visitor.
     */
    private function exitWithAjaxError(string $message): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            http_response_code(200);
        }

        echo json_encode(['success' => false, 'errors' => [$message]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function message(Judgement $judgement): string
    {
        $messages = $this->settings()->messages;
        $key = $judgement->messageKey();

        $message = (string) ($messages[$key] ?? '');

        return $message !== '' ? $message : $this->trans('Please complete the captcha to continue.', [], self::FRONT_DOMAIN);
    }

    /* --------------------------------------------------------------------- */
    /* Front office: the widget                                               */
    /* --------------------------------------------------------------------- */

    public function hookActionFrontControllerSetMedia($params = []): void
    {
        if (!$this->active()) {
            return;
        }

        $controller = $this->context->controller;

        if (!method_exists($controller, 'registerJavascript') || !method_exists($controller, 'registerStylesheet')) {
            return;
        }

        // The script finds the forms it must protect before it decides to load
        // anything from Google: a shop with no protected form on the page pays
        // nothing at all.
        $controller->registerJavascript(
            $this->name,
            'modules/' . $this->name . '/views/js/selestrecaptcha.js',
            'bottom',
            false
        );

        $controller->registerStylesheet(
            $this->name,
            'modules/' . $this->name . '/views/css/selestrecaptcha.css',
            ['media' => 'all', 'priority' => 200]
        );

        $this->publishConfiguration();
    }

    /**
     * Hands the configuration to the front script through PrestaShop's own
     * JavaScript definitions, the way ps_emailsubscription does.
     *
     * The alternative — printing it from a display hook — silently produces
     * nothing on the themes that do not call that hook: neither Classic nor
     * Hummingbird renders `displayHeader` any more, and a module that looks
     * installed while it protects nothing is the worst possible failure.
     */
    private function publishConfiguration(): void
    {
        $config = WidgetConfig::fromSettings(
            $this->settings(),
            Settings::SCRIPT,
            $this->context->language->iso_code
        )->withFlashMessage($this->flashHolder()->message());

        Media::addJsDef(['selestRecaptchaConfig' => $config->toArray()]);
    }

    /**
     * Tells psgdpr (and any consent manager) that this module talks to Google.
     *
     * The hook name is derived from the method name, so this must stay
     * hookRegisterGDPRConsent: PrestaShop refuses (or warns on) a registered
     * hook whose method does not follow that convention.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    public function hookRegisterGDPRConsent($params = [])
    {
        $context = $this->context;

        if (!empty($params['active']) && isset($context->cookie->rgpdConsent)) {
            return ['modules/selestrecaptcha/views/js/selestrecaptcha.js' => 'google_recaptcha'];
        }

        return [];
    }

    /* --------------------------------------------------------------------- */
    /* Back office                                                            */
    /* --------------------------------------------------------------------- */

    public function getContent(): string
    {
        $store = new ConfigurationStore();
        $shopId = $this->currentShopId();
        $settings = $store->load($shopId);
        $output = '';

        if (Tools::isSubmit(self::ADMIN_TEST_BUTTON)) {
            $output .= $this->renderKeyDiagnostic();
        }

        if (Tools::isSubmit(self::ADMIN_STATS_BUTTON)) {
            $output .= $this->renderInstallPing();
        }

        if (Tools::isSubmit(self::ADMIN_SAVE_BUTTON) || Tools::isSubmit('submitAdd')) {
            $posted = Tools::getValue(SettingsForm::INPUT);
            $raw = is_array($posted) ? $posted : [];
            $updated = Settings::fromArray(
                $this->mergeWithStored($settings, $raw),
                $settings->shopUrls
            );

            if ($store->save($updated, $shopId)) {
                $this->settings = $updated;
                $output .= $this->notice(
                    $this->trans('Settings saved.', [], self::TRANSLATION_DOMAIN),
                    'success'
                );
            } else {
                $output .= $this->notice(
                    $this->trans('Settings could not be saved.', [], self::TRANSLATION_DOMAIN),
                    'error'
                );
            }
        }

        if ($settings->enabled && $settings->usesTestKeys()) {
            $output .= $this->notice(
                $this->trans(
                    "Google's test keys are in use: every captcha will pass, including those of bots. Use them on staging only.",
                    [],
                    self::TRANSLATION_DOMAIN
                ),
                'warn'
            );
        }

        if ($settings->enabled && ($settings->siteKey === '' || $settings->secretKey === '')) {
            $output .= $this->notice(
                $this->trans('Protection is enabled but no key is set: nothing is being verified yet.', [], self::TRANSLATION_DOMAIN),
                'warn'
            );
        }

        $conflicts = (new ConflictingModules($this->name))->active();

        if ($conflicts !== []) {
            $output .= $this->notice(
                $this->trans(
                    'Another reCAPTCHA module is still installed on this shop (%s). Two captchas on the same form protect nothing: uninstall the other one.',
                    [implode(', ', $conflicts)],
                    self::TRANSLATION_DOMAIN
                ),
                'warn'
            );
        }

        $output .= $this->renderForm($settings);

        return $output;
    }

    /**
     * Only the keys the back office posted are replaced: HelperForm omits a
     * field it did not render, and a missing field must never silently reset
     * to a default the merchant chose.
     *
     * @param array<string, mixed> $posted
     *
     * @return array<string, mixed>
     */
    private function mergeWithStored(Settings $settings, array $posted): array
    {
        $merged = $settings->toArray();

        foreach ($posted as $key => $value) {
            if ($key === 'targets') {
                foreach ($value as $target => $targetValues) {
                    $merged['targets'][$target] = array_merge(
                        $merged['targets'][$target] ?? [],
                        is_array($targetValues) ? $targetValues : []
                    );
                }
                continue;
            }

            $merged[$key] = $value;
        }

        // An empty secret field in the browser means "unchanged", not "clear it".
        if (trim((string) ($posted['secret_key'] ?? '')) === '') {
            $merged['secret_key'] = $settings->secretKey;
        }

        return $merged;
    }

    /**
     * The visitor-facing defaults, in the language of the shop: a French shop
     * should not start by telling customers "Please complete the captcha to
     * continue." in English.
     *
     * @return array<string, string>
     */
    private function translatedMessages(): array
    {
        $defaults = Settings::defaultMessages();
        $translated = [];

        foreach (array_keys($defaults) as $key) {
            $translated[$key] = $this->trans($defaults[$key], [], self::FRONT_DOMAIN);
        }

        return $translated;
    }

    private function renderForm(Settings $settings): string
    {
        $form = new SettingsForm($this);

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        // Outside a real admin request (CLI diagnostics, tests) the controller
        // has not filled those in; a form without a token cannot be submitted.
        $helper->token = ($this->token ?? '') ?: Tools::getAdminTokenLite('AdminModules');
        $helper->show_toolbar = true;
        $helper->submit_action = self::ADMIN_SAVE_BUTTON;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name;
        $helper->table = ($this->table ?? '') ?: 'module';

        // HelperForm takes the inputs and the values separately.
        $helper->fields_value = $form->values($settings);

        return (string) $helper->generateForm($form->formArray($settings));
    }

    /**
     * Sends the installation ping by hand, so the merchant can see it leave the
     * shop rather than trusting a switch.
     */
    private function renderInstallPing(): string
    {
        $settings = $this->settings();
        $sent = (new InstallReporter(new CurlTransport()))
            ->report(InstallReporter::EVENT_INSTALL, $settings, $this->version);

        if (!$settings->statsEnabled || $settings->statsEndpoint === '') {
            return $this->notice(
                $this->trans(
                    'Set an HTTPS statistics endpoint first; nothing was sent.',
                    [],
                    self::TRANSLATION_DOMAIN
                ),
                'warn'
            );
        }

        return $this->notice(
            $sent
                ? $this->trans('The installation ping was sent to the endpoint you set.', [], self::TRANSLATION_DOMAIN)
                : $this->trans('The endpoint did not answer. Check the address, then try again.', [], self::TRANSLATION_DOMAIN),
            $sent ? 'success' : 'error'
        );
    }

    private function renderKeyDiagnostic(): string
    {
        $settings = $this->settings();
        $outcome = (new KeyChecker(new CurlTransport()))->check($settings);

        [$message, $type] = match ($outcome) {
            KeyChecker::OK => [
                $this->trans('Google accepted the secret key and this shop can reach the verification endpoint.', [], self::TRANSLATION_DOMAIN),
                'success',
            ],
            KeyChecker::WRONG_SECRET => [
                $this->trans('Google refused the secret key. Copy it again from the reCAPTCHA console.', [], self::TRANSLATION_DOMAIN),
                'error',
            ],
            KeyChecker::NOT_CONFIGURED => [
                $this->trans('Fill in the site key and the secret key first.', [], self::TRANSLATION_DOMAIN),
                'warn',
            ],
            default => [
                $this->trans(
                    'The verification endpoint could not be reached from this server. With "refuse the submission" selected, every form will be blocked while this lasts.',
                    [],
                    self::TRANSLATION_DOMAIN
                ),
                'error',
            ],
        };

        return $this->notice($message, $type);
    }

    private function notice(string $message, string $type): string
    {
        return sprintf('<div class="alert alert-%s">%s</div>', htmlspecialchars($type, ENT_QUOTES, 'UTF-8'), $message);
    }

    private function currentShopId(): ?int
    {
        if ($this->context->shop instanceof \Shop) {
            return (int) $this->context->shop->id;
        }

        return null;
    }

    /* --------------------------------------------------------------------- */
    /* Wiring                                                                 */
    /* --------------------------------------------------------------------- */

    private function active(): bool
    {
        if (!(bool) $this->active) {
            return false;
        }

        $settings = $this->settings();

        return $settings->isUsable();
    }

    private function settings(): Settings
    {
        if ($this->settings === null) {
            $this->settings = (new ConfigurationStore())->load(
                $this->context->shop instanceof \Shop ? (int) $this->context->shop->id : null
            );
        }

        return $this->settings;
    }

    private function flashHolder(): FlashHolder
    {
        if ($this->flash === null) {
            $this->flash = new FlashHolder();
        }

        return $this->flash;
    }

    private function judge(): SubmissionJudge
    {
        $settings = $this->settings();
        $verifier = new Verifier(
            new CurlTransport(),
            $settings->endpoint,
            $settings->timeout
        );

        return new SubmissionJudge($settings, $verifier, $this->logger());
    }

    /**
     * Bridges the module's logging interface to the PrestaShop log channel.
     * Neither the secret nor the token ever reaches this method.
     */
    private function logger(): EventLoggerInterface
    {
        if (!$this->settings()->logEvents) {
            return new NullEventLogger();
        }

        return new PsEventLogger($this);
    }

    private function requestSnapshot(): RequestSnapshot
    {
        $controller = $this->context->controller;

        return new RequestSnapshot(
            is_object($controller) && method_exists($controller, 'php_self')
                ? (string) $controller->php_self
                : (string) $controller::class,
            $_POST,
            $_GET,
            $this->isAjaxRequest(),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')),
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
        );
    }

    /**
     * XHR is not always announced: the review form posts with jQuery, which sets
     * X-Requested-With, but a plain fetch would not.
     */
    private function isAjaxRequest(): bool
    {
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }
}