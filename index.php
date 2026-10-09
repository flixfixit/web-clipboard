<?php
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
];

$localConfigFile = __DIR__ . '/config.php';

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

// ── Session / Auth ───────────────────────────────────────────────────────────
session_name(SESSION_NAME);
session_start();

$authenticated = PIN_CONFIGURED && is_string($_SESSION['auth'] ?? null) && hash_equals(AUTH_TOKEN, $_SESSION['auth']);

if (!PIN_CONFIGURED) {
    $loginError = 'Kein PIN konfiguriert. Bitte in config.php (oder per CLIPBOARD_PIN) einen eigenen PIN setzen.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    if (checkPin((string)$_POST['pin'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = AUTH_TOKEN;
        $authenticated = true;
    } else {
        $loginError = 'Falscher PIN.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
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
    cleanupFiles();

    // Download a stored file (streamed through PHP so the PIN protects it)
    if (isset($_GET['download'])) {
        $files = loadFiles();
        $f = null;
        foreach ($files as $cand) { if ($cand['id'] === $_GET['download']) { $f = $cand; break; } }
        $path = $f ? UPLOAD_DIR . '/' . $f['stored'] : null;
        if ($f && $path && is_file($path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . addslashes($f['name']) . '"');
            header('Cache-Control: no-store');
            readfile($path);
        } else {
            http_response_code(404);
            echo 'Datei nicht gefunden.';
        }
        exit;
    }

    // Chunked upload: receive one piece at a time, assemble on the final chunk.
    // Keeps every request small so server/proxy size limits are never hit.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chunked'])) {
        header('Content-Type: application/json; charset=utf-8');
        $uploadId = preg_replace('/[^a-f0-9]/', '', $_POST['uploadId'] ?? '');
        $index    = (int)($_POST['chunkIndex'] ?? -1);
        $total    = (int)($_POST['totalChunks'] ?? 0);
        $name     = basename($_POST['fileName'] ?? '');

        if ($uploadId === '' || $index < 0 || $total < 1
            || !isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Chunk.']);
            exit;
        }

        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
        $tmpDir = UPLOAD_DIR . '/.tmp_' . $uploadId;
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        move_uploaded_file($_FILES['chunk']['tmp_name'], $tmpDir . '/' . $index);

        // Not the last chunk → acknowledge and wait for the next one
        if ($index < $total - 1) {
            echo json_encode(['ok' => true, 'done' => false]);
            exit;
        }

        // Final chunk → concatenate all parts in order into the stored file
        $stored = bin2hex(random_bytes(16));
        $out = fopen(UPLOAD_DIR . '/' . $stored, 'wb');
        for ($i = 0; $i < $total; $i++) {
            $part = $tmpDir . '/' . $i;
            if (!is_file($part)) {
                fclose($out);
                @unlink(UPLOAD_DIR . '/' . $stored);
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Fehlender Chunk ' . $i . '.']);
                exit;
            }
            $in = fopen($part, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            @unlink($part);
        }
        fclose($out);
        @rmdir($tmpDir);

        $files = loadFiles();
        array_unshift($files, [
            'id'     => uniqid('', true),
            'name'   => $name !== '' ? $name : 'upload.bin',
            'stored' => $stored,
            'size'   => filesize(UPLOAD_DIR . '/' . $stored),
            'ts'     => time(),
        ]);
        saveFiles($files);
        echo json_encode(['ok' => true, 'done' => true]);
        exit;
    }

    // Upload one or more files (non-JS fallback, single request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload'])) {
        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
        $up = $_FILES['upload'];
        // Normalise to arrays (single vs. multiple)
        $names = is_array($up['name']) ? $up['name'] : [$up['name']];
        $tmps  = is_array($up['tmp_name']) ? $up['tmp_name'] : [$up['tmp_name']];
        $errs  = is_array($up['error']) ? $up['error'] : [$up['error']];
        $files = loadFiles();
        $count = 0;
        foreach ($names as $i => $origName) {
            if ($errs[$i] === UPLOAD_ERR_NO_FILE) continue;
            if ($errs[$i] !== UPLOAD_ERR_OK) {
                $error = 'Upload fehlgeschlagen (evtl. zu groß für die Server-Grenze).';
                continue;
            }
            $stored = bin2hex(random_bytes(16));
            if (move_uploaded_file($tmps[$i], UPLOAD_DIR . '/' . $stored)) {
                array_unshift($files, [
                    'id'     => uniqid('', true),
                    'name'   => basename($origName),
                    'stored' => $stored,
                    'size'   => filesize(UPLOAD_DIR . '/' . $stored),
                    'ts'     => time(),
                ]);
                $count++;
            }
        }
        if ($count > 0) saveFiles($files);

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
        $files = loadFiles();
        $kept = [];
        foreach ($files as $f) {
            if ($f['id'] === $_GET['delfile']) { @unlink(UPLOAD_DIR . '/' . $f['stored']); }
            else { $kept[] = $f; }
        }
        saveFiles($kept);
        header('Location: index.php');
        exit;
    }

    // Add
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['text'])) {
        $text = trim($_POST['text']);
        if ($text !== '') {
            $entries = loadEntries();
            array_unshift($entries, [
                'id'   => uniqid('', true),
                'text' => $text,
                'ts'   => time(),
            ]);
            $entries = array_slice($entries, 0, MAX_ENTRIES);
            saveEntries($entries);
            $message = 'Gespeichert.';
        }
    }

    // Delete
    if (isset($_GET['delete'])) {
        $id      = $_GET['delete'];
        $entries = loadEntries();
        $entries = array_values(array_filter($entries, fn($e) => $e['id'] !== $id));
        saveEntries($entries);
        header('Location: index.php');
        exit;
    }

    // Clear all: texts, file index and every blob under uploads/ (incl. orphans)
    if (isset($_GET['clearall'])) {
        saveEntries([]);
        saveFiles([]);
        $remaining = purgeUploads();
        header('Location: index.php' . ($remaining ? '?err=purge' : ''));
        exit;
    }

    $entries = loadEntries();
    $files   = loadFiles();

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
        <a class="btn btn-secondary" href="?clearall" onclick="return confirm('Alle Einträge UND Dateien löschen?')">Alle löschen</a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="?logout">Abmelden</a>
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
      <textarea name="text" placeholder="Text hier einfügen …" autofocus></textarea>
      <div class="row">
        <button class="btn btn-primary" type="submit">Speichern</button>
      </div>
    </form>
  </div>

  <div class="card">
    <form method="post" enctype="multipart/form-data" id="uploadForm">
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
          <a class="icon-btn icon-btn-danger" href="?delete=<?= urlencode($e['id']) ?>" onclick="return confirmDelete('Löschen?')" title="Löschen" aria-label="Löschen"><?= $trashSvg ?></a>
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
        <a class="btn btn-danger" href="?delfile=<?= urlencode($f['id']) ?>" onclick="return confirmDelete('Datei löschen?')">Löschen</a>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

<script>
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
          <a class="icon-btn icon-btn-danger" href="?delete=${encodeURIComponent(e.id)}" onclick="return confirmDelete('Löschen?')" title="Löschen" aria-label="Löschen">${ICON_TRASH}</a>
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
        <a class="btn btn-danger" href="?delfile=${encodeURIComponent(f.id)}" onclick="return confirmDelete('Datei löschen?')">Löschen</a>
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
