<?php

/**
 * Checks what the system plugin does when content is saved, in the LOCAL development site.
 *
 * Takes the first article of the fixtures, adds an external image to it, and saves it the way the
 * editor does: event before, store, event after. Only the plugin of Media Audit gets the events, so
 * nothing else of the site reacts. The article is put back as it was at the end.
 *
 * Usage: php verify-on-save.php [--root=/path/to/site] [--url=https://cdn.example.org/a.png]
 *
 * Without --url, takes an image that has a copy and forgets that it has, so that it is downloaded
 * again. The file of the copy stays where it is.
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Content;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root::', 'url::']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

$_SERVER['HTTP_HOST']   = getenv('MEDIAAUDIT_DOMAIN') ?: 'localhost';
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

/** @var \Joomla\Database\DatabaseDriver $db */
$db = $container->get(DatabaseInterface::class);

$failed = 0;
$check  = static function (string $what, bool $ok, string $detail = '') use (&$failed): void {
    $failed += $ok ? 0 : 1;
    echo $ok ? 'ok      ' : 'FAILED  ', $what, $detail === '' ? '' : '  (' . $detail . ')', PHP_EOL;
};
$one = static function (string $sql) use ($db) {
    return $db->setQuery($sql)->loadResult();
};

// A user to save as
$userId = (int) $one('SELECT MIN(u.id) FROM #__users u INNER JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0');
$user   = $container->get(UserFactoryInterface::class)->loadUserById($userId);
$app->loadIdentity($user);

// The plugin, listening to a dispatcher that nothing else listens to
$dispatcher = new Dispatcher();
$plugin     = $app->bootPlugin('mediaaudit', 'system');

$check('the system plugin is installed', str_starts_with(\get_class($plugin), 'Gss\\Plugin\\System\\'));

if ($failed) {
    exit(1);
}

$plugin->setDispatcher($dispatcher);
$plugin->registerListeners();

// Whatever the options of the site say: converting adds files next to the images of the fixtures
\Joomla\CMS\Component\ComponentHelper::getParams('com_mediaaudit')->set('convert_format', '');

// The image
$url = $options['url'] ?? null;

if ($url === null) {
    $row = $db->setQuery('SELECT id, url, url_hash, local_path FROM #__mediaaudit_urls WHERE local_path IS NOT NULL ORDER BY size ASC, id ASC', 0, 1)->loadObject();

    if (!$row) {
        fwrite(STDERR, "No external image has a copy yet. Run mediaaudit:localize first, or give --url.\n");
        exit(1);
    }

    $url = $row->url;
    $db->setQuery('DELETE FROM #__mediaaudit_urls WHERE id = ' . (int) $row->id)->execute();
    echo 'image   ', $url, PHP_EOL;
}

$hash = sha1($url);

// The article
$table = new Content($db);
$table->setCurrentUser($user);

if (!$table->load(['alias' => 'mediaaudit-fixture-01'])) {
    fwrite(STDERR, "The fixtures are not there. Run seed-fixtures.php first.\n");
    exit(1);
}

$id       = (int) $table->id;
$original = $table->introtext;
$backups  = (int) $one('SELECT COALESCE(MAX(id), 0) FROM #__mediaaudit_rewrites');

$save = static function (Content $table) use ($dispatcher): void {
    $dispatcher->dispatch('onContentBeforeSave', new BeforeSaveEvent('onContentBeforeSave', [
        'context' => 'com_content.article',
        'subject' => $table,
        'isNew'   => false,
        'data'    => [],
    ]));

    if (!$table->store()) {
        throw new \RuntimeException($table->getError());
    }

    $dispatcher->dispatch('onContentAfterSave', new AfterSaveEvent('onContentAfterSave', [
        'context' => 'com_content.article',
        'subject' => $table,
        'isNew'   => false,
        'data'    => [],
    ]));
};

