<?php
// Router for the PHP built-in dev server (`php -S ... dev/router.php`).
// The built-in server ignores .htaccess, so this emulates the protection rules
// from .htaccess and uploads/.htaccess for local testing. Never used in production.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$path = strtolower('/' . ltrim(str_replace('\\', '/', $path), '/'));
// Windows resolves "config.php." / "config.php " / "config.php::$DATA" to the
// same file, so normalise those variants before matching.
$path = str_replace('::$data', '', $path);
$path = preg_replace('#[. ]+(?=/|$)#', '', $path);

$blocked = preg_match('#/\.#', $path)          // dotfiles/-dirs: .git, .htaccess, .devdata
    || str_ends_with($path, '.json')           // JSON data stores
    || $path === '/config.php'
    || $path === '/uploads' || str_starts_with($path, '/uploads/')
    || $path === '/dev' || str_starts_with($path, '/dev/');

if ($blocked) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden (dev router)\n";
    return true;
}

return false; // let the built-in server handle it (index.php is the fallback)
