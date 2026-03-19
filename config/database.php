<?php

return [
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'port'     => getenv('DB_PORT') ?: '5432',
    'dbname'   => getenv('DB_NAME') ?: 'orders',
    'user'     => getenv('DB_USER') ?: 'app',
    'password' => getenv('DB_PASSWORD') ?: 'secret',
];
