<?php

return [
    // Set your own PIN here or via the CLIPBOARD_PIN environment variable.
    // The placeholder below is rejected: login stays locked until it is changed.
    'pin' => getenv('CLIPBOARD_PIN') ?: 'replace-with-a-long-random-pin',

    // Runtime storage. Keep these files out of git and, if possible, outside
    // the public web root. The defaults work for small shared-hosting setups.
    'data_file' => __DIR__ . '/data.json',
    'files_file' => __DIR__ . '/files.json',
    'upload_dir' => __DIR__ . '/uploads',

    'max_entries' => 50,
    'file_ttl_days' => 7,
    'confirm_delete' => false,
    'session_name' => 'clipboard_session',
];
