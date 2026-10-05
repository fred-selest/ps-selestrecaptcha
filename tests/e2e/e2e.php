<?php

declare(strict_types=1);

/**
 * End-to-end tests against a real, running shop.
 *
 *   php tests/e2e/e2e.php http://127.0.0.1
 *
 * Nothing is mocked on the shop side: real front controllers, real templates,
 * real database. The only fake is the Google endpoint, served by
 * tests/Integration/fake-google.php and configured as the module's endpoint.
 *
 * Each check states what it proves, and every check that says "blocked" is
 * proved by the absence of the side effect in the database, not by the absence
 * of an error message.
 */
final class ShopClient
{
    private string $base;
    private string $jar;

    public function __construct(string $base, string $jar)
    {
        $this->base = rtrim($base, '/');
        $this->jar = $jar;
    }

    /**
     * @param array<string, string>|null $post
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     */
    public function request(string $path, ?array $post = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    public function reset(): void
    {
        @unlink($this->jar);
    }
}

final class Harness
{
    public int $passed = 0;
    public int $failed = 0;
    /** @var array<int, string> */
    public array $failures = [];

    public function __construct(
        private readonly string $shopDir,
        private readonly string $dbName = 'ps828',
        private readonly string $dbUser = 'selest',
        private readonly string $dbPass = 'selestpass',
    ) {
    }

    public string $lastBody = '';

    public function check(string $what, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            ++$this->passed;
            printf("  ok   %s\n", $what);

            return;
        }

        ++$this->failed;
        $this->failures[] = $what . ($detail !== '' ? ' — ' . $detail : '');
        printf("  FAIL %s%s\n", $what, $detail !== '' ? ' — ' . $detail : '');
        printf("       answer: %s\n", $this->snippet($this->lastBody));
    }

    public function section(string $title): void
    {
        printf("\n%s\n", $title);
    }

    public function summary(): int
    {
        printf("\n%d passed, %d failed\n", $this->passed, $this->failed);

        foreach ($this->failures as $failure) {
            printf("  - %s\n", $failure);
        }

        return $this->failed === 0 ? 0 : 1;
    }

    public function count(string $sql): int
    {
        $pdo = new PDO(
            sprintf('mysql:host=127.0.0.1;dbname=%s;charset=utf8mb4', $this->dbName),
            $this->dbUser,
            $this->dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        return (int) $pdo->query($sql)->fetchColumn();
    }

    /**
     * Applies settings through the shop's own code path.
     *
     * A raw SQL UPDATE would leave PrestaShop's configuration cache holding the
     * previous value, so the very next request would keep the old behaviour and
     * the test would assert against a shop that never changed.
     *
     * @param array<string, mixed> $overrides
     */
    public function configure(array $overrides = []): void
    {
        $command = sprintf(
            'cd %s && php -d memory_limit=-1 %s %s 2>&1',
            escapeshellarg($this->shopDir),
            escapeshellarg($this->shopDir . '/modules/selestrecaptcha/tools/set-settings.php'),
            escapeshellarg((string) json_encode($overrides, JSON_UNESCAPED_SLASHES))
        );

        exec($command, $output, $status);

        if ($status !== 0) {
            fwrite(STDERR, "configure() failed: " . implode("\n", $output) . "\n");
            exit(1);
        }
    }

    public function snippet(string $html): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($html)) ?? $html;

        return substr(trim($text), 0, 220);
    }

    public function shopDir(): string
    {
        return $this->shopDir;
    }
}

$baseUrl = $argv[1] ?? 'http://127.0.0.1';
$shopDir = $argv[2] ?? dirname(__DIR__, 2);
$dbName = $argv[3] ?? 'ps828';

$fakeState = getenv('SR_GOOGLE_STATE') ?: (__DIR__ . '/../Integration/.google-state.json');
if (is_file($fakeState)) {
    unlink($fakeState);
}

$jar = tempnam(sys_get_temp_dir(), 'sr-jar-');
$contactHtml = '';
$client = new ShopClient($baseUrl, $jar);
$h = new Harness($shopDir, $dbName);