try {
    $added            = '<p><img src="' . htmlspecialchars($url) . '" alt="pasted"><a href="https://www.example.org/">a link, not an image</a></p>';
    $table->introtext = $original . $added;

    $start = microtime(true);
    $save($table);
    $seconds = round(microtime(true) - $start, 2);

    $stored = (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id);
    $copy   = $one('SELECT local_path FROM #__mediaaudit_urls WHERE url_hash = ' . $db->quote($hash));

    $check('the image was copied', $copy !== null && is_file($root . '/' . $copy), (string) $copy . ', ' . $seconds . ' s');
    $check('the article loads the copy', $copy !== null && str_contains($stored, '<img src="' . $copy . '" alt="pasted">'));
    $check('the article does not load the external image', !str_contains($stored, 'src="' . htmlspecialchars($url) . '"'));
    $check('the link that is not an image stays', str_contains($stored, '<a href="https://www.example.org/">'));
    $check('the rest of the text stays', str_starts_with($stored, $original));

    $reference = $copy === null ? null : $db->setQuery(
        'SELECT exists_local, is_external, source_state FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article')
        . ' AND source_id = ' . $id . ' AND path_hash = ' . $db->quote(sha1((string) $copy))
    )->loadObject();

    $check('the index has the reference to the copy', $reference !== null && (int) $reference->exists_local === 1 && (int) $reference->is_external === 0);
    $check(
        'the index has no reference to the external image',
        (int) $one('SELECT COUNT(*) FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article') . ' AND source_id = ' . $id . ' AND is_external = 1') === 0
    );
    $check(
        'the index of the files has the copy',
        $copy !== null && (int) $one('SELECT COUNT(*) FROM #__mediaaudit_files WHERE path_hash = ' . $db->quote(sha1((string) $copy)) . ' AND status = ' . $db->quote('active')) === 1
    );

    $backup = $db->setQuery('SELECT * FROM #__mediaaudit_rewrites WHERE id > ' . $backups . ' AND source_id = ' . $id)->loadObject();

    $check('the text before the change is kept', $backup !== null && $backup->original === $original . $added && $backup->rewritten_hash === sha1($stored));
    $check('the change is in the name of the user', $backup !== null && (int) $backup->rewritten_by === $userId);

    // Custom fields are written by another plugin, after the event: their references are read later
    $fields = 'source_type = ' . $db->quote('article_field') . ' AND source_id = ' . $id;

    $db->setQuery('DELETE FROM #__mediaaudit_refs WHERE ' . $fields)->execute();
    $save($table);

    $check('custom fields wait for the end of the request', (int) $one('SELECT COUNT(*) FROM #__mediaaudit_refs WHERE ' . $fields) === 0);

    $plugin->flush();

    $check(
        'custom fields are in the index at the end of the request',
        $db->setQuery('SELECT path FROM #__mediaaudit_refs WHERE ' . $fields . ' AND exists_local = 1 ORDER BY path')->loadColumn()
            === ['images/_mediaaudit_fixtures/field.jpg', 'images/_mediaaudit_fixtures/field2.jpg']
    );

    // State: unpublish in a list
    $dispatcher->dispatch('onContentChangeState', new \Joomla\CMS\Event\Model\AfterChangeStateEvent('onContentChangeState', [
        'context' => 'com_content.article',
        'subject' => [$id],
        'value'   => 0,
    ]));

    $check(
        'a change of state reaches the index',
        (int) $one('SELECT COUNT(*) FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article') . ' AND source_id = ' . $id . ' AND source_state <> 0') === 0
    );
} finally {
    // As it was
    $table->introtext = $original;
    $save($table);

    $db->setQuery('DELETE FROM #__mediaaudit_rewrites WHERE id > ' . $backups . ' AND source_id = ' . $id)->execute();
}

$check('the article is as it was', (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id) === $original);
$plugin->flush();

$check(
    'the index is as it was',
    (int) $one('SELECT COUNT(*) FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article') . ' AND source_id = ' . $id) === 1
    && (int) $one('SELECT COUNT(*) FROM #__mediaaudit_refs WHERE source_id = ' . $id . ' AND source_type IN (' . $db->quote('article') . ',' . $db->quote('article_field') . ') AND source_state = 1') === 3
);

echo $failed ? "FAILED: $failed\n" : "OK\n";
exit($failed ? 1 : 0);
