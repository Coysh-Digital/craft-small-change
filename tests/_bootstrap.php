<?php

declare(strict_types=1);

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');

require_once CRAFT_VENDOR_PATH . '/autoload.php';

// tests/.env supplies the database settings locally; variables already in the environment win, as in CI.
if (class_exists(Dotenv\Dotenv::class) && is_file(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->load();
}

TestSetup::configureCraft();
