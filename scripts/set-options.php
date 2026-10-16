<?php

/**
 * Writes options of the component in a LOCAL development site, the way the form of the options
 * does. Options that are not named stay as they are.
 *
 * Usage: php set-options.php --root=/path/to/site [--site-domains=a.com,b.com] [--localize-hosts=c.com]
 *                            [--localize-on-save=0] [--base-path=/folder]
 *                            [--convert-format=avif|webp|off] [--convert-program=/path/to/avifenc]
 *                            [--convert-on-save=0] [--convert-batch=20]
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', [
    'root:', 'site-domains::', 'localize-hosts::', 'localize-on-save::', 'base-path::',
    'convert-format::', 'convert-program::', 'convert-on-save::', 'convert-batch::',
]);
$root    = rtrim($options['root'] ?? '', '/');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

require_once $root . '/configuration.php';

$config          = new JConfig();
[$host, $port]   = array_pad(explode(':', $config->host), 2, 3306);
$db              = new mysqli($host, $config->user, $config->password, $config->db, (int) $port);
$db->set_charset('utf8mb4');

$table = $config->dbprefix . 'extensions';
$row   = $db->query("SELECT extension_id, params FROM `$table` WHERE type = 'component' AND element = 'com_mediaaudit'")->fetch_assoc();

if (!$row) {
    fwrite(STDERR, "com_mediaaudit is not installed in $root.\n");
    exit(1);
}

$params = json_decode($row['params'] ?: '{}', true) ?: [];
$lines  = static fn (string $list): string => implode("\n", array_filter(array_map('trim', explode(',', $list))));

foreach (['site-domains' => 'site_domains', 'localize-hosts' => 'localize_hosts'] as $option => $name) {
    if (isset($options[$option])) {
        $params[$name] = $lines((string) $options[$option]);
    }
}

if (isset($options['localize-on-save'])) {
    $params['localize_on_save'] = (int) $options['localize-on-save'];
}

if (isset($options['base-path'])) {
    $params['base_path'] = (string) $options['base-path'];
}

if (isset($options['convert-format'])) {
    $params['convert_format'] = \in_array($options['convert-format'], ['avif', 'webp'], true) ? $options['convert-format'] : '';
}

if (isset($options['convert-program'])) {
    $params['convert_program'] = (string) $options['convert-program'];
}

foreach (['convert-on-save' => 'convert_on_save', 'convert-batch' => 'convert_batch'] as $option => $name) {
    if (isset($options[$option])) {
        $params[$name] = (int) $options[$option];
    }
}

$statement = $db->prepare("UPDATE `$table` SET params = ? WHERE extension_id = ?");
$encoded   = json_encode($params);
$id        = (int) $row['extension_id'];

$statement->bind_param('si', $encoded, $id);
$statement->execute();

echo json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