// A known starting point: a previous run may have left the module disabled or
// pointed at a dead endpoint.
$h->configure([
    'enabled' => true,
    'version' => 'v2_checkbox',
    'site_key' => 'site-key-for-the-test-shop',
    'secret_key' => 'selest-fake-secret-key-000000000000',
    'endpoint' => 'http://127.0.0.1:18093/siteverify',
    'fail_mode' => 'closed',
    'strict_hostname' => false,
    'log_events' => true,
    'min_score' => 0.5,
    'targets' => [
        'contact' => ['enabled' => true, 'action' => 'contact', 'min_score' => null, 'strict_hostname' => null],
        'registration' => ['enabled' => true, 'action' => 'account/register', 'min_score' => null, 'strict_hostname' => null],
        'newsletter' => ['enabled' => true, 'action' => 'newsletter', 'min_score' => null, 'strict_hostname' => null],
        'comment' => ['enabled' => true, 'action' => 'product/review', 'min_score' => null, 'strict_hostname' => null],
    ],
]);

$contactPath = '/contact-us';
$registrationPath = '/registration';
$fakeSecret = 'selest-fake-secret-key-000000000000';

$h->section('The shop renders the captcha configuration');
$client->reset();
$page = $client->request($contactPath);
$h->lastBody = $page['body'];
$h->check('contact page answers 200', $page['status'] === 200, (string) $page['status']);
// Hummingbird (9.x) ships one bundled script and inline styles, Classic (8.x)
// links the module files directly: the check has to accept both shapes.
$h->check('page carries the captcha configuration', str_contains($page['body'], 'selestRecaptchaConfig'));

$script = $client->request('/modules/selestrecaptcha/views/js/selestrecaptcha.js');
$h->check(
    'the front script is served',
    $script['status'] === 200 && str_contains($script['body'], 'srRecaptchaReady'),
    'HTTP ' . $script['status']
);

$stylesheet = $client->request('/modules/selestrecaptcha/views/css/selestrecaptcha.css');
$h->check(
    'the front stylesheet is served',
    $stylesheet['status'] === 200 && str_contains($stylesheet['body'], 'selestrecaptcha-alert'),
    'HTTP ' . $stylesheet['status']
);

$pageScript = str_contains($page['body'], 'modules/selestrecaptcha/views/js/selestrecaptcha.js');
if (!$pageScript && preg_match_all('/src="([^"]*?\.js)"/', $page['body'], $bundles)) {
    foreach (array_unique($bundles[1]) as $bundle) {
        $path = (string) parse_url((string) $bundle, PHP_URL_PATH);
        if ($path !== '' && str_contains($client->request($path)['body'], 'srRecaptchaReady')) {
            $pageScript = true;
            break;
        }
    }
}

$h->check('the front script reaches the page, directly or through the theme bundle', $pageScript);
$h->check('configuration names the site key', str_contains($page['body'], 'site-key-for-the-test-shop'));
$h->check('the secret key is not in the page', !str_contains($page['body'], $fakeSecret));
$h->check('the verification endpoint is not in the page', !str_contains($page['body'], '18093/siteverify'));

/**
 * The contact form's anti-spam token is rendered in the page and checked
 * server-side; a submission without it is discarded by the contact module
 * itself, whatever the captcha says.
 */
// Every submission carries its own address: PrestaShop reuses the customer
// thread of a known sender and refuses a second registration for the same
// email, so a fixed address would make "was it stored?" a meaningless question.
$runId = substr(bin2hex(random_bytes(4)), 0, 8);
$contactCounter = 0;

$contactPage = static function () use ($client, $contactPath, &$contactHtml): string {
    $contactHtml = $client->request($contactPath)['body'];

    return $contactHtml;
};

$contactForm = static function (array $extra) use ($client, $contactPath, &$contactHtml, &$contactCounter, $runId): array {
    // Every submission is preceded by a page load, exactly like a visitor:
    // the contact form's own token is regenerated as soon as it is stale, and
    // a stale one makes the shop drop the message for its own reasons.
    $contactHtml = $client->request($contactPath)['body'];
    preg_match('/name="token" value="([^"]+)"/', $contactHtml, $m);
    ++$contactCounter;

    return array_merge([
        'submitMessage' => 'Submit message',
        'id_contact' => '1',
        'from' => sprintf('visitor-%s-%d@example.test', $runId, $contactCounter),
        'message' => sprintf('Hello, I would like to know about your products (%d).', $contactCounter),
        'url' => '',
        'token' => $m[1] ?? '',
    ], $extra);
};

$h->section('A submission without a token never reaches the database');
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$blocked = $client->request($contactPath, $contactForm([]));
$h->lastBody = $blocked['body'];
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('no customer thread was created', $before === $after, sprintf('%d -> %d', $before, $after));
$h->check(
    'the visitor is told why (flash carried in the page)',
    str_contains($blocked['body'], 'Please complete the captcha'),
    'no message rendered'
);
$h->check(
    'the re-rendered form keeps what the visitor typed',
    str_contains($blocked['body'], 'I would like to know about your products'),
    'the message field came back empty'
);

