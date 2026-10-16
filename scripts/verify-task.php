<?php

/**
 * Runs the routine of the scheduled task in the LOCAL development site, and prints the summary that
 * it would send. Sends nothing.
 *
 * Usage: php verify-task.php [--root=/path/to/site] [--language=zh-TW] [--check-external]
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root::', 'language::', 'check-external']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

$_SERVER['HTTP_HOST']   = getenv('MEDIAAUDIT_DOMAIN') ?: 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF']    = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS']       = 'on';

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

if (isset($options['language'])) {
    $language = $container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage($options['language']);
    Factory::$language = $language;

    if (method_exists($app, 'setLanguage')) {
        $app->setLanguage($language);
    }
}

$plugin = $app->bootPlugin('mediaaudit', 'task');

if (!str_starts_with(\get_class($plugin), 'Gss\\Plugin\\Task\\')) {
    fwrite(STDERR, "The task plugin is not installed.\n");
    exit(1);
}

$params = ComponentHelper::getParams('com_mediaaudit');
$runner = ScanRunner::create($container->get(DatabaseInterface::class), $params, JPATH_ROOT, $app->getDispatcher(), (string) $app->get('tmp_path'));
$task   = (object) [
    'localize'       => 1,
    'localize_limit' => 5,
    'check_external' => isset($options['check-external']) ? 1 : 0,
    'check_limit'    => 10,
    'recheck_days'   => 0,
];

$result = $plugin->run($runner, $task, $params);
$mail   = $plugin->compose($result);

echo 'Subject: ', $mail['subject'], PHP_EOL, PHP_EOL, $mail['body'];

$ok = $result['summary']['files'] > 0 && str_contains($mail['body'], (string) number_format($result['summary']['files']));

exit($ok ? 0 : 1);
