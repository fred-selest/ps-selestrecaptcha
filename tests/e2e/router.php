<?php
/**
 * Front controller router for `php -S`, emulating the rewrite rules of a real
 * shop so the end-to-end tests hit the same entry points a visitor does.
 *
 *   php -S 127.0.0.1:18080 tests/e2e/router.php   (from the shop root)
 */
$root = rtrim((string) ($_ENV['PS_ROOT'] ?? getcwd()), '/');
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $root . $path;

// Existing file: serve it as PHP when it is a script, otherwise let the
// built-in server stream it (CSS, JS, images).
if ($path !== '/' && is_file($file)) {
    if (str_ends_with($file, '.php')) {
        $_SERVER['SCRIPT_NAME'] = $path;
        $_SERVER['SCRIPT_FILENAME'] = $file;

        require $file;

        return true;
    }

    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';

require $root . '/index.php';

return true;