$h->section('A bot token is refused');
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$client->request($contactPath, $contactForm(['grecaptcha-response' => 'bot-token']));
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('no customer thread was created', $before === $after, sprintf('%d -> %d', $before, $after));

$h->section('A replayed token is refused');
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->lastBody = $client->request($contactPath, $contactForm(['grecaptcha-response' => 'valid-token']))['body'];
$mid = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('the first use is accepted', $mid === $before + 1, sprintf('%d -> %d', $before, $mid));

$client->request($contactPath, $contactForm(['grecaptcha-response' => 'valid-token']));
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('the second use of the same token is refused', $after === $mid, sprintf('%d -> %d', $mid, $after));

$h->section('A valid token goes through');
$client->reset();
$contactPage();
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->lastBody = $client->request($contactPath, $contactForm(['grecaptcha-response' => 'valid-token-ok']))['body'];
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('the message was stored', $after === $before + 1, sprintf('%d -> %d', $before, $after));

$h->section('Registration');

// PS 8.2 registration also asks for a social title, a birthdate and the
// privacy consent; a submission missing one of them is dropped by the shop,
// whatever the captcha decided.
$registrationForm = [
    'submitCreate' => '1',
    'id_gender' => '1',
    'firstname' => 'Visitor',
    'lastname' => 'Tester',
    'password' => 'VisitorPassw0rd!2026',
    'birthday' => '1990-01-01',
    'psgdpr' => '1',
    // PS 8.2 makes the privacy policy acceptance a mandatory field.
    'customer_privacy' => '1',
];

$client->reset();
$client->request($registrationPath);
$before = $h->count('SELECT COUNT(*) FROM ps_customer');
$client->request($registrationPath, array_merge($registrationForm, [
    'email' => sprintf('bot-no-token-%s@example.test', $runId),
]));
$after = $h->count('SELECT COUNT(*) FROM ps_customer');
$h->check('no account was created without a token', $before === $after, sprintf('%d -> %d', $before, $after));

$h->lastBody = $client->request($registrationPath, array_merge($registrationForm, [
    'email' => sprintf('real-visitor-%s@example.test', $runId),
    'grecaptcha-response' => 'valid-token-register',
]))['body'];
$after = $h->count('SELECT COUNT(*) FROM ps_customer');
$h->check('the account was created with a valid token', $after === $before + 1, sprintf('%d -> %d', $before, $after));

$h->section('Newsletter');
$client->reset();
$home = $client->request('/');
$h->check('the home page renders a newsletter form', str_contains($home['body'], 'submitNewsletter'));

$before = $h->count('SELECT COUNT(*) FROM ps_emailsubscription');
$newsletterForm = [
    'submitNewsletter' => 'ok',
    'blockHookName' => 'displayFooterBefore',
    'action' => '0',
];

$client->request('/', array_merge($newsletterForm, [
    'email' => sprintf('spam-no-token-%s@example.test', $runId),
]));
$after = $h->count('SELECT COUNT(*) FROM ps_emailsubscription');
$h->check('no subscriber was added without a token', $before === $after, sprintf('%d -> %d', $before, $after));

$h->lastBody = $client->request('/', array_merge($newsletterForm, [
    'email' => sprintf('subscriber-ok-%s@example.test', $runId),
    'grecaptcha-response' => 'valid-token-newsletter',
]))['body'];
$after = $h->count('SELECT COUNT(*) FROM ps_emailsubscription');
$h->check('the subscriber was added with a valid token', $after === $before + 1, sprintf('%d -> %d', $before, $after));

$h->section('Product reviews (XHR endpoint)');
$client->reset();
$client->request('/');
$response = $client->request('/index.php?fc=module&module=productcomments&controller=PostComment', [
    'id_product' => '1',
    'comment_title' => 'Great',
    'comment_content' => 'buy cheap watches here',
    'customer_name' => 'Spammer',
    'criterion' => ['1' => '3'],
    'grecaptcha-response' => 'bot-token',
], ['X-Requested-With: XMLHttpRequest']);

$decoded = json_decode($response['body'], true);
$h->check('the endpoint answers JSON', is_array($decoded), substr($response['body'], 0, 120));
$h->check(
    'the review is refused with our message',
    is_array($decoded) && ($decoded['success'] ?? true) === false
        && str_contains(json_encode($decoded), 'captcha'),
    $response['body']
);

$response = $client->request('/index.php?fc=module&module=productcomments&controller=PostComment', [
    'id_product' => '1',
    'comment_title' => 'Great',
    'comment_content' => 'A genuinely useful review.',
    'customer_name' => 'Visitor',
    'criterion' => ['1' => '3'],
    'grecaptcha-response' => 'valid-token-review',
], ['X-Requested-With: XMLHttpRequest']);

$decoded = json_decode($response['body'], true);
$h->check(
    'a valid token is not answered with our refusal',
    is_array($decoded) && !str_contains(json_encode($decoded), 'captcha'),
    $response['body']
);

$h->section('v3 scoring');
$h->configure(['version' => 'v3']);

$client->reset();
$contactPage();
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$client->request($contactPath, $contactForm(['grecaptcha-response' => 'v3-0.1-contact']));
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('a low score is refused', $before === $after, sprintf('%d -> %d', $before, $after));

$lowPage = $client->request($contactPath);
$h->check(
    'the score message is shown',
    str_contains($lowPage['body'], 'flagged as automated')
);

$client->reset();
$contactPage();
$client->request($contactPath, $contactForm(['grecaptcha-response' => 'v3-0.9-contact']));
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('a high score goes through', $after === $before + 1, sprintf('%d -> %d', $before, $after));

$h->configure(['strict_hostname' => true]);

$client->reset();
$contactPage();
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->lastBody = $client->request($contactPath, $contactForm(['grecaptcha-response' => 'v3-0.9-contact-elsewhere']))['body'];
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check(
    'a token solved on another hostname is refused when the check is on',
    $before === $after,
    sprintf('%d -> %d', $before, $after)
);

$h->configure(['strict_hostname' => false]);
$client->reset();
$contactPage();
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$client->request($contactPath, $contactForm(['grecaptcha-response' => 'v3-0.95-contact-elsewhere']));
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check(
    'and accepted when the merchant leaves the check off',
    $after === $before + 1,
    sprintf('%d -> %d', $before, $after)
);

$h->section('Google being unreachable');
$h->configure(['version' => 'v2_checkbox', 'endpoint' => 'http://127.0.0.1:1/siteverify', 'fail_mode' => 'closed']);

$client->reset();
$contactPage();
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$client->request($contactPath, $contactForm(['grecaptcha-response' => 'any-token']));
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('fail-closed refuses the submission', $before === $after, sprintf('%d -> %d', $before, $after));

$h->configure(['fail_mode' => 'open']);
$client->reset();
$contactPage();
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$open = $client->request($contactPath, $contactForm(['grecaptcha-response' => 'any-token']));
$h->lastBody = $open['body'];
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check(
    'fail-open lets the submission through',
    $after === $before + 1,
    sprintf(
        '%d -> %d (http %d, flash %s, sent %s)',
        $before,
        $after,
        $open['status'],
        str_contains($open['body'], '"flash"') ? 'shown' : 'none',
        str_contains($open['body'], 'successfully sent to our team') ? 'yes' : 'no'
    )
);

$h->section('A disabled target is not protected');
$h->configure(['endpoint' => 'http://127.0.0.1:18093/siteverify', 'fail_mode' => 'closed', 'targets' => [
    'contact' => ['enabled' => true],
    'registration' => ['enabled' => false],
    'newsletter' => ['enabled' => false],
    'comment' => ['enabled' => false],
]]);

$client->reset();
$client->request($registrationPath);
$before = $h->count('SELECT COUNT(*) FROM ps_customer');
$h->lastBody = $client->request($registrationPath, array_merge($registrationForm, [
    'email' => sprintf('nocaptcha-%s@example.test', $runId),
]))['body'];
$after = $h->count('SELECT COUNT(*) FROM ps_customer');
$h->check('the account was created although no captcha was sent', $after === $before + 1, sprintf('%d -> %d', $before, $after));

$h->section('The whole module switched off');
$h->configure(['enabled' => false, 'targets' => [
    'contact' => ['enabled' => true],
    'registration' => ['enabled' => true],
    'newsletter' => ['enabled' => true],
    'comment' => ['enabled' => true],
]]);

$client->reset();
$contactPage();
$before = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->lastBody = $client->request($contactPath, $contactForm([]))['body'];
$after = $h->count('SELECT COUNT(*) FROM ps_customer_message');
$h->check('messages go through untouched', $after === $before + 1, sprintf('%d -> %d', $before, $after));

$page = $client->request($contactPath);
$h->check('no captcha script is injected when the module is off', !str_contains($page['body'], 'selestrecaptchaConfig'));

exit($h->summary());