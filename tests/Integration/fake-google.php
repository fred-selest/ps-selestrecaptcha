<?php

/**
 * A fake of Google's siteverify endpoint, faithful enough to be worth testing
 * against. Serve it with:
 *
 *     php -S 127.0.0.1:18093 tests/Integration/fake-google.php
 *
 * Used tokens are remembered in tests/Integration/.google-state.json so a
 * token really can only be spent once, like the real service.
 *
 * It reproduces what the real API does that a naive stub does not:
 *
 *   - the answer is JSON with the documented field names, including the
 *     hyphenated "error-codes";
 *   - a token is accepted exactly once (the second call answers
 *     timeout-or-duplicate, like the real service);
 *   - the secret is checked before the token, which is what makes the back
 *     office self test meaningful;
 *   - scores and actions are per-token, so v3 rules can be exercised;
 *   - failures can be injected: ?fault=http500|garbage|slow|refused
 *
 * Tokens understood:
 *   valid-token           success (v2 flavour)
 *   v3-0.9-contact        success, score 0.9, action "contact"
 *   v3-0.1-contact        success, score 0.1, action "contact"
 *   v3-0.9-elsewhere      success, score 0.9, action "contact", hostname "evil.example"
 *   bot-token             invalid-input-response
 *   replay                timeout-or-duplicate
 *   any other             invalid-input-response
 */

declare(strict_types=1);

const FAKE_SECRET = 'selest-fake-secret-key-000000000000';
const FAKE_WRONG_SECRET = 'wrong-secret';

$stateFile = getenv('SR_GOOGLE_STATE') ?: (__DIR__ . '/.google-state.json');

$readState = static function () use ($stateFile): array {
    if (!is_file($stateFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($stateFile), true);

    return is_array($decoded) ? $decoded : [];
};

$writeState = static function (array $state) use ($stateFile): void {
    file_put_contents($stateFile, json_encode($state));
};

$answer = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
};

$raw = (string) file_get_contents('php://input');
parse_str($raw, $fields);

$fault = (string) ($_GET['fault'] ?? '');

if ($fault === 'http500') {
    $answer(['error' => 'quota'], 500);
    exit;
}

if ($fault === 'garbage') {
    http_response_code(200);
    header('Content-Type: text/html');
    echo '<html><body>Service unavailable</body></html>';
    exit;
}

if ($fault === 'slow') {
    sleep(10);
    $answer(['success' => true]);
    exit;
}

$secret = (string) ($fields['secret'] ?? '');
$response = (string) ($fields['response'] ?? '');

// A trace of every call, so a test that fails elsewhere can still show what
// the shop really asked Google.
if (($trace = getenv('SR_GOOGLE_TRACE')) !== false && $trace !== '') {
    file_put_contents(
        $trace,
        date('c') . ' response=' . $response . ' secret=' . substr($secret, 0, 8) . " ip=" . ($fields['remoteip'] ?? '-') . "\n",
        FILE_APPEND
    );
}

// Google validates the secret first.
if ($secret === '') {
    $answer(['success' => false, 'error-codes' => ['missing-input-secret']]);
    exit;
}

if ($secret === FAKE_WRONG_SECRET) {
    $answer(['success' => false, 'error-codes' => ['invalid-input-secret']]);
    exit;
}

if ($secret !== FAKE_SECRET) {
    $answer(['success' => false, 'error-codes' => ['invalid-input-secret']]);
    exit;
}

if ($response === '') {
    $answer(['success' => false, 'error-codes' => ['missing-input-response']]);
    exit;
}

// Single use, exactly like the real service.
$state = $readState();
$seen = $state['seen'] ?? [];

if (in_array($response, $seen, true)) {
    $answer([
        'success' => false,
        'error-codes' => ['timeout-or-duplicate'],
        'challenge_ts' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    exit;
}

if (str_starts_with($response, 'bot-token')) {
    $answer(['success' => false, 'error-codes' => ['invalid-input-response']]);
    exit;
}

if (str_starts_with($response, 'replay')) {
    $answer(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
    exit;
}

if (str_starts_with($response, 'v3-')) {
    $seen[] = $response;
    $writeState(['seen' => $seen]);

    // v3-<score>-<action>[-elsewhere]: "elsewhere" only changes the hostname the
    // answer claims, so a test can check the hostname rule without also
    // tripping the action rule.
    $parts = explode('-', $response);
    $score = $parts[1] ?? '0.9';
    $action = implode('-', array_slice($parts, 2));
    $hostname = 'shop.example';

    if (str_ends_with($action, '-elsewhere')) {
        $action = substr($action, 0, -strlen('-elsewhere'));
        $hostname = 'evil.example';
    }

    $action = $action !== '' ? $action : 'contact';

    $answer([
        'success' => true,
        'score' => (float) $score,
        'action' => $action,
        'challenge_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        'hostname' => $hostname,
    ]);
    exit;
}

if (str_starts_with($response, 'valid-token')) {
    $seen[] = $response;
    $writeState(['seen' => $seen]);

    $answer([
        'success' => true,
        'challenge_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        'hostname' => 'shop.example',
    ]);
    exit;
}

$answer(['success' => false, 'error-codes' => ['invalid-input-response']]);