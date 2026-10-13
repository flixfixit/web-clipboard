<?php
// Smoke test against a running dev server (dev/serve.ps1).
// Usage: php dev/smoketest.php [base-url] [pin]
// Defaults: http://127.0.0.1:8080 and the PIN from CLIPBOARD_PIN / dev/config.dev.php.
// Only removes the entries it creates itself — never calls "Alle löschen".
if (PHP_SAPI !== 'cli') exit;

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$pin  = $argv[2] ?? (require __DIR__ . '/config.dev.php')['pin'];

$cookies = [];
$failed  = 0;

function req(string $method, string $path, ?array $fields = null, ?array $file = null): array {
    global $base, $cookies, $lastHeaders;
    $headers = [];
    $body = '';
    if ($file !== null) {
        $b = '----smoke' . bin2hex(random_bytes(8));
        foreach ($fields ?? [] as $k => $v) {
            $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"{$file['field']}\"; filename=\"blob\"\r\n"
               . "Content-Type: application/octet-stream\r\n\r\n{$file['data']}\r\n--$b--\r\n";
        $headers[] = "Content-Type: multipart/form-data; boundary=$b";
    } elseif ($fields !== null) {
        $body = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    if ($cookies) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies));
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
    ]]);
    $resp = @file_get_contents($base . $path, false, $ctx);
    if ($resp === false) {
        fwrite(STDERR, "Server unter $base nicht erreichbar. Läuft dev/serve.ps1?\n");
        exit(2);
    }
    $status = 0;
    $lastHeaders = $http_response_header;
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int)$m[1];
        if (preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $h, $m)) {
            if ($m[2] === '' || $m[2] === 'deleted') unset($cookies[$m[1]]); else $cookies[$m[1]] = $m[2];
        }
    }
    return [$status, $resp];
}

