<?php
// Filled in by `git archive` (export-subst in .gitattributes) when a release is
// built; a plain checkout (or an archive without tag) shows "dev".
$appVersion = '$Format:%(describe:tags=true)$';
define('APP_VERSION', $appVersion === '' || $appVersion[0] === '$' ? 'dev' : $appVersion);

// ── Config ──────────────────────────────────────────────────────────────────
$defaultConfig = [
    'pin'            => getenv('CLIPBOARD_PIN') ?: '',
    'pin_hash'       => '',      // password_hash() of the password, written by setup.php; wins over 'pin'
    'data_file'      => __DIR__ . '/data.json',
    'files_file'     => __DIR__ . '/files.json',
    'upload_dir'     => __DIR__ . '/uploads',
    'max_entries'    => 50,
    'file_ttl_days'  => 7,       // uploaded files auto-delete after this many days
    'confirm_delete' => false,   // true = ask before deleting single entries/files
    'session_name'   => 'clipboard_session',
    'login_max_attempts'   => 5,    // failed logins per IP before a lockout
    'login_lockout_minutes' => 15,
];

$localConfigFile = __DIR__ . '/config.php';

// Security headers for every response (app and setup). The app loads nothing
// from other origins; inline script/style is needed by the single-file layout.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; "
    . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// Session cookie: only for this app's directory, never readable by JS, HTTPS-only
// when served over HTTPS, and not sent along with cross-site subrequests/POSTs.
define('IS_HTTPS', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
    'secure'   => IS_HTTPS,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// First run: without config.php and CLIPBOARD_PIN, show the setup instead of the
// app. setup.php is the only pre-auth action and locks itself once config.php exists.
if (!is_file($localConfigFile) && (string)getenv('CLIPBOARD_PIN') === '' && is_file(__DIR__ . '/setup.php')) {
    define('CLIPBOARD_APP', true);
    require __DIR__ . '/setup.php';
    runSetup(__DIR__, $localConfigFile);
    exit;
}

$localConfig = is_file($localConfigFile) ? require $localConfigFile : [];
$config = is_array($localConfig) ? array_replace($defaultConfig, $localConfig) : $defaultConfig;

define('PIN', (string)$config['pin']);
define('PIN_HASH', (string)$config['pin_hash']);
define('DATA_FILE', (string)$config['data_file']);
define('FILES_FILE', (string)$config['files_file']);
define('UPLOAD_DIR', (string)$config['upload_dir']);
define('MAX_ENTRIES', (int)$config['max_entries']);
define('FILE_TTL_DAYS', (int)$config['file_ttl_days']);
define('CONFIRM_DELETE', (bool)$config['confirm_delete']);
define('SESSION_NAME', (string)$config['session_name']);
define('LOGIN_MAX_ATTEMPTS', max(1, (int)$config['login_max_attempts']));
define('LOGIN_LOCKOUT', max(1, (int)$config['login_lockout_minutes']) * 60);
define('ATTEMPTS_FILE', dirname(DATA_FILE) . '/login-attempts.json');
define('TOKENS_FILE', dirname(DATA_FILE) . '/api-tokens.json');

// An empty PIN or one of the documented placeholders must never grant access,
// otherwise a fresh deployment without config.php would be open to anyone.
define('PIN_CONFIGURED', PIN_HASH !== ''
    || (PIN !== '' && !in_array(PIN, ['change-me', 'replace-with-a-long-random-pin'], true)));

// Sessions store a fingerprint of the current secret instead of a plain flag,
// so changing the password (e.g. re-running the setup) logs out every session.
define('AUTH_TOKEN', hash('sha256', 'clipboard-auth|' . (PIN_HASH !== '' ? PIN_HASH : PIN)));

function checkPin(string $input): bool {
    return PIN_HASH !== '' ? password_verify($input, PIN_HASH) : hash_equals(PIN, $input);
}

// Login throttling: failed attempts per client IP (stored hashed) within the
// lockout window. Returns the seconds until the next attempt is allowed (0 = now).
// $record: null = only check, true = success (reset), false = failure (count).
// Behind a proxy that does not pass the client IP on, all clients share one counter.
function loginThrottle(?bool $record = null): int {
    $key = hash('sha256', 'clipboard-login|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $fh = @fopen(ATTEMPTS_FILE, 'c+');
    if ($fh === false) return 0; // not writable: fail open rather than lock everyone out
    flock($fh, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fh), true);
    $all = is_array($all) ? $all : [];
    $now = time();
    foreach ($all as $k => $times) {
        $all[$k] = array_values(array_filter((array)$times, fn($t) => is_int($t) && $t > $now - LOGIN_LOCKOUT));
        if (!$all[$k]) unset($all[$k]);
    }
    if ($record === true) unset($all[$key]);
    if ($record === false) $all[$key][] = $now;
    $mine = $all[$key] ?? [];
    $wait = count($mine) >= LOGIN_MAX_ATTEMPTS ? min($mine) + LOGIN_LOCKOUT - $now : 0;
    if ($record !== null) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($all));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return max(0, $wait);
}

// ── REST API ─────────────────────────────────────────────────────────────────
// Requests with ?api=… are answered by runApi() (see below) and never touch the
// session: only an API token authorises them, a session cookie does not.
if (isset($_GET['api'])) {
    runApi();
    exit;
}

// ── Session / Auth ───────────────────────────────────────────────────────────
session_name(SESSION_NAME);
session_start();

$authenticated = PIN_CONFIGURED && is_string($_SESSION['auth'] ?? null) && hash_equals(AUTH_TOKEN, $_SESSION['auth']);

if (!PIN_CONFIGURED) {
    $loginError = 'Kein PIN konfiguriert. Bitte in config.php (oder per CLIPBOARD_PIN) einen eigenen PIN setzen.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    if (($wait = loginThrottle()) > 0) {
        $loginError = 'Zu viele Fehlversuche. Bitte in ' . (int)ceil($wait / 60) . ' Minute(n) erneut versuchen.';
    } elseif (checkPin((string)$_POST['pin'])) {
        loginThrottle(true);
        session_regenerate_id(true);
        $_SESSION['auth'] = AUTH_TOKEN;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $authenticated = true;
    } else {
        $wait = loginThrottle(false);
        $loginError = $wait > 0
            ? 'Falscher PIN. Zu viele Fehlversuche – Login für ' . (int)ceil($wait / 60) . ' Minute(n) gesperrt.'
            : 'Falscher PIN.';
    }
}

