<?php
// Copy to config.php or let the installer generate it.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'forlover_v2',
        'user' => 'forlover',
        'pass' => 'change-me',
    ],
    'salt' => 'replace-with-random-string',
    // Optional: one-click update channels (primary first, fallback second).
    // 'update_channels' => ['https://api.mao.ms/v1/forlover', 'https://api.cxcx.club/v1/forlover'],
];