function check(string $label, bool $ok, string $detail = ''): void {
    global $failed;
    if (!$ok) $failed++;
    echo ($ok ? '  OK    ' : '  FEHLER ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

function header_line(string $name): string {
    global $lastHeaders;
    foreach ($lastHeaders as $h) if (stripos($h, "$name:") === 0) return trim(substr($h, strlen($name) + 1));
    return '';
}

function state(): ?array {
    [, $body] = req('GET', '/?json=1');
    $data = json_decode($body, true);
    return is_array($data) && isset($data['texts']) ? $data : null;
}

echo "Smoke-Test gegen $base\n\nRepository:\n";
// setup.php writes its own copies of the .htaccess files; they must not drift
define('CLIPBOARD_APP', true);
require_once dirname(__DIR__) . '/setup.php';
foreach (setupHtaccessFiles() as $name => $content) {
    $file = dirname(__DIR__) . '/' . $name;
    check("setup.php-Vorlage für $name ist aktuell",
        is_file($file) && setupNormalize((string)file_get_contents($file)) === setupNormalize($content));
}

echo "\nSchutzregeln (ohne Login):\n";
foreach (['/config.php', '/setup-started.json', '/data.json', '/files.json', '/.devdata/', '/.devdata/data.json', '/uploads/',
          '/uploads/.htaccess', '/dev/', '/dev/router.php', '/.git/HEAD', '/.git/config'] as $p) {
    [$s] = req('GET', $p);
    check("$p → 403", $s === 403, "Status $s");
}
// Spelling variants: Windows maps them to the real file, Linux returns 404.
// Either way they must not be served (no 2xx).
foreach (['/config.php.', '/CONFIG.PHP', '/Data.JSON', '/setup.php'] as $p) {
    [$s] = req('GET', $p);
    check("$p wird nicht ausgeliefert", $s < 200 || $s >= 300, "Status $s");
}
// mod_speling must not list similar file names (300 Multiple Choices)
foreach (['/.gibt-es-nicht', '/dat.json', '/DATA.JSON', '/uploads.x'] as $p) {
    [$s, $body] = req('GET', $p);
    check("$p verrät keine Dateinamen", $s !== 300 && $s !== 301 && !str_contains($body, 'data.json'), "Status $s");
}
check('?json=1 liefert ohne Login keine Daten', state() === null);
req('GET', '/');
check('CSP verbietet Einbetten und fremde Quellen', str_contains(header_line('Content-Security-Policy'), "frame-ancestors 'none'")
    && str_contains(header_line('Content-Security-Policy'), "default-src 'self'"));
check('X-Frame-Options: DENY', header_line('X-Frame-Options') === 'DENY');
check('X-Content-Type-Options: nosniff', header_line('X-Content-Type-Options') === 'nosniff');
check('Referrer-Policy: no-referrer', header_line('Referrer-Policy') === 'no-referrer');
[, $body] = req('POST', '/', ['pin' => 'falsch-' . bin2hex(random_bytes(4))]);
check('Falscher PIN wird abgelehnt', str_contains($body, 'Falscher PIN') && state() === null);

echo "\nAngemeldet:\n";
req('POST', '/', ['pin' => $pin]);
$cookie = header_line('Set-Cookie');
$appPath = rtrim(parse_url($base, PHP_URL_PATH) ?? '', '/') . '/';
check('Session-Cookie: HttpOnly, SameSite=Lax, Pfad der App', stripos($cookie, 'HttpOnly') !== false
    && stripos($cookie, 'SameSite=Lax') !== false && stripos($cookie, "path=$appPath;") !== false
    && (!str_starts_with($base, 'https:') || stripos($cookie, 'secure') !== false), $cookie);
$s0 = state();
check('Login mit PIN', $s0 !== null);
if ($s0 === null) { echo "\nAbbruch: Login fehlgeschlagen (PIN prüfen).\n"; exit(1); }

[, $page] = req('GET', '/');
preg_match('#name="csrf" value="([0-9a-f]{64})"#', $page, $m);
$csrf = $m[1] ?? '';
check('Seite enthält CSRF-Token', $csrf !== '');

$marker = 'smoketest <b>' . bin2hex(random_bytes(6)) . '</b>';
[$s] = req('POST', '/', ['text' => $marker]);
check('Text ohne CSRF-Token → 403, nicht gespeichert', $s === 403
    && !in_array($marker, array_column(state()['texts'], 'text'), true), "Status $s");
req('POST', '/', ['text' => $marker, 'csrf' => $csrf]);
$text = null;
foreach (state()['texts'] as $t) if ($t['text'] === $marker) $text = $t;
check('Text speichern', $text !== null);
[, $page] = req('GET', '/');
check('Text wird HTML-escaped ausgegeben', !str_contains($page, $marker) && str_contains($page, htmlspecialchars($marker)));

// 6 MB in two chunks (5 MB + 1 MB), like the browser client does
$data = random_bytes(6 * 1024 * 1024);
$chunk = 5 * 1024 * 1024;
$uploadId = bin2hex(random_bytes(8));
$fileName = 'smoketest-' . $uploadId . '.bin';
$total = (int)ceil(strlen($data) / $chunk);
$ok = true;
for ($i = 0; $i < $total; $i++) {
    [$s, $r] = req('POST', '/', ['csrf' => $csrf, 'chunked' => '1', 'uploadId' => $uploadId, 'chunkIndex' => $i,
        'totalChunks' => $total, 'fileName' => $fileName], ['field' => 'chunk', 'data' => substr($data, $i * $chunk, $chunk)]);
    $ok = $ok && $s === 200 && (json_decode($r, true)['ok'] ?? false);
}
check("Chunked Upload ($total Chunks, 6 MB)", $ok);
$file = null;
foreach (state()['files'] as $f) if ($f['name'] === $fileName) $file = $f;
check('Datei erscheint in der Liste', $file !== null);
if ($file) {
    [$s, $dl] = req('GET', '/?download=' . urlencode($file['id']));
    check('Download liefert identischen Inhalt', $s === 200 && hash_equals(hash('sha256', $data), hash('sha256', $dl)));
}
[$s] = req('GET', '/?download=gibt-es-nicht');
check('Download mit unbekannter ID → 404', $s === 404, "Status $s");

if ($text) {
    [$s] = req('GET', '/?delete=' . urlencode($text['id']));
    check('Löschen ohne CSRF-Token → 403, Eintrag bleibt', $s === 403
        && in_array($marker, array_column(state()['texts'], 'text'), true), "Status $s");
    req('GET', '/?delete=' . urlencode($text['id']) . '&csrf=' . $csrf);
}
if ($file) req('GET', '/?delfile=' . urlencode($file['id']) . '&csrf=' . $csrf);
$s1 = state();
check('Text und Datei wieder gelöscht',
    !in_array($marker, array_column($s1['texts'], 'text'), true) && !in_array($fileName, array_column($s1['files'], 'name'), true));

echo "\nAPI-Token und REST-API:\n";
[$s] = req('POST', '/', ['newtoken' => '1', 'token_name' => 'smoketest']);
check('Token erzeugen ohne CSRF-Token → 403', $s === 403, "Status $s");
$tokenName = 'smoketest <i>' . bin2hex(random_bytes(4)) . '</i>';
[$s] = req('POST', '/', ['newtoken' => '1', 'token_name' => $tokenName, 'csrf' => $csrf]);
check('Token erzeugen leitet weiter', $s === 302 || $s === 303, "Status $s");
[, $page] = req('GET', '/');
preg_match('#cbt_[0-9a-f]{48}#', $page, $m);
$token = $m[0] ?? '';
check('Neues Token wird angezeigt, Name HTML-escaped', $token !== '' && str_contains($page, htmlspecialchars($tokenName)));
[, $page] = req('GET', '/');
check('Token wird nur einmal angezeigt', $token !== '' && !str_contains($page, $token));
// Only revoke our own token, other tokens in the list stay untouched
preg_match('#' . preg_quote(htmlspecialchars($tokenName), '#') . '</strong>.*?\?deltoken=([0-9a-f]+)&amp;csrf=#s', $page, $m);
$tokenId = $m[1] ?? '';

// API requests never send the session cookie
function api(string $method, string $path, array $headers = [], string $body = ''): array {
    global $base, $lastHeaders;
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
    ]]);
    $resp = (string)@file_get_contents($base . $path, false, $ctx);
    $lastHeaders = $http_response_header ?? [];
    preg_match('#^HTTP/\S+ (\d+)#', $lastHeaders[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $resp, json_decode($resp, true)];
}
function multipart(array $fields, array $files): array {
    $b = '----smoke' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $k => $v) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    foreach ($files as $k => [$name, $data]) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"$name\"\r\n"
               . "Content-Type: application/octet-stream\r\n\r\n$data\r\n";
    }
    return ["Content-Type: multipart/form-data; boundary=$b", $body . "--$b--\r\n"];
}
$auth = "X-Clipboard-Token: $token";

