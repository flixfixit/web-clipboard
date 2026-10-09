<?php
// Local development config. dev/serve.ps1 creates a config.php that loads this
// file if none exists yet. Runtime data goes to .devdata/ (ignored by git), so
// test data never mixes with the default data.json/files.json/uploads paths.
$devData = dirname(__DIR__) . '/.devdata';

return [
    // Test PIN for local development only.
    'pin' => getenv('CLIPBOARD_PIN') ?: 'dev-pin-4711',

    'data_file' => $devData . '/data.json',
    'files_file' => $devData . '/files.json',
    'upload_dir' => $devData . '/uploads',

    'max_entries' => 50,
    'file_ttl_days' => 7,
    'confirm_delete' => false,
    'session_name' => 'clipboard_dev_session',
];
