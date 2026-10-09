<?php
// First-run setup. index.php includes this file only while no config.php exists
// (and CLIPBOARD_PIN is not set). It is reachable for SETUP_WINDOW seconds after
// the first visit; deleting setup-started.json opens a new window.
if (!defined('CLIPBOARD_APP')) {
    http_response_code(404);
    exit;
}

const SETUP_WINDOW = 1800;        // seconds
const SETUP_MIN_PASSWORD = 8;
const SETUP_MARKER = 'setup-started.json';

// Protective .htaccess files, written by the setup when they are missing
// (e.g. because an FTP client skipped dotfiles). dev/smoketest.php checks that
// these copies match the files in the repository.
function setupHtaccessFiles(): array {
    return [
        '.htaccess' => <<<'HTACCESS'
# Block direct access to the JSON data stores
<FilesMatch "\.(json)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

<Files "config.php">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</Files>

# Block dotfiles and dot-directories (.git, .env, .devdata …), e.g. when the
# app is deployed with its git checkout. .well-known stays reachable for ACME.
<IfModule mod_authz_core.c>
    <If "%{REQUEST_URI} =~ m#/\.(?!well-known/)#">
        Require all denied
    </If>
</IfModule>
<IfModule !mod_authz_core.c>
    RedirectMatch 403 "/\.(?!well-known/)"
</IfModule>

# mod_speling (active on IONOS) answers unknown paths with a "Multiple Choices"
# page that lists similar file names, e.g. data.json or .gitignore
<IfModule mod_speling.c>
    CheckSpelling Off
</IfModule>

HTACCESS,
        'uploads/.htaccess' => <<<'HTACCESS'
# No direct access to uploaded blobs — downloads are streamed via index.php
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

Options -Indexes

# Never execute anything in here as a script. No php_flag here: with PHP as
# FastCGI (e.g. IONOS) php_flag is an invalid command and the whole folder
# answers 500 instead of 403.
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py
AddType application/octet-stream .php .phtml .phps .cgi .pl .py .html .htm .shtml

HTACCESS,
    ];
}

function setupNormalize(string $s): string {
    return rtrim(str_replace("\r\n", "\n", $s)) . "\n";
}

// Creates directories, data files and missing .htaccess files, then config.php.
// config.php comes last and is opened with 'x', so a failed or concurrent run
// never leaves a half-configured instance behind.
function setupInstall(string $dir, string $configFile, array $cfg): array {
    $notes = [];
    if (!is_dir($dir . '/uploads') && !@mkdir($dir . '/uploads', 0775, true)) {
        return ['error' => 'Ordner uploads/ konnte nicht angelegt werden (Schreibrechte prüfen).'];
    }
    foreach (setupHtaccessFiles() as $name => $content) {
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            if (@file_put_contents($path, $content) === false) {
                return ['error' => "$name konnte nicht angelegt werden (Schreibrechte prüfen)."];
            }
            $notes[] = "$name angelegt.";
        } elseif (setupNormalize((string)file_get_contents($path)) !== setupNormalize($content)) {
            $notes[] = "$name existiert bereits und weicht von der mitgelieferten Version ab – bitte prüfen.";
        }
    }
    foreach (['data.json', 'files.json'] as $name) {
        if (!is_file($dir . '/' . $name)) {
            if (@file_put_contents($dir . '/' . $name, '[]') === false) {
                return ['error' => "$name konnte nicht angelegt werden (Schreibrechte prüfen)."];
            }
            $notes[] = "$name angelegt.";
        }
    }

    $fh = @fopen($configFile, 'x');
    if ($fh === false) {
        return ['error' => 'config.php existiert bereits oder kann nicht geschrieben werden.'];
    }
    fwrite($fh, "<?php\n"
        . "// Erzeugt vom Setup am " . date('d.m.Y H:i') . ".\n"
        . "// Neues Passwort: diese Datei löschen und die App erneut aufrufen.\n"
        . "return " . var_export($cfg, true) . ";\n");
    fclose($fh);
    @chmod($configFile, 0640);
    @unlink($dir . '/' . SETUP_MARKER);
    $notes[] = 'config.php angelegt.';
    return ['notes' => $notes];
}

