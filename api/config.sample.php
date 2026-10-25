<?php
/**
 * FINATUM PropertyBank — configuration.
 *
 * install.php writes this file for you. If you prefer to configure by hand,
 * copy it to config.php and fill in your database credentials.
 */

return [
    // 'mysql' on WINT.GLOBAL, 'sqlite' for local evaluation.
    'driver'   => 'mysql',

    // MySQL / MariaDB connection (cPanel > MySQL Databases).
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => '',
    'username' => '',
    'password' => '',
    'charset'  => 'utf8mb4',

    // PropertyBank has a database of its own, so this stays empty. Only when it
    // has to share one, use a prefix such as 'pb_'. Fixed at installation.
    'table_prefix' => '',

    // Used only when driver is 'sqlite'.
    'sqlite_path' => __DIR__ . '/../data/propertybank.sqlite',

    'app_name' => 'FINATUM PropertyBank',
    'timezone' => 'Europe/Berlin',

    // Session cookie name. Different from the other FINATUM tools', so they never collide.
    'session_name' => 'pbsid',
    'cookie_path'  => '',

    // For local testing only: send every outbound request (OLX, Nominatim,
    // ECB, NBU) to this base URL instead — see tools/mock-sources.php.
    // 'sources_mock' => 'http://127.0.0.1:8099/',

    'debug' => false,
];