// CSRF protection: every state-changing request of a logged-in user (any POST,
// and the delete/logout links) must carry the session's token.
if ($authenticated && empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
define('CSRF_TOKEN', (string)($_SESSION['csrf'] ?? ''));
$changesState = ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['pin']))
    || isset($_GET['delete']) || isset($_GET['delfile']) || isset($_GET['clearall']) || isset($_GET['logout'])
    || isset($_GET['deltoken']);
if ($authenticated && $changesState) {
    $sent = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(CSRF_TOKEN, $sent)) {
        http_response_code(403);
        if (isset($_POST['chunked']) || isset($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token. Bitte die Seite neu laden.']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Ungültiges Sicherheits-Token. Bitte die Seite neu laden.\n";
        }
        exit;
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// A new API token is shown exactly once: create it, keep it in the session for
// the redirect (so a reload does not create another one) and drop it on display.
$newToken = '';
if ($authenticated) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newtoken'])) {
        $_SESSION['new_token'] = createToken((string)($_POST['token_name'] ?? ''));
        header('Location: index.php#api');
        exit;
    }
    $newToken = (string)($_SESSION['new_token'] ?? '');
    unset($_SESSION['new_token']);
}

// Release the session lock now: auth is resolved and nothing below writes to
// the session. Without this, a long upload holds the lock and blocks the
// concurrent auto-refresh poll (which can disrupt the upload).
session_write_close();

// ── Data helpers ─────────────────────────────────────────────────────────────
function loadEntries(): array {
    if (!file_exists(DATA_FILE)) return [];
    $data = json_decode(file_get_contents(DATA_FILE), true);
    return is_array($data) ? $data : [];
}

