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
    global $base, $cookies;
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

function state(): ?array {
    [, $body] = req('GET', '/?json=1');
    $data = json_decode($body, true);
    return is_array($data) && isset($data['texts']) ? $data : null;
}

echo "Smoke-Test gegen $base\n\nSchutzregeln (ohne Login):\n";
foreach (['/config.php', '/data.json', '/files.json', '/.devdata/', '/.devdata/data.json', '/uploads/',
          '/uploads/.htaccess', '/dev/', '/dev/router.php', '/.git/HEAD', '/.git/config'] as $p) {
    [$s] = req('GET', $p);
    check("$p → 403", $s === 403, "Status $s");
}
// Spelling variants: Windows maps them to the real file, Linux returns 404.
// Either way they must not be served (no 2xx).
foreach (['/config.php.', '/CONFIG.PHP', '/Data.JSON'] as $p) {
    [$s] = req('GET', $p);
    check("$p wird nicht ausgeliefert", $s < 200 || $s >= 300, "Status $s");
}
// mod_speling must not list similar file names (300 Multiple Choices)
foreach (['/.gibt-es-nicht', '/dat.json', '/DATA.JSON', '/uploads.x'] as $p) {
    [$s, $body] = req('GET', $p);
    check("$p verrät keine Dateinamen", $s !== 300 && $s !== 301 && !str_contains($body, 'data.json'), "Status $s");
}
check('?json=1 liefert ohne Login keine Daten', state() === null);
[, $body] = req('POST', '/', ['pin' => 'falsch-' . bin2hex(random_bytes(4))]);
check('Falscher PIN wird abgelehnt', str_contains($body, 'Falscher PIN') && state() === null);

echo "\nAngemeldet:\n";
req('POST', '/', ['pin' => $pin]);
$s0 = state();
check('Login mit PIN', $s0 !== null);
if ($s0 === null) { echo "\nAbbruch: Login fehlgeschlagen (PIN prüfen).\n"; exit(1); }

$marker = 'smoketest <b>' . bin2hex(random_bytes(6)) . '</b>';
req('POST', '/', ['text' => $marker]);
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
    [$s, $r] = req('POST', '/', ['chunked' => '1', 'uploadId' => $uploadId, 'chunkIndex' => $i,
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

if ($text) req('GET', '/?delete=' . urlencode($text['id']));
if ($file) req('GET', '/?delfile=' . urlencode($file['id']));
$s1 = state();
check('Text und Datei wieder gelöscht',
    !in_array($marker, array_column($s1['texts'], 'text'), true) && !in_array($fileName, array_column($s1['files'], 'name'), true));

req('GET', '/?logout=1');
check('Logout beendet die Session', state() === null);
if ($file) {
    [$s, $dl] = req('GET', '/?download=' . urlencode($file['id']));
    check('Download ohne Login liefert keine Datei', $dl !== $data);
}

echo $failed ? "\n$failed Prüfung(en) fehlgeschlagen.\n" : "\nAlle Prüfungen bestanden.\n";
exit($failed ? 1 : 0);
