<?php

declare(strict_types=1);

use craft\helpers\App;

$dsn = (string) App::env('TEST_DB_DSN');

return [
    'dsn' => $dsn,
    // Craft picks the charset and schema defaults from the driver, which the DSN alone doesn't set.
    'driver' => str_starts_with($dsn, 'pgsql:') ? 'pgsql' : 'mysql',
    'user' => App::env('TEST_DB_USER'),
    'password' => App::env('TEST_DB_PASSWORD'),
    'schema' => App::env('TEST_DB_SCHEMA') ?: null,
    'tablePrefix' => App::env('TEST_DB_TABLE_PREFIX') ?: '',
];
