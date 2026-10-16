<?php

/**
 * Boots the component and the plugins of Media Audit in a LOCAL development site, to see that
 * everything that was installed can be loaded.
 *
 * Usage: php smoke.php [--root=/path/to/site] [--domain=site.test]
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Joomla\CMS\Factory;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root::', 'domain::']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

// What ConsoleApplication::populateHttpHost() does when the application is executed
$_SERVER['HTTP_HOST']   = $options['domain'] ?? (getenv('MEDIAAUDIT_DOMAIN') ?: 'localhost');
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF']    = '/index.php';
$_SERVER['REQUEST_URI'] = '/';

const _JEXEC = 1;
\define('JPATH_BASE', $root);

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app                  = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();

$failed  = 0;
$classes = [
    \Gss\Component\Mediaaudit\Administrator\Controller\DisplayController::class,
    \Gss\Component\Mediaaudit\Administrator\View\Dashboard\HtmlView::class,
];

echo 'component    ', \get_class($app->bootComponent('com_mediaaudit')), PHP_EOL;

foreach ($classes as $class) {
    $found   = class_exists($class);
    $failed += $found ? 0 : 1;
    echo $found ? 'class        ' : 'MISSING      ', $class, PHP_EOL;
}

foreach (['console', 'task', 'system', 'webservices'] as $group) {
    $plugin  = $app->bootPlugin('mediaaudit', $group);
    $ours    = str_starts_with(\get_class($plugin), 'Gss\\Plugin\\');
    $failed += $ours ? 0 : 1;
    echo str_pad($group, 13), $ours ? \get_class($plugin) : 'NOT LOADED', PHP_EOL;
}

foreach (['falang', 'flexicontent', 'easyblog'] as $name) {
    $plugin  = $app->bootPlugin($name, 'mediaaudit');
    $ours    = str_starts_with(\get_class($plugin), 'Gss\\Plugin\\Mediaaudit\\');
    $failed += $ours ? 0 : 1;
    echo str_pad('mediaaudit', 13), $ours ? \get_class($plugin) : 'NOT LOADED: ' . $name, PHP_EOL;
}

exit($failed ? 1 : 0);
