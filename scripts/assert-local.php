<?php

/**
 * Stops with exit code 1 unless the Joomla site at the given root uses a database on this machine.
 * The development scripts call it first, so that they can never touch a production site.
 *
 * Usage: php assert-local.php /path/to/site
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = rtrim($argv[1] ?? '', '/');

if ($root === '' || !is_file($root . '/configuration.php')) {
    fwrite(STDERR, "No configuration.php in '$root'.\n");
    exit(1);
}

require_once $root . '/configuration.php';

$host = strtolower((new JConfig())->host);

// Strip the port or socket part: "127.0.0.1:3306", "localhost:/tmp/mysql.sock", "[::1]:3306"
$name = preg_match('/^\[(.+)\]/', $host, $m) ? $m[1] : explode(':', $host)[0];

if (!\in_array($name, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing to run: the database host of $root is '$name', not this machine.\n");
    exit(1);
}