// Requests protected paths over HTTP to confirm the web server enforces the
// .htaccess rules. Returns [label, ok (true/false/null = not checkable), detail].
function setupSelfCheck(string $dir): array {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
          . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $probe = 'setup-check-' . bin2hex(random_bytes(6)) . '.txt';
    $marker = bin2hex(random_bytes(8));
    @file_put_contents($dir . '/uploads/' . $probe, $marker);

    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0]]);
    $get = function (string $path) use ($base, $ctx): ?array {
        $body = @file_get_contents($base . $path, false, $ctx);
        if ($body === false || !isset($http_response_header[0])) return null;
        preg_match('#^HTTP/\S+ (\d+)#', $http_response_header[0], $m);
        return [(int)($m[1] ?? 0), $body];
    };

    $checks = [];
    $r = $get('/data.json');
    $checks[] = ['data.json gesperrt', $r === null ? null : ($r[0] < 200 || $r[0] >= 300), $r ? "HTTP {$r[0]}" : ''];
    $r = $get('/config.php');
    $checks[] = ['config.php nicht lesbar', $r === null ? null : !str_contains($r[1], 'pin_hash'), $r ? "HTTP {$r[0]}" : ''];
    $r = $get('/uploads/' . $probe);
    $checks[] = ['uploads/ gesperrt', $r === null ? null : !str_contains($r[1], $marker), $r ? "HTTP {$r[0]}" : ''];
    @unlink($dir . '/uploads/' . $probe);
    return $checks;
}