[$s] = api('GET', '/?api=ping');
check('API ohne Token → 401', $s === 401, "Status $s");
[$s] = api('GET', '/?api=ping', ['X-Clipboard-Token: cbt_' . str_repeat('0', 48)]);
check('API mit falschem Token → 401', $s === 401, "Status $s");
[$s] = req('GET', '/?api=texts');
check('API mit Session-Cookie statt Token → 401', $s === 401, "Status $s");
[$s] = api('GET', '/?api=ping&token=' . $token);
check('Token in der URL wird ignoriert → 401', $s === 401, "Status $s");
[$s, , $j] = api('GET', '/?api=ping', [$auth]);
check('API ping mit X-Clipboard-Token', $s === 200 && ($j['ok'] ?? false) && ($j['token'] ?? '') === $tokenName, "Status $s");
check('API-Antwort ist JSON, nicht cachebar', str_starts_with(header_line('Content-Type'), 'application/json')
    && str_contains(header_line('Cache-Control'), 'no-store'));
[$s] = api('GET', '/?api=ping', ["Authorization: Bearer $token"]);
check('API ping mit Authorization: Bearer', $s === 200, "Status $s (Server reicht Authorization evtl. nicht an PHP weiter)");
[$s] = api('GET', '/?api=gibt-es-nicht', [$auth]);
check('Unbekannte Ressource → 404', $s === 404, "Status $s");

$apiText = "api-smoketest <b>" . bin2hex(random_bytes(6)) . "</b>\nzweite Zeile äöü";
[$s, , $j] = api('POST', '/?api=texts', [$auth, 'Content-Type: application/json'], json_encode(['text' => $apiText]));
$apiTextId = $j['text']['id'] ?? '';
check('API: Text als JSON speichern → 201', $s === 201 && ($j['text']['text'] ?? '') === $apiText, "Status $s");
check('API-Text erscheint in der Weboberfläche', in_array($apiText, array_column(state()['texts'], 'text'), true));
[$s, , $j] = api('POST', '/?api=texts', [$auth, 'Content-Type: text/plain; charset=utf-8'], 'plain ' . $apiText);
$plainId = $j['text']['id'] ?? '';
check('API: Text als text/plain speichern → 201', $s === 201 && ($j['text']['text'] ?? '') === 'plain ' . $apiText, "Status $s");
[$s] = api('POST', '/?api=texts', [$auth, 'Content-Type: application/json'], '{"text": "   "}');
check('API: leerer Text → 400', $s === 400, "Status $s");
[$s, , $j] = api('GET', '/?api=texts', [$auth]);
check('API: Texte lesen', $s === 200 && in_array($apiText, array_column($j['texts'] ?? [], 'text'), true));
[$s, , $j] = api('GET', '/?api=texts&id=' . urlencode($apiTextId), [$auth]);
check('API: einzelnen Text lesen', $s === 200 && ($j['text']['text'] ?? '') === $apiText, "Status $s");
[$s] = api('DELETE', '/?api=texts&id=' . urlencode($apiTextId), [$auth]);
check('API: Text löschen (DELETE)', $s === 200 && !in_array($apiText, array_column(state()['texts'], 'text'), true), "Status $s");
[$s] = api('DELETE', '/?api=texts&id=' . urlencode($apiTextId), [$auth]);
check('API: bereits gelöschter Text → 404', $s === 404, "Status $s");
[$s] = api('POST', '/?api=texts&id=' . urlencode($plainId) . '&_method=DELETE', [$auth]);
check('API: Text löschen per POST &_method=DELETE', $s === 200, "Status $s");