function saveEntries(array $entries): void {
    file_put_contents(DATA_FILE, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function loadFiles(): array {
    if (!file_exists(FILES_FILE)) return [];
    $data = json_decode(file_get_contents(FILES_FILE), true);
    return is_array($data) ? $data : [];
}

function saveFiles(array $files): void {
    file_put_contents(FILES_FILE, json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function humanSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = $bytes;
    while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
    return ($i === 0 ? $n : round($n, 1)) . ' ' . $units[$i];
}

// Remove files older than FILE_TTL_DAYS (and their stored blobs).
function cleanupFiles(): void {
    $files = loadFiles();
    $cutoff = time() - FILE_TTL_DAYS * 86400;
    $kept = [];
    $changed = false;
    foreach ($files as $f) {
        if ($f['ts'] < $cutoff) {
            @unlink(UPLOAD_DIR . '/' . $f['stored']);
            $changed = true;
        } else {
            $kept[] = $f;
        }
    }
    if ($changed) saveFiles($kept);

    // Sweep temp dirs left behind by aborted chunked uploads (older than 1 day)
    foreach (glob(UPLOAD_DIR . '/.tmp_*') ?: [] as $dir) {
        if (is_dir($dir) && filemtime($dir) < time() - 86400) {
            foreach (glob($dir . '/*') ?: [] as $p) @unlink($p);
            @rmdir($dir);
        }
    }
}

// Remove EVERYTHING under uploads/ (stored blobs, orphaned files and temp
// dirs from aborted uploads) but keep the protective .htaccess. Returns the
// number of leftover items that could not be deleted (0 = fully cleaned).
function purgeUploads(): int {
    if (!is_dir(UPLOAD_DIR)) return 0;
    $remaining = 0;
    foreach (scandir(UPLOAD_DIR) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess') continue;
        $path = UPLOAD_DIR . '/' . $entry;
        if (is_dir($path)) {
            foreach (glob($path . '/*') ?: [] as $p) { if (!@unlink($p)) $remaining++; }
            if (!@rmdir($path)) $remaining++;
        } else {
            if (!@unlink($path)) $remaining++;
        }
    }
    return $remaining;
}

// ── Clipboard operations (shared by the web UI and the API) ─────────────────
function addEntry(string $text): ?array {
    $text = trim($text);
    if ($text === '') return null;
    $entry = ['id' => uniqid('', true), 'text' => $text, 'ts' => time()];
    $entries = loadEntries();
    array_unshift($entries, $entry);
    saveEntries(array_slice($entries, 0, MAX_ENTRIES));
    return $entry;
}

function deleteEntry(string $id): bool {
    $entries = loadEntries();
    $kept = array_values(array_filter($entries, fn($e) => $e['id'] !== $id));
    saveEntries($kept);
    return count($kept) < count($entries);
}

function findFile(string $id): ?array {
    foreach (loadFiles() as $f) { if ($f['id'] === $id) return $f; }
    return null;
}

// Register an already stored blob (random name under UPLOAD_DIR) in the index.
function addFile(string $stored, string $name): array {
    $file = [
        'id'     => uniqid('', true),
        'name'   => basename($name) !== '' ? basename($name) : 'upload.bin',
        'stored' => $stored,
        'size'   => filesize(UPLOAD_DIR . '/' . $stored),
        'ts'     => time(),
    ];
    $files = loadFiles();
    array_unshift($files, $file);
    saveFiles($files);
    return $file;
}

// Move a file from a regular (non-chunked) upload into storage.
function storeUpload(string $tmpName, string $name): ?array {
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
    $stored = bin2hex(random_bytes(16));
    return move_uploaded_file($tmpName, UPLOAD_DIR . '/' . $stored) ? addFile($stored, $name) : null;
}

// Chunked upload: receive one piece at a time, assemble on the final chunk.
// Keeps every request small so server/proxy size limits are never hit.
// Returns [HTTP status, JSON response].
function receiveChunk(string $uploadId, int $index, int $total, string $name, ?array $chunk): array {
    $uploadId = preg_replace('/[^a-f0-9]/', '', $uploadId);
    if ($uploadId === '' || $index < 0 || $total < 1 || $index >= $total
        || !$chunk || ($chunk['error'] ?? null) !== UPLOAD_ERR_OK) {
        return [400, ['ok' => false, 'error' => 'Ungültiger Chunk.']];
    }

    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
    $tmpDir = UPLOAD_DIR . '/.tmp_' . $uploadId;
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

    if (!move_uploaded_file($chunk['tmp_name'], $tmpDir . '/' . $index)) {
        return [500, ['ok' => false, 'error' => 'Chunk konnte nicht gespeichert werden.']];
    }

    // Not the last chunk → acknowledge and wait for the next one
    if ($index < $total - 1) return [200, ['ok' => true, 'done' => false]];

    // Final chunk → concatenate all parts in order into the stored file
    $stored = bin2hex(random_bytes(16));
    $out = fopen(UPLOAD_DIR . '/' . $stored, 'wb');
    for ($i = 0; $i < $total; $i++) {
        $part = $tmpDir . '/' . $i;
        if (!is_file($part)) {
            fclose($out);
            @unlink(UPLOAD_DIR . '/' . $stored);
            return [400, ['ok' => false, 'error' => 'Fehlender Chunk ' . $i . '.']];
        }
        $in = fopen($part, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        @unlink($part);
    }
    fclose($out);
    @rmdir($tmpDir);

    return [200, ['ok' => true, 'done' => true, 'file' => addFile($stored, $name)]];
}

function deleteFile(string $id): bool {
    $files = loadFiles();
    $kept = [];
    foreach ($files as $f) {
        if ($f['id'] === $id) { @unlink(UPLOAD_DIR . '/' . $f['stored']); }
        else { $kept[] = $f; }
    }
    saveFiles($kept);
    return count($kept) < count($files);
}

// Clear all: texts, file index and every blob under uploads/ (incl. orphans).
// Returns the number of leftover items that could not be deleted.
function clearAll(): int {
    saveEntries([]);
    saveFiles([]);
    return purgeUploads();
}

// Stream a stored file (always through PHP, so the PIN or token protects it).
function sendFile(array $f): void {
    $path = UPLOAD_DIR . '/' . $f['stored'];
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . addslashes($f['name']) . '"');
    header('Cache-Control: no-store');
    readfile($path);
}

// ── API tokens ───────────────────────────────────────────────────────────────
// Tokens are random (192 bit) and only their SHA-256 hash is stored, next to
// data.json in api-tokens.json. The plain token is shown once after creation.
function loadTokens(): array {
    if (!file_exists(TOKENS_FILE)) return [];
    $data = json_decode(file_get_contents(TOKENS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveTokens(array $tokens): void {
    file_put_contents(TOKENS_FILE, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function createToken(string $name): string {
    $plain = 'cbt_' . bin2hex(random_bytes(24));
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $tokens = loadTokens();
    $tokens[] = [
        'id'        => bin2hex(random_bytes(8)),
        'name'      => $name !== '' ? (preg_match('/^.{0,60}/u', $name, $m) ? $m[0] : 'API-Token') : 'API-Token',
        'hash'      => hash('sha256', $plain),
        'hint'      => substr($plain, 0, 10) . '…',
        'created'   => time(),
        'last_used' => 0,
    ];
    saveTokens($tokens);
    return $plain;
}

function deleteToken(string $id): void {
    saveTokens(array_values(array_filter(loadTokens(), fn($t) => $t['id'] !== $id)));
}

// Look up a plain token; remembers when it was last used (at most once a minute).
function findToken(string $plain): ?array {
    $hash = hash('sha256', $plain);
    $tokens = loadTokens();
    foreach ($tokens as $i => $t) {
        if (is_string($t['hash'] ?? null) && hash_equals($t['hash'], $hash)) {
            if (time() - (int)$t['last_used'] > 60) {
                $tokens[$i]['last_used'] = time();
                saveTokens($tokens);
            }
            return $t;
        }
    }
    return null;
}

// Token from "X-Clipboard-Token: …" or "Authorization: Bearer …". Never read
// from the URL, where it would end up in logs and browser history.
function apiTokenFromRequest(): string {
    $token = (string)($_SERVER['HTTP_X_CLIPBOARD_TOKEN'] ?? '');
    if ($token === '') {
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($auth === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $auth = (string)$v; }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) $token = $m[1];
    }
    return trim($token);
}

function apiReply(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(int $status, string $message): void {
    apiReply($status, ['ok' => false, 'error' => $message]);
}

function apiEntry(array $e): array {
    return ['id' => $e['id'], 'text' => $e['text'], 'ts' => $e['ts'], 'date' => date('c', $e['ts'])];
}

function apiFile(array $f): array {
    return ['id' => $f['id'], 'name' => $f['name'], 'size' => $f['size'], 'ts' => $f['ts'], 'date' => date('c', $f['ts'])];
}

// Text from a JSON body {"text": "…"}, a form field "text" or a text/plain body.
function apiTextFromRequest(): ?string {
    $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_starts_with($type, 'application/json')) {
        $body = json_decode((string)file_get_contents('php://input'), true);
        return is_array($body) && is_string($body['text'] ?? null) ? $body['text'] : null;
    }
    if (isset($_POST['text'])) return is_string($_POST['text']) ? $_POST['text'] : null;
    if (str_starts_with($type, 'text/plain')) return (string)file_get_contents('php://input');
    return null;
}

// ?api=ping|texts|files|all[&id=…]. Every call needs a valid token; failed
// attempts count towards the same per-IP lockout as wrong PINs.
function runApi(): void {
    $method = $_SERVER['REQUEST_METHOD'];
    // For clients or hosters that cannot send DELETE: POST …&_method=DELETE
    if ($method === 'POST' && strtoupper((string)($_GET['_method'] ?? '')) === 'DELETE') $method = 'DELETE';

    if (!PIN_CONFIGURED) apiError(503, 'Clipboard ist noch nicht eingerichtet.');
    $plain = apiTokenFromRequest();
    if ($plain === '') apiError(401, 'API-Token fehlt (Header X-Clipboard-Token oder Authorization: Bearer).');
    if (($wait = loginThrottle()) > 0) {
        header('Retry-After: ' . $wait);
        apiError(429, 'Zu viele Fehlversuche. Bitte in ' . (int)ceil($wait / 60) . ' Minute(n) erneut versuchen.');
    }
    $token = findToken($plain);
    if ($token === null) {
        loginThrottle(false);
        apiError(401, 'Ungültiges API-Token.');
    }

    cleanupFiles();
    $resource = (string)$_GET['api'];
    $id = isset($_GET['id']) ? (string)$_GET['id'] : null;

    if ($resource === 'ping' && $method === 'GET') {
        apiReply(200, ['ok' => true, 'version' => APP_VERSION, 'token' => $token['name']]);
    }

    if ($resource === 'texts') {
        if ($method === 'GET' && $id === null) {
            apiReply(200, ['ok' => true, 'texts' => array_map('apiEntry', loadEntries())]);
        }
        if ($method === 'GET') {
            foreach (loadEntries() as $e) { if ($e['id'] === $id) apiReply(200, ['ok' => true, 'text' => apiEntry($e)]); }
            apiError(404, 'Eintrag nicht gefunden.');
        }
        if ($method === 'POST' && $id === null) {
            $text = apiTextFromRequest();
            $entry = $text !== null ? addEntry($text) : null;
            if ($entry === null) apiError(400, 'Kein Text übergeben (JSON {"text": …}, Formularfeld text oder text/plain).');
            apiReply(201, ['ok' => true, 'text' => apiEntry($entry)]);
        }
        if ($method === 'DELETE' && $id !== null) {
            deleteEntry($id) ? apiReply(200, ['ok' => true]) : apiError(404, 'Eintrag nicht gefunden.');
        }
    }

    if ($resource === 'files') {
        if ($method === 'GET' && $id === null) {
            apiReply(200, ['ok' => true, 'files' => array_map('apiFile', loadFiles())]);
        }
        if ($method === 'GET') {
            $f = findFile($id);
            if ($f === null || !is_file(UPLOAD_DIR . '/' . $f['stored'])) apiError(404, 'Datei nicht gefunden.');
            sendFile($f);
            exit;
        }
        if ($method === 'POST' && $id === null) {
            // Same chunk protocol as the browser: uploadId, chunkIndex, totalChunks, fileName, chunk
            if (isset($_FILES['chunk'])) {
                [$status, $res] = receiveChunk((string)($_POST['uploadId'] ?? ''), (int)($_POST['chunkIndex'] ?? -1),
                    (int)($_POST['totalChunks'] ?? 0), (string)($_POST['fileName'] ?? ''), $_FILES['chunk']);
                if (isset($res['file'])) { $res['file'] = apiFile($res['file']); $status = 201; }
                apiReply($status, $res);
            }
            if (!isset($_FILES['file'])) {
                apiError(400, 'Keine Datei übergeben (Formularfeld file). Große Dateien in Chunks senden.');
            }
            // file=@a or file[]=@a&file[]=@b
            $names = (array)$_FILES['file']['name'];
            $tmps  = (array)$_FILES['file']['tmp_name'];
            $stored = [];
            foreach ((array)$_FILES['file']['error'] as $i => $err) {
                if ($err !== UPLOAD_ERR_OK) {
                    apiError(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400,
                        'Upload fehlgeschlagen (evtl. zu groß für die Server-Grenze, dann in Chunks senden).');
                }
                $f = storeUpload((string)$tmps[$i], (string)$names[$i]);
                if ($f === null) apiError(500, 'Datei konnte nicht gespeichert werden.');
                $stored[] = apiFile($f);
            }
            apiReply(201, ['ok' => true, 'files' => $stored]);
        }
        if ($method === 'DELETE' && $id !== null) {
            deleteFile($id) ? apiReply(200, ['ok' => true]) : apiError(404, 'Datei nicht gefunden.');
        }
    }

    if ($resource === 'all' && $method === 'DELETE') {
        $remaining = clearAll();
        $remaining === 0 ? apiReply(200, ['ok' => true])
            : apiError(500, 'Einige Dateien konnten nicht gelöscht werden (Dateirechte prüfen).');
    }

    apiError(in_array($resource, ['ping', 'texts', 'files', 'all'], true) ? 405 : 404,
        'Unbekannte API-Anfrage. Siehe README, Abschnitt „REST-API“.');
}

// ── Actions (only when authenticated) ────────────────────────────────────────
$message = '';
$error   = '';
if ($authenticated) {
    if (isset($_GET['msg']) && $_GET['msg'] === 'up') {
        $message = ((int)($_GET['n'] ?? 0)) . ' Datei(en) hochgeladen.';
    }
    if (isset($_GET['err']) && $_GET['err'] === 'upload') {
        $error = 'Upload fehlgeschlagen (evtl. zu groß für die Server-Grenze).';
    }
    if (isset($_GET['err']) && $_GET['err'] === 'purge') {
        $error = 'Einige Dateien konnten nicht gelöscht werden (Dateirechte prüfen).';
    }
    if (isset($_GET['msg']) && $_GET['msg'] === 'tokendel') {
        $message = 'API-Token widerrufen.';
    }
    cleanupFiles();

    // Download a stored file (streamed through PHP so the PIN protects it)
    if (isset($_GET['download'])) {
        $f = findFile((string)$_GET['download']);
        if ($f && is_file(UPLOAD_DIR . '/' . $f['stored'])) {
            sendFile($f);
        } else {
            http_response_code(404);
            echo 'Datei nicht gefunden.';
        }
        exit;
    }

    // Chunked upload from the browser (see receiveChunk())
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chunked'])) {
        [$status, $res] = receiveChunk((string)($_POST['uploadId'] ?? ''), (int)($_POST['chunkIndex'] ?? -1),
            (int)($_POST['totalChunks'] ?? 0), (string)($_POST['fileName'] ?? ''), $_FILES['chunk'] ?? null);
        unset($res['file']);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($res);
        exit;
    }

    // Upload one or more files (non-JS fallback, single request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload'])) {
        $up = $_FILES['upload'];
        // Normalise to arrays (single vs. multiple)
        $names = is_array($up['name']) ? $up['name'] : [$up['name']];
        $tmps  = is_array($up['tmp_name']) ? $up['tmp_name'] : [$up['tmp_name']];
        $errs  = is_array($up['error']) ? $up['error'] : [$up['error']];
        $count = 0;
        foreach ($names as $i => $origName) {
            if ($errs[$i] === UPLOAD_ERR_NO_FILE) continue;
            if ($errs[$i] !== UPLOAD_ERR_OK) {
                $error = 'Upload fehlgeschlagen (evtl. zu groß für die Server-Grenze).';
                continue;
            }
            if (storeUpload($tmps[$i], $origName)) $count++;
        }

        // AJAX upload → answer with JSON instead of redirecting
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $count > 0, 'count' => $count, 'error' => $error]);
            exit;
        }

        $q = $error ? '?err=upload' : ($count > 0 ? '?msg=up&n=' . $count : '');
        header('Location: index.php' . $q);
        exit;
    }

    // Delete a file
    if (isset($_GET['delfile'])) {
        deleteFile((string)$_GET['delfile']);
        header('Location: index.php');
        exit;
    }

    // Add
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['text'])) {
        if (addEntry((string)$_POST['text'])) $message = 'Gespeichert.';
    }

    // Delete
    if (isset($_GET['delete'])) {
        deleteEntry((string)$_GET['delete']);
        header('Location: index.php');
        exit;
    }

    // Revoke an API token
    if (isset($_GET['deltoken'])) {
        deleteToken((string)$_GET['deltoken']);
        header('Location: index.php?msg=tokendel#api');
        exit;
    }

    // Clear all: texts, file index and every blob under uploads/ (incl. orphans).
    // API tokens stay.
    if (isset($_GET['clearall'])) {
        $remaining = clearAll();
        header('Location: index.php' . ($remaining ? '?err=purge' : ''));
        exit;
    }

    $entries = loadEntries();
    $files   = loadFiles();
    $tokens  = loadTokens();

    if (isset($_GET['json'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode([
            'texts' => array_map(fn($e) => [
                'id'   => $e['id'],
                'text' => $e['text'],
                'date' => date('d.m.Y H:i', $e['ts']),
            ], $entries),
            'files' => array_map(fn($f) => [
                'id'   => $f['id'],
                'name' => $f['name'],
                'size' => humanSize($f['size']),
                'date' => date('d.m.Y H:i', $f['ts']),
            ], loadFiles()),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Output ───────────────────────────────────────────────────────────────────
// Icons (stroke style), shared between PHP markup and JS
$copySvg  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
$trashSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clipboard</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; padding: 1rem; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 1.25rem; margin-bottom: 1rem; max-width: 700px; margin-inline: auto; }
  h1 { font-size: 1.4rem; margin-bottom: 1rem; color: #333; }
  textarea { width: 100%; border: 1px solid #ccc; border-radius: 6px; padding: .6rem; font-size: 1rem; resize: vertical; min-height: 100px; font-family: inherit; }
  input[type=text], input[type=password] { width: 100%; border: 1px solid #ccc; border-radius: 6px; padding: .6rem; font-size: 1rem; }
  .btn { display: inline-block; padding: .5rem 1.1rem; border: none; border-radius: 6px; cursor: pointer; font-size: .95rem; text-decoration: none; }
  .btn-primary   { background: #4a6fa5; color: #fff; }
  .btn-danger    { background: #c0392b; color: #fff; font-size: .8rem; padding: .3rem .7rem; }
  .btn-secondary { background: #aaa;    color: #fff; font-size: .8rem; padding: .3rem .7rem; }
  .btn:hover { opacity: .85; }
  .row { display: flex; gap: .5rem; align-items: flex-start; flex-wrap: wrap; margin-top: .6rem; }
  .entry { position: relative; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: .8rem 1rem; margin-bottom: .75rem; max-width: 700px; margin-inline: auto; }
  .entry-text { white-space: pre-wrap; word-break: break-word; font-size: .95rem; line-height: 1.5; padding-right: 4rem; }
  .entry-text.collapsed { max-height: 9em; overflow: hidden; }
  .entry-meta { font-size: .75rem; color: #888; margin-top: .4rem; display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
  .entry-actions { position: absolute; top: .45rem; right: .45rem; display: flex; gap: .15rem; }
  .icon-btn { display: inline-flex; background: none; border: none; color: #778; cursor: pointer; padding: .35rem; border-radius: 6px; line-height: 0; text-decoration: none; }
  .icon-btn:hover { background: #e8f0fe; color: #2c5282; }
  .icon-btn.copied { color: #2e7d32; }
  .icon-btn-danger:hover { background: #fdecea; color: #c0392b; }
  .expand-btn { display: block; margin: .2rem auto 0; background: none; border: none; color: #999; cursor: pointer; font-size: 1.2rem; letter-spacing: 3px; line-height: .6; padding: .15rem .8rem .35rem; border-radius: 6px; }
  .expand-btn:hover { color: #4a6fa5; background: #eef3fb; }
  .expand-btn[hidden] { display: none; }
  .msg { background: #d4edda; color: #155724; border-radius: 6px; padding: .5rem .9rem; margin-bottom: .75rem; max-width: 700px; margin-inline: auto; font-size: .9rem; }
  .err { background: #f8d7da; color: #721c24; border-radius: 6px; padding: .5rem .9rem; margin-bottom: .75rem; max-width: 700px; margin-inline: auto; font-size: .9rem; }
  .top-bar { max-width: 700px; margin-inline: auto; display: flex; justify-content: space-between; align-items: center; margin-bottom: .5rem; }
  .empty { text-align: center; color: #aaa; padding: 2rem; }
  .version { text-align: center; color: #bbb; font-size: .72rem; margin-top: 1.5rem; }
  .dropzone { border: 2px dashed #b8c4d8; border-radius: 8px; padding: 1rem; text-align: center; color: #7a8aa3; cursor: pointer; transition: background .15s, border-color .15s; }
  .dropzone.dragover { background: #eef3fb; border-color: #4a6fa5; color: #4a6fa5; }
  .file-entry { display: flex; align-items: center; gap: .6rem; }
  .file-icon { font-size: 1.3rem; }
  .file-info { flex: 1; min-width: 0; }
  .file-name { font-weight: 600; font-size: .92rem; word-break: break-word; }
  .file-sub { font-size: .75rem; color: #888; }
  .file-actions { display: flex; gap: .4rem; flex-shrink: 0; }
  .btn-dl { background: #2e7d32; color: #fff; font-size: .8rem; padding: .3rem .7rem; }
  .overlay { position: fixed; inset: 0; background: rgba(20,24,38,.55); display: none; align-items: center; justify-content: center; z-index: 1000; }
  .overlay.show { display: flex; }
  .upload-box { background: #fff; border-radius: 10px; padding: 1.5rem; width: min(420px, 90vw); box-shadow: 0 8px 30px rgba(0,0,0,.3); }
  .upload-box h2 { font-size: 1.05rem; margin-bottom: 1rem; }
  .progress { background: #e6e9ef; border-radius: 6px; height: 16px; overflow: hidden; }
  .progress-bar { background: #4a6fa5; height: 100%; width: 0%; transition: width .15s; }
  .progress-text { font-size: .85rem; color: #555; margin-top: .6rem; display: flex; justify-content: space-between; gap: 1rem; }
  .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #ccc; border-top-color: #4a6fa5; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: -2px; margin-right: .4rem; }
  @keyframes spin { to { transform: rotate(360deg); } }
  .api-card { margin-top: 1.5rem; }
  .api-card summary { cursor: pointer; font-weight: 600; color: #555; }
  .api-card .hint { font-size: .82rem; color: #777; margin: .7rem 0; line-height: 1.45; }
  .api-card code { font-family: ui-monospace, Consolas, monospace; font-size: .82rem; background: #f3f4f7; border-radius: 4px; padding: .1rem .3rem; word-break: break-all; }
  .token-new { background: #fff8e1; border: 1px solid #f0d77a; border-radius: 8px; padding: .7rem .9rem; margin: .7rem 0; font-size: .85rem; }
  .token-new .token-value { display: flex; align-items: center; gap: .4rem; margin-top: .4rem; }
  .token-list { list-style: none; margin: .5rem 0; }
  .token-list li { display: flex; align-items: center; gap: .6rem; padding: .45rem 0; border-top: 1px solid #eee; font-size: .88rem; }
  .token-list .token-info { flex: 1; min-width: 0; word-break: break-word; }
  .token-list .file-sub { margin-top: .1rem; }
  .token-form { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .6rem; }
  .token-form input[type=text] { flex: 1; min-width: 12rem; width: auto; }
</style>
</head>
<body>

<?php if (!$authenticated): ?>

  <div class="card" style="max-width:340px">
    <h1>🔒 Clipboard</h1>
    <?php if (!empty($loginError)): ?>
      <div class="err"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="post">
      <label style="display:block;margin-bottom:.4rem;font-size:.9rem">PIN</label>
      <input type="password" name="pin" autofocus autocomplete="off">
      <div class="row">
        <button class="btn btn-primary" type="submit">Anmelden</button>
      </div>
    </form>
  </div>

<?php else: ?>

  <div class="overlay" id="uploadOverlay">
    <div class="upload-box">
      <h2><span class="spinner"></span>Upload läuft …</h2>
      <div class="progress"><div class="progress-bar" id="progressBar"></div></div>
      <div class="progress-text">
        <span id="progressPct">0 %</span>
        <span id="progressInfo"></span>
      </div>
      <p style="font-size:.78rem;color:#999;margin-top:.8rem">Bitte das Fenster geöffnet lassen, bis der Upload fertig ist.</p>
    </div>
  </div>

  <div class="top-bar">
    <h1 style="font-size:1.2rem">📋 Clipboard</h1>
    <div style="display:flex;gap:.5rem;align-items:center">
      <button class="btn btn-secondary" onclick="refreshEntries(this)">Aktualisieren</button>
      <?php if (!empty($entries) || !empty($files)): ?>
        <a class="btn btn-secondary" href="?clearall&amp;csrf=<?= htmlspecialchars(CSRF_TOKEN) ?>" onclick="return confirm('Alle Einträge UND Dateien löschen?')">Alle löschen</a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="?logout&amp;csrf=<?= htmlspecialchars(CSRF_TOKEN) ?>">Abmelden</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="msg"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(CSRF_TOKEN) ?>">
      <textarea name="text" placeholder="Text hier einfügen …" autofocus></textarea>
      <div class="row">
        <button class="btn btn-primary" type="submit">Speichern</button>
      </div>
    </form>
  </div>

  <div class="card">
    <form method="post" enctype="multipart/form-data" id="uploadForm">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(CSRF_TOKEN) ?>">
      <div class="dropzone" id="dropzone">
        Datei hierher ziehen oder klicken zum Auswählen
        <input type="file" name="upload[]" id="fileInput" multiple style="display:none">
      </div>
      <div class="row">
        <button class="btn btn-primary" type="submit">Hochladen</button>
        <span id="fileChosen" style="font-size:.85rem;color:#666;align-self:center"></span>
      </div>
    </form>
  </div>

  <div id="entries">
  <?php if (empty($entries)): ?>
    <div class="empty">Noch keine Einträge.</div>
  <?php else: ?>
    <?php foreach ($entries as $e): ?>
      <div class="entry">
        <div class="entry-actions">
          <a class="icon-btn icon-btn-danger" href="?delete=<?= urlencode($e['id']) ?>&amp;csrf=<?= htmlspecialchars(CSRF_TOKEN) ?>" onclick="return confirmDelete('Löschen?')" title="Löschen" aria-label="Löschen"><?= $trashSvg ?></a>
          <button class="icon-btn" onclick="copyText(this)" data-text="<?= htmlspecialchars($e['text'], ENT_QUOTES) ?>" title="Kopieren" aria-label="Kopieren"><?= $copySvg ?></button>
        </div>
        <div class="entry-text"><?= htmlspecialchars($e['text']) ?></div>
        <button class="expand-btn" onclick="toggleExpand(this)" title="Mehr anzeigen" aria-label="Mehr anzeigen" hidden>⋯</button>
        <div class="entry-meta">
          <span><?= date('d.m.Y H:i', $e['ts']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>

  <div id="files">
  <?php foreach ($files as $f): ?>
    <div class="entry file-entry">
      <span class="file-icon">📎</span>
      <div class="file-info">
        <div class="file-name"><?= htmlspecialchars($f['name']) ?></div>
        <div class="file-sub"><?= humanSize($f['size']) ?> · <?= date('d.m.Y H:i', $f['ts']) ?></div>
      </div>
      <div class="file-actions">
        <a class="btn btn-dl" href="?download=<?= urlencode($f['id']) ?>">Download</a>
        <a class="btn btn-danger" href="?delfile=<?= urlencode($f['id']) ?>&amp;csrf=<?= htmlspecialchars(CSRF_TOKEN) ?>" onclick="return confirmDelete('Datei löschen?')">Löschen</a>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <?php $apiBase = (IS_HTTPS ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['SCRIPT_NAME'] ?? '/index.php'); ?>
  <details class="card api-card" id="api"<?= $newToken !== '' || ($_GET['msg'] ?? '') === 'tokendel' ? ' open' : '' ?>>
    <summary>API-Zugänge (<?= count($tokens) ?>)</summary>
    <p class="hint">
      Mit einem API-Token können Programme, Skripte und Claude Texte und Dateien senden, lesen und löschen – ohne PIN.
      Endpunkt: <code><?= htmlspecialchars($apiBase) ?>?api=…</code>, Token im Header <code>X-Clipboard-Token</code>.
      Jedes Token gewährt vollen Zugriff auf das Clipboard; nicht mehr benötigte Tokens bitte widerrufen.
    </p>

    <?php if ($newToken !== ''): ?>
      <div class="token-new">
        <strong>Neues Token – jetzt kopieren, es wird nur einmal angezeigt:</strong>
        <div class="token-value">
          <code><?= htmlspecialchars($newToken) ?></code>
          <button class="icon-btn" onclick="copyText(this)" data-text="<?= htmlspecialchars($newToken, ENT_QUOTES) ?>" title="Kopieren" aria-label="Token kopieren"><?= $copySvg ?></button>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($tokens): ?>
      <ul class="token-list">
        <?php foreach ($tokens as $t): ?>
          <li>
            <div class="token-info">
              <strong><?= htmlspecialchars($t['name']) ?></strong> <code><?= htmlspecialchars($t['hint']) ?></code>
              <div class="file-sub">
                erstellt <?= date('d.m.Y H:i', (int)$t['created']) ?> ·
                <?= $t['last_used'] ? 'zuletzt benutzt ' . date('d.m.Y H:i', (int)$t['last_used']) : 'noch nie benutzt' ?>
              </div>
            </div>
            <a class="btn btn-danger" href="?deltoken=<?= urlencode($t['id']) ?>&amp;csrf=<?= htmlspecialchars(CSRF_TOKEN) ?>" onclick="return confirm(<?= htmlspecialchars(json_encode('Token „' . $t['name'] . '“ widerrufen? Programme, die es nutzen, verlieren den Zugriff.', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">Widerrufen</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" class="token-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(CSRF_TOKEN) ?>">
      <input type="hidden" name="newtoken" value="1">
      <input type="text" name="token_name" maxlength="60" placeholder="Name, z. B. Laptop-Skript oder Claude" required>
      <button class="btn btn-primary" type="submit">Token erzeugen</button>
    </form>
  </details>

  <div class="version">Clipboard <?= htmlspecialchars(APP_VERSION) ?></div>

<script>
const CSRF = <?= json_encode(CSRF_TOKEN) ?>; // sent with every state-changing request
const REFRESH_INTERVAL = 15000; // ms
let lastSignature = null;

function renderData(data) {
  const signature = JSON.stringify(data);
  if (signature === lastSignature) return; // nichts geändert
  lastSignature = signature;

  const texts = document.getElementById('entries');
  if (data.texts.length === 0) {
    texts.innerHTML = '<div class="empty">Noch keine Einträge.</div>';
  } else {
    texts.innerHTML = data.texts.map(e => `
      <div class="entry">
        <div class="entry-actions">
          <a class="icon-btn icon-btn-danger" href="?delete=${encodeURIComponent(e.id)}&amp;csrf=${CSRF}" onclick="return confirmDelete('Löschen?')" title="Löschen" aria-label="Löschen">${ICON_TRASH}</a>
          <button class="icon-btn" onclick="copyText(this)" data-text="${escAttr(e.text)}" title="Kopieren" aria-label="Kopieren">${ICON_COPY}</button>
        </div>
        <div class="entry-text">${escHtml(e.text)}</div>
        <button class="expand-btn" onclick="toggleExpand(this)" title="Mehr anzeigen" aria-label="Mehr anzeigen" hidden>⋯</button>
        <div class="entry-meta">
          <span>${e.date}</span>
        </div>
      </div>`).join('');
  }
  initEntries();

  const files = document.getElementById('files');
  files.innerHTML = data.files.map(f => `
    <div class="entry file-entry">
      <span class="file-icon">📎</span>
      <div class="file-info">
        <div class="file-name">${escHtml(f.name)}</div>
        <div class="file-sub">${escHtml(f.size)} · ${f.date}</div>
      </div>
      <div class="file-actions">
        <a class="btn btn-dl" href="?download=${encodeURIComponent(f.id)}">Download</a>
        <a class="btn btn-danger" href="?delfile=${encodeURIComponent(f.id)}&amp;csrf=${CSRF}" onclick="return confirmDelete('Datei löschen?')">Löschen</a>
      </div>
    </div>`).join('');
}

function fetchEntries() {
  return fetch(location.pathname + '?json=1&t=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store'
    })
    .then(r => r.json())
    .then(renderData)
    .catch(() => {});
}

function refreshEntries(btn) {
  const orig = btn.textContent;
  btn.textContent = '…';
  btn.disabled = true;
  lastSignature = null; // manueller Klick erzwingt Neuaufbau
  fetchEntries().finally(() => { btn.textContent = orig; btn.disabled = false; });
}

// Auto-Refresh alle 15 Sek. (pausierbar während eines Uploads)
let refreshTimer = null;
function startRefresh() { if (!refreshTimer) refreshTimer = setInterval(fetchEntries, REFRESH_INTERVAL); }
function stopRefresh()  { clearInterval(refreshTimer); refreshTimer = null; }
startRefresh();
function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function escAttr(s) {
  return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;');
}
const ICON_COPY  = <?= json_encode($copySvg) ?>;
const ICON_TRASH = <?= json_encode($trashSvg) ?>;
const ICON_CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>';

// Löschen-Bestätigung: per CONFIRM_DELETE in der Config an-/abschaltbar
const CONFIRM_DELETE = <?= CONFIRM_DELETE ? 'true' : 'false' ?>;
function confirmDelete(msg) {
  return !CONFIRM_DELETE || confirm(msg);
}

function copyText(btn) {
  navigator.clipboard.writeText(btn.dataset.text).then(() => {
    btn.innerHTML = ICON_CHECK;
    btn.classList.add('copied');
    setTimeout(() => { btn.innerHTML = ICON_COPY; btn.classList.remove('copied'); }, 1500);
  });
}

// Lange Einträge einklappen: Text auf feste Höhe begrenzen und den
// ⋯-Button nur zeigen, wenn tatsächlich etwas abgeschnitten wird.
function initEntries() {
  document.querySelectorAll('#entries .entry').forEach(entry => {
    const text = entry.querySelector('.entry-text');
    const btn  = entry.querySelector('.expand-btn');
    if (!text || !btn || entry.dataset.init) return;
    entry.dataset.init = '1';
    text.classList.add('collapsed');
    if (text.scrollHeight > text.clientHeight + 2) {
      btn.hidden = false;
    } else {
      text.classList.remove('collapsed');
    }
  });
}

function toggleExpand(btn) {
  const text = btn.closest('.entry').querySelector('.entry-text');
  const collapsed = text.classList.toggle('collapsed');
  btn.textContent = collapsed ? '⋯' : '▴';
  btn.title = btn.ariaLabel = collapsed ? 'Mehr anzeigen' : 'Weniger anzeigen';
}

initEntries();

// ── Drag & Drop / Datei-Auswahl ──────────────────────────────────────────────
const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');
const fileChosen = document.getElementById('fileChosen');

function updateChosen() {
  const n = fileInput.files.length;
  fileChosen.textContent = n === 0 ? '' :
    (n === 1 ? fileInput.files[0].name : n + ' Dateien ausgewählt');
}

dropzone.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', updateChosen);

['dragenter', 'dragover'].forEach(ev =>
  dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('dragover'); }));
['dragleave', 'drop'].forEach(ev =>
  dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('dragover'); }));

dropzone.addEventListener('drop', e => {
  fileInput.files = e.dataTransfer.files;
  updateChosen();
});

// ── Upload mit Fortschritt + Sperre ──────────────────────────────────────────
const uploadForm = document.getElementById('uploadForm');
const overlay = document.getElementById('uploadOverlay');
const progressBar = document.getElementById('progressBar');
const progressPct = document.getElementById('progressPct');
const progressInfo = document.getElementById('progressInfo');

function fmtBytes(b) {
  const u = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
  return (i === 0 ? b : b.toFixed(1)) + ' ' + u[i];
}

const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB pro Chunk

uploadForm.addEventListener('submit', e => {
  e.preventDefault();
  const files = Array.from(fileInput.files);
  if (files.length === 0) { alert('Bitte zuerst eine Datei auswählen.'); return; }

  const totalBytes = files.reduce((s, f) => s + f.size, 0);
  let completedBytes = 0; // Bytes vollständig hochgeladener Dateien
  const startTs = Date.now();

  stopRefresh(); // Poll während des Uploads aussetzen
  overlay.classList.add('show');
  progressBar.style.width = '0%';
  progressPct.textContent = '0 %';
  progressInfo.textContent = '';

  function updateProgress(absInFile) {
    const loaded = completedBytes + absInFile;
    const pct = totalBytes > 0 ? Math.round(loaded / totalBytes * 100) : 100;
    progressBar.style.width = pct + '%';
    progressPct.textContent = pct + ' %';
    const secs = (Date.now() - startTs) / 1000;
    const speed = secs > 0 ? loaded / secs : 0;
    const remaining = speed > 0 ? (totalBytes - loaded) / speed : 0;
    progressInfo.textContent =
      `${fmtBytes(loaded)} / ${fmtBytes(totalBytes)} · ${fmtBytes(speed)}/s · noch ${Math.ceil(remaining)}s`;
  }

  function finish(ok, msg) {
    overlay.classList.remove('show');
    startRefresh();
    if (ok) {
      fileInput.value = '';
      updateChosen();
      lastSignature = null;
      fetchEntries();
    } else {
      alert(msg || 'Upload fehlgeschlagen.');
    }
  }

  function uploadFile(fileIndex) {
    if (fileIndex >= files.length) { finish(true); return; }
    const file = files[fileIndex];
    const uploadId = (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '' + Math.random()))
      .replace(/[^a-f0-9]/gi, '').toLowerCase();
    const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));

    function uploadChunk(chunkIndex) {
      const start = chunkIndex * CHUNK_SIZE;
      const blob = file.slice(start, start + CHUNK_SIZE);
      const data = new FormData();
      data.append('ajax', '1');
      data.append('chunked', '1');
      data.append('uploadId', uploadId);
      data.append('chunkIndex', chunkIndex);
      data.append('totalChunks', totalChunks);
      data.append('fileName', file.name);
      data.append('chunk', blob);
      data.append('csrf', CSRF);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', location.pathname, true);
      xhr.upload.addEventListener('progress', ev => {
        if (ev.lengthComputable) updateProgress(start + ev.loaded);
      });
      xhr.addEventListener('load', () => {
        let res = {};
        try { res = JSON.parse(xhr.responseText); } catch (_) {}
        if (xhr.status === 200 && res.ok) {
          if (chunkIndex < totalChunks - 1) {
            uploadChunk(chunkIndex + 1);
          } else {
            completedBytes += file.size;
            uploadFile(fileIndex + 1);
          }
        } else {
          finish(false, res.error);
        }
      });
      xhr.addEventListener('error', () => finish(false, 'Verbindung unterbrochen.'));
      xhr.send(data);
    }

    uploadChunk(0);
  }

  uploadFile(0);
});

// Warnen, falls während des Uploads die Seite verlassen wird
window.addEventListener('beforeunload', e => {
  if (overlay.classList.contains('show')) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<?php endif; ?>

</body>
</html>
