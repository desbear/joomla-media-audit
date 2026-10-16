<?php

/**
 * Renders the pages of the component as the administrator sees them, in the LOCAL development site,
 * without a browser and without a login: the component is dispatched for a Super User, and what it
 * prints is checked and written to a folder.
 *
 * Only the component runs. The template of the administrator, its modules and the plugins of the
 * site do not, so the pages come without menu and without style sheets.
 *
 * Usage: php render-admin.php [--root=/path/to/site] [--language=zh-TW] [--out=/folder]
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root::', 'language::', 'out::', 'domain::', 'set::']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');
$out     = isset($options['out']) ? rtrim($options['out'], '/') : null;
$domain  = $options['domain'] ?? (getenv('MEDIAAUDIT_DOMAIN') ?: 'localhost');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

$_SERVER['HTTP_HOST']      = $domain;
$_SERVER['HTTPS']          = 'on';
$_SERVER['SCRIPT_NAME']    = '/administrator/index.php';
$_SERVER['PHP_SELF']       = '/administrator/index.php';
$_SERVER['REQUEST_URI']    = '/administrator/index.php?option=com_mediaaudit';
$_SERVER['REQUEST_METHOD'] = 'GET';

const _JEXEC = 1;
\define('JPATH_BASE', $root . '/administrator');

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$container = Factory::getContainer();

// A session that lives in memory, and leaves nothing behind
$container->alias('session.web', 'session.cli')
    ->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$app                  = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
Factory::$application = $app;

$app->createExtensionNamespaceMap();

// What the application does when it runs: the site is one folder above the administrator
\Joomla\CMS\Uri\Uri::root(false, '');

$db     = $container->get(DatabaseInterface::class);
$userId = (int) $db->setQuery(
    'SELECT MIN(u.id) FROM #__users u INNER JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0'
)->loadResult();

$app->loadIdentity($container->get(UserFactoryInterface::class)->loadUserById($userId));

if (isset($options['language'])) {
    $language = $container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage($options['language']);
    $app->loadLanguage($language);
    Factory::$language = $language;
} else {
    $app->loadLanguage();
}

$app->getLanguage()->load('joomla', JPATH_ADMINISTRATOR);
$app->getLanguage()->load('com_mediaaudit', JPATH_ADMINISTRATOR . '/components/com_mediaaudit')
    || $app->getLanguage()->load('com_mediaaudit', JPATH_ADMINISTRATOR);

$app->loadDocument();

// The names of the classes of Joomla 3, which plugins of the site still use
\Joomla\CMS\Plugin\PluginHelper::importPlugin('behaviour');

// What ComponentHelper::renderComponent() defines before it dispatches a component
\define('JPATH_COMPONENT', JPATH_ADMINISTRATOR . '/components/com_mediaaudit');
\define('JPATH_COMPONENT_SITE', JPATH_SITE . '/components/com_mediaaudit');
\define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR . '/components/com_mediaaudit');

// Options of the component for this run only, like --set=convert_format=avif,convert_program=/path
foreach (array_filter(explode(',', (string) ($options['set'] ?? ''))) as $pair) {
    [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

    \Joomla\CMS\Component\ComponentHelper::getParams('com_mediaaudit')->set($name, $value);
}

$failed = 0;
$pages  = [
    'dashboard'   => [],
    'images'      => [],
    'images:png'  => ['filter' => ['type' => 'png', 'used' => '1']],
    'inuse'       => ['filter' => ['search' => '_mediaaudit_fixtures/']],
    'orphans'     => ['filter' => ['search' => '_mediaaudit_fixtures/']],
    'semiorphans' => ['filter' => ['search' => '_mediaaudit_fixtures/']],
    'broken'      => ['filter' => ['search' => '_mediaaudit_fixtures/']],
    'duplicates'  => ['filter' => ['search' => '_mediaaudit_fixtures/']],
    'duplicates:convert' => ['filter' => ['search' => '_mediaaudit_convert/']],
    'quarantine'  => ['filter' => ['state' => 'all']],
    'localized'   => [],
    'localized:waiting' => ['filter' => ['state' => 'waiting']],
    'localized:failed'  => ['filter' => ['state' => 'failed']],
    'rewrites'    => ['filter' => ['state' => 'all']],
    // With whatever the quarantine holds: the page asks, it deletes nothing
    'deleteconfirm' => ['state' => ['com_mediaaudit.delete.ids' => 'SELECT id FROM #__mediaaudit_quarantine ORDER BY id DESC']],
];

foreach ($pages as $page => $request) {
    // "view:name" is a view with other filters
    $view  = explode(':', $page)[0];
    $input = $app->getInput();

    $input->set('option', 'com_mediaaudit');
    $input->set('view', $view);
    $input->set('task', '');
    $input->set('filter', $request['filter'] ?? []);
    $input->set('limit', 50);

    foreach ($request['state'] ?? [] as $key => $query) {
        $app->setUserState($key, array_map('intval', $db->setQuery($query, 0, 5)->loadColumn()));
    }

    // The buttons of the pages before are not the ones of this page
    $before = \count(Toolbar::getInstance('toolbar')->getItems());

    ob_start();

    try {
        $app->bootComponent('com_mediaaudit')->getDispatcher($app)->dispatch();
        $html  = ob_get_clean();
        $error = '';
    } catch (\Throwable $e) {
        $html  = ob_get_clean();
        $error = \get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    }

    // What PHP prints when a template reads what is not there
    if ($error === '' && preg_match('/(?:Warning|Notice|Fatal error|Parse error)<\/b>:|^(?:PHP )?(?:Warning|Notice|Fatal error):/m', $html, $match)) {
        $position = strpos($html, $match[0]);
        $error    = trim(strip_tags(substr($html, max(0, $position - 10), 300)));
    }

    // A link that was escaped twice leads nowhere
    if ($error === '' && str_contains($html, '&amp;amp;')) {
        $error = 'escaped twice: ' . substr($html, max(0, strpos($html, '&amp;amp;') - 80), 120);
    }

    // Keys of the language that have no text
    if ($error === '' && preg_match_all('/\bCOM_MEDIAAUDIT_[A-Z0-9_]+/', strip_tags($html), $keys)) {
        $error = 'without text: ' . implode(', ', array_unique($keys[0]));
    }

    $rows    = substr_count($html, '<tr') - substr_count($html, '<thead') ;
    $buttons = array_map(
        // Buttons of ToolbarHelper are lists: kind, icon, icon, text or task, ...
        static fn ($button) => \is_array($button)
            ? (string) ($button[0] === 'Link' ? 'link' : ($button[0] === 'Popup' ? 'options' : ($button[3] ?? $button[0])))
            : ($button->getOption('task') ?: $button->getName()),
        \array_slice(Toolbar::getInstance('toolbar')->getItems(), $before)
    );
    $failed += $error === '' && $html !== '' ? 0 : 1;

    printf(
        "%s  %-18s %6d bytes, %2d rows of tables, toolbar: %s%s\n",
        $error === '' && $html !== '' ? 'ok    ' : 'FAILED',
        $page,
        \strlen($html),
        max(0, $rows),
        implode(' ', array_filter($buttons)) ?: '-',
        $error === '' ? '' : "\n        " . $error
    );

    if ($out !== null && (is_dir($out) || mkdir($out, 0755, true))) {
        $styles = '';

        foreach ($app->getDocument()->_style as $declarations) {
            $styles .= implode("\n", (array) $declarations);
        }

        file_put_contents(
            "$out/" . str_replace(':', '-', $page) . '.html',
            '<!DOCTYPE html><html lang="' . $app->getLanguage()->getTag() . '"><head><meta charset="utf-8"><base href="https://' . $domain . '/administrator/">'
            . '<title>Media Audit: ' . $page . '</title>'
            . '<link rel="stylesheet" href="https://' . $domain . '/media/templates/administrator/atum/css/template.min.css">'
            . '<link rel="stylesheet" href="https://' . $domain . '/media/vendor/fontawesome-free/css/fontawesome.min.css">'
            . '<style>body{padding:1.5rem}' . $styles . '</style></head><body class="admin com_mediaaudit">' . $html
            // The page of Joomla loads the scripts of the document; this one has the one script that there is
            . '<script>' . \Gss\Component\Mediaaudit\Administrator\Helper\ChartHelper::script() . '</script></body></html>'
        );
    }
}

echo $failed ? "FAILED: $failed\n" : "OK\n";
exit($failed ? 1 : 0);
