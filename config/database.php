<?php

declare(strict_types=1);

return [
    'host' => getenv('WTF_CODE_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('WTF_CODE_DB_PORT') ?: '3306',
    'database' => getenv('WTF_CODE_DB_NAME') ?: 'wtfcode',
    'username' => getenv('WTF_CODE_DB_USER') ?: 'root',
    'password' => getenv('WTF_CODE_DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'app_url' => rtrim(getenv('WTF_CODE_APP_URL') ?: '', '/'),
    'environment' => getenv('WTF_CODE_ENV') ?: 'local',
];