$apiData = random_bytes(300 * 1024);
$apiName = 'api-smoketest-' . bin2hex(random_bytes(4)) . '.bin';
[$ct, $body] = multipart([], ['file' => [$apiName, $apiData]]);
[$s, , $j] = api('POST', '/?api=files', [$auth, $ct], $body);
$apiFileId = $j['files'][0]['id'] ?? '';
check('API: Datei hochladen → 201', $s === 201 && ($j['files'][0]['size'] ?? 0) === strlen($apiData), "Status $s");
[$s, , $j] = api('GET', '/?api=files', [$auth]);
check('API: Dateiliste enthält die Datei', in_array($apiName, array_column($j['files'] ?? [], 'name'), true));
[$s, $dl] = api('GET', '/?api=files&id=' . urlencode($apiFileId), [$auth]);
check('API: Download liefert identischen Inhalt', $s === 200 && $dl === $apiData, "Status $s");
[$s, $dl] = api('GET', '/?api=files&id=' . urlencode($apiFileId));
check('API: Download ohne Token → 401', $s === 401 && $dl !== $apiData, "Status $s");
[$s] = api('DELETE', '/?api=files&id=' . urlencode($apiFileId), [$auth]);
check('API: Datei löschen', $s === 200 && !in_array($apiName, array_column(state()['files'], 'name'), true), "Status $s");

// Chunked like the browser: three small pieces
$parts = str_split(random_bytes(3000), 1000);
$uploadId = bin2hex(random_bytes(8));
$chunkName = 'api-chunks-' . $uploadId . '.bin';
$ok = true;
$j = [];
foreach ($parts as $i => $part) {
    [$ct, $body] = multipart(['uploadId' => $uploadId, 'chunkIndex' => $i, 'totalChunks' => count($parts), 'fileName' => $chunkName],
        ['chunk' => ['blob', $part]]);
    [$s, , $j] = api('POST', '/?api=files', [$auth, $ct], $body);
    $ok = $ok && ($j['ok'] ?? false) && $s === ($i === count($parts) - 1 ? 201 : 200);
}
$chunkId = $j['file']['id'] ?? '';
[, $dl] = api('GET', '/?api=files&id=' . urlencode($chunkId), [$auth]);
check('API: Chunked Upload (3 Chunks) ergibt identische Datei', $ok && $dl === implode('', $parts));
api('DELETE', '/?api=files&id=' . urlencode($chunkId), [$auth]);
[$s] = api('GET', '/?api=all', [$auth]);
check('API: "all" nur per DELETE (GET → 405)', $s === 405, "Status $s");

[$s] = req('GET', '/?deltoken=' . urlencode($tokenId));
check('Token widerrufen ohne CSRF-Token → 403', $s === 403, "Status $s");
req('GET', '/?deltoken=' . urlencode($tokenId) . '&csrf=' . $csrf);
[$s] = api('GET', '/?api=ping', [$auth]);
check('Widerrufenes Token → 401', $tokenId !== '' && $s === 401, "Status $s");
// The failed token attempts above count towards the login lockout; a login resets it
req('POST', '/', ['pin' => $pin]);
[, $page] = req('GET', '/');
preg_match('#name="csrf" value="([0-9a-f]{64})"#', $page, $m);
$csrf = $m[1] ?? $csrf;

[$s] = req('GET', '/?logout=1');
check('Abmelden ohne CSRF-Token → 403, Session bleibt', $s === 403 && state() !== null, "Status $s");
req('GET', '/?logout=1&csrf=' . $csrf);
check('Logout beendet die Session', state() === null);
if ($file) {
    [$s, $dl] = req('GET', '/?download=' . urlencode($file['id']));
    check('Download ohne Login liefert keine Datei', $dl !== $data);
}

echo $failed ? "\n$failed Prüfung(en) fehlgeschlagen.\n" : "\nAlle Prüfungen bestanden.\n";
exit($failed ? 1 : 0);