function runSetup(string $dir, string $configFile): void {
    session_name('clipboard_setup');
    session_start();

    $markerFile = $dir . '/' . SETUP_MARKER;
    if (!is_file($markerFile)) @file_put_contents($markerFile, '{}');
    $started  = is_file($markerFile) ? (int)filemtime($markerFile) : 0;
    $writable = $started > 0 && is_writable($dir);
    $expired  = $writable && time() - $started > SETUP_WINDOW;

    if (empty($_SESSION['setup_csrf'])) $_SESSION['setup_csrf'] = bin2hex(random_bytes(16));

    $values = ['file_ttl_days' => 7, 'max_entries' => 50, 'confirm_delete' => false];
    $errors = [];
    $result = null;
    $checks = [];

    if ($writable && !$expired && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string)($_POST['password'] ?? '');
        $values = [
            'file_ttl_days'  => (int)($_POST['file_ttl_days'] ?? 0),
            'max_entries'    => (int)($_POST['max_entries'] ?? 0),
            'confirm_delete' => isset($_POST['confirm_delete']),
        ];
        if (!hash_equals($_SESSION['setup_csrf'], (string)($_POST['csrf'] ?? ''))) {
            $errors[] = 'Sitzung abgelaufen. Bitte das Formular erneut absenden.';
        }
        if (preg_match_all('/./us', $password) < SETUP_MIN_PASSWORD) { // chars, works without mbstring
            $errors[] = 'Das Passwort muss mindestens ' . SETUP_MIN_PASSWORD . ' Zeichen lang sein.';
        } elseif ($password !== (string)($_POST['password2'] ?? '')) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }
        if ($values['file_ttl_days'] < 1 || $values['file_ttl_days'] > 365) {
            $errors[] = 'Lebensdauer der Dateien: 1 bis 365 Tage.';
        }
        if ($values['max_entries'] < 1 || $values['max_entries'] > 1000) {
            $errors[] = 'Maximale Text-Einträge: 1 bis 1000.';
        }
        if (!$errors) {
            $result = setupInstall($dir, $configFile, ['pin_hash' => password_hash($password, PASSWORD_DEFAULT)] + $values);
            if (isset($result['error'])) {
                $errors[] = $result['error'];
                $result = null;
            } else {
                $checks = setupSelfCheck($dir);
                session_destroy();
            }
        }
    }

    $remaining = max(0, (int)ceil(($started + SETUP_WINDOW - time()) / 60));
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Clipboard – Einrichtung</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; padding: 1rem; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 1.25rem; max-width: 460px; margin: 1rem auto; }
  h1 { font-size: 1.3rem; margin-bottom: .8rem; color: #333; }
  p, li { font-size: .92rem; line-height: 1.5; }
  ul { margin: .4rem 0 .4rem 1.2rem; }
  label { display: block; font-size: .9rem; margin: .8rem 0 .3rem; }
  label.check { display: flex; gap: .5rem; align-items: center; }
  input[type=password], input[type=number] { width: 100%; border: 1px solid #ccc; border-radius: 6px; padding: .6rem; font-size: 1rem; }
  .hint { font-size: .8rem; color: #888; margin-top: .25rem; }
  .btn { display: inline-block; margin-top: 1rem; padding: .55rem 1.2rem; border: none; border-radius: 6px; cursor: pointer; font-size: .95rem; background: #4a6fa5; color: #fff; text-decoration: none; }
  .msg { background: #d4edda; color: #155724; border-radius: 6px; padding: .5rem .9rem; margin-bottom: .75rem; font-size: .9rem; }
  .err { background: #f8d7da; color: #721c24; border-radius: 6px; padding: .5rem .9rem; margin-bottom: .75rem; font-size: .9rem; }
  .warn { background: #fff3cd; color: #664d03; border-radius: 6px; padding: .5rem .9rem; margin-bottom: .75rem; font-size: .9rem; }
  .ok { color: #2e7d32; } .bad { color: #c0392b; } .unk { color: #888; }
  code { background: #f3f4f6; padding: 0 .25rem; border-radius: 4px; }
</style>
</head>
<body>
<div class="card">
<?php if ($result !== null): ?>
  <h1>✅ Einrichtung abgeschlossen</h1>
  <div class="msg">Die App ist eingerichtet. Das Setup ist ab jetzt gesperrt.</div>
  <ul>
    <?php foreach ($result['notes'] as $note): ?><li><?= $e($note) ?></li><?php endforeach; ?>
  </ul>
  <p style="margin-top:.8rem"><strong>Prüfung der Schutzregeln:</strong></p>
  <ul>
    <?php foreach ($checks as [$label, $ok, $detail]): ?>
      <li class="<?= $ok === true ? 'ok' : ($ok === false ? 'bad' : 'unk') ?>">
        <?= $ok === true ? '✔' : ($ok === false ? '✘' : '?') ?> <?= $e($label) ?>
        <?= $ok === null ? '(nicht prüfbar)' : '(' . $e($detail) . ')' ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (in_array(false, array_column($checks, 1), true)): ?>
    <div class="err">Der Webserver wertet die <code>.htaccess</code>-Regeln offenbar nicht aus. Daten wären direkt abrufbar – bitte vor der Nutzung beheben (siehe README, Abschnitt Deployment).</div>
  <?php endif; ?>
  <a class="btn" href="index.php">Zur Anmeldung</a>
<?php elseif (!$writable): ?>
  <h1>⚠️ Einrichtung nicht möglich</h1>
  <div class="err">Das App-Verzeichnis ist für PHP nicht beschreibbar.</div>
  <p>Bitte Schreibrechte für das Verzeichnis setzen oder <code>config.php</code> manuell aus <code>config.example.php</code> anlegen.</p>
<?php elseif ($expired): ?>
  <h1>🔒 Einrichtung gesperrt</h1>
  <div class="warn">Das Zeitfenster für die Einrichtung (<?= SETUP_WINDOW / 60 ?> Minuten) ist abgelaufen.</div>
  <p>Zum erneuten Öffnen die Datei <code><?= SETUP_MARKER ?></code> im App-Verzeichnis löschen (per SFTP oder Webspace-Explorer) und diese Seite neu laden.</p>
<?php else: ?>
  <h1>🛠️ Clipboard einrichten</h1>
  <p>Noch keine Konfiguration gefunden. Lege jetzt das Passwort fest. Das Setup legt <code>config.php</code>, die Datenablage und die Schutzregeln an.</p>
  <p class="hint">Noch <?= $remaining ?> Minute(n) verfügbar, danach wird das Setup gesperrt.</p>
  <?php foreach ($errors as $err): ?><div class="err" style="margin-top:.75rem"><?= $e($err) ?></div><?php endforeach; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($_SESSION['setup_csrf']) ?>">
    <label for="password">Passwort</label>
    <input type="password" id="password" name="password" minlength="<?= SETUP_MIN_PASSWORD ?>" required autofocus autocomplete="new-password">
    <div class="hint">Mindestens <?= SETUP_MIN_PASSWORD ?> Zeichen. Gespeichert wird nur ein Hash.</div>
    <label for="password2">Passwort wiederholen</label>
    <input type="password" id="password2" name="password2" minlength="<?= SETUP_MIN_PASSWORD ?>" required autocomplete="new-password">
    <label for="ttl">Lebensdauer hochgeladener Dateien (Tage)</label>
    <input type="number" id="ttl" name="file_ttl_days" min="1" max="365" value="<?= $e($values['file_ttl_days']) ?>" required>
    <label for="max">Maximale Anzahl Text-Einträge</label>
    <input type="number" id="max" name="max_entries" min="1" max="1000" value="<?= $e($values['max_entries']) ?>" required>
    <label class="check"><input type="checkbox" name="confirm_delete" <?= $values['confirm_delete'] ? 'checked' : '' ?>> Vor dem Löschen nachfragen</label>
    <button class="btn" type="submit">Einrichten</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
<?php
}
