<?php

/**
 * Checks that files of the quarantine are deleted for good only when every condition is met, in the
 * LOCAL development site.
 *
 * Works on a file of its own, a copy of the orphan of the fixtures, and leaves the fixtures as they
 * were. The one file that this script deletes is that copy.
 *
 * Usage: php verify-delete.php [--root=/path/to/site]
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Gss\Component\Mediaaudit\Administrator\Service\QuarantineService;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

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

$userId = (int) $one('SELECT MIN(u.id) FROM #__users u INNER JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0');
$user   = $container->get(UserFactoryInterface::class)->loadUserById($userId);
$app->loadIdentity($user);

$params = ComponentHelper::getParams('com_mediaaudit');
$runner = ScanRunner::create($db, $params, JPATH_ROOT, $app->getDispatcher(), (string) $app->get('tmp_path'));
$folder = 'images/_mediaaudit_fixtures';
$path   = $folder . '/verify-delete.jpg';
$other  = $folder . '/orphan.jpg';

$snapshot = static function () use ($root, $path): array {
    $files = [];

    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/images', \FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), \strlen($root) + 1);

        if ($relative !== $path && !str_starts_with($relative, 'images/_mediaaudit_quarantine/')) {
            $files[$relative] = $file->getSize() . ':' . $file->getMTime();
        }
    }

    ksort($files);

    return $files;
};

$before = $snapshot();
$record = 0;

// A file of our own: the orphan with one byte more, so that it is no duplicate of it
file_put_contents($root . '/' . $path, file_get_contents($root . '/' . $other) . "\0");

$sha1 = sha1_file($root . '/' . $path);

$ago = static function (int $id, int $days) use ($db): void {
    $db->setQuery('UPDATE #__mediaaudit_quarantine SET moved_at = ' . $db->quote(gmdate('Y-m-d H:i:s', time() - $days * 86400)) . ' WHERE id = ' . $id)->execute();
};
$reason = static fn (array $result): string => $result['refused'][0]['reason'] ?? ($result['deleted'] ? 'deleted it' : 'nothing');

try {
    $scan = $runner->run(true);
    $runner->getHistory()->record('cli', (float) $scan['seconds'], $runner->getReport()->summary());

    $service = $runner->getQuarantine();
    $days    = $service->getDeleteAfterDays();
    $moved   = $service->move([$path], $userId, 'verify-delete.php', $user->username)['moved'][0] ?? null;
    $record  = (int) ($moved['id'] ?? 0);
    $held    = $root . '/' . ($moved['quarantine_path'] ?? '-');

    $check('the file is in the quarantine', $record > 0 && is_file($held) && !file_exists($root . '/' . $path));

    // ---------------------------------------------------------------------------------------------
    echo "--- the days\n";

    $check('the options ask for at least a day', $days >= 1, $days . ' days');

    $result = $service->delete([$record], $userId, $user->username);

    $check('not on the day it was moved', $reason($result) === QuarantineService::REASON_TOO_RECENT && is_file($held));

    $ago($record, $days - 1);
    $result = $service->delete([$record], $userId, $user->username);

    $check('not a day before the days are over', $reason($result) === QuarantineService::REASON_TOO_RECENT && is_file($held));

    $preview = $service->preview([$record])[0] ?? [];

    $check(
        'the page that asks names the day',
        ($preview['deletable'] ?? true) === false && ($preview['reason'] ?? '') === QuarantineService::REASON_TOO_RECENT
        && abs(strtotime($preview['deletable_at'] . ' UTC') - (time() + 86400)) < 60
    );

    $never = new QuarantineService($db, JPATH_ROOT, $runner->getHistory(), 24, null, 0);

    $check('options that ask for no days at all still ask for one', $never->getDeleteAfterDays() === 1);

    $ago($record, $days + 1);

    // ---------------------------------------------------------------------------------------------
    echo "--- content that refers to the file\n";

    $reference = static function (string $to, string $field = 'introtext') use ($db): void {
        $row = (object) [
            'path'         => $to,
            'path_hash'    => sha1($to),
            'path_ci_hash' => sha1(mb_strtolower($to)),
            'raw'          => $to,
            'is_external'  => 0,
            'source_type'  => 'verify',
            'source_id'    => 1,
            'field'        => $field,
            'source_state' => 0,
            'exists_local' => 0,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ];

        $db->insertObject('#__mediaaudit_refs', $row);
    };
    $forget = static function () use ($db): void {
        $db->setQuery('DELETE FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('verify'))->execute();
    };

    // Something began to refer to the file while it was in quarantine, even if it is not published
    $reference($path);
    $result = $service->delete([$record], $userId, $user->username);

    $check('refused when content refers to where the file was', $reason($result) === QuarantineService::REASON_REFERENCED && is_file($held));
    $forget();

    $reference($folder . '/Verify-Delete.JPG');
    $result = $service->delete([$record], $userId, $user->username);

    $check('refused when content refers to it in other capitals', $reason($result) === QuarantineService::REASON_OTHER_CASE && is_file($held));
    $forget();

    // ---------------------------------------------------------------------------------------------
    echo "--- a scan that is too old\n";

    $last = (int) $one('SELECT MAX(id) FROM #__mediaaudit_scans');
    $time = $one('SELECT scanned_at FROM #__mediaaudit_scans WHERE id = ' . $last);

    $db->setQuery('UPDATE #__mediaaudit_scans SET scanned_at = ' . $db->quote(gmdate('Y-m-d H:i:s', time() - 3 * 86400)) . ' WHERE id = ' . $last)->execute();

    $result = $service->delete([$record], $userId, $user->username);

    $check('refused', $reason($result) === QuarantineService::REASON_SCAN_TOO_OLD && is_file($held));

    $db->setQuery('UPDATE #__mediaaudit_scans SET scanned_at = ' . $db->quote($time) . ' WHERE id = ' . $last)->execute();

    // ---------------------------------------------------------------------------------------------
    echo "--- records that do not say what they should\n";

    $real = $one('SELECT quarantine_path FROM #__mediaaudit_quarantine WHERE id = ' . $record);

    foreach ([$other, 'configuration.php', 'images/_mediaaudit_quarantine/../' . basename($folder) . '/orphan.jpg'] as $target) {
        $db->setQuery('UPDATE #__mediaaudit_quarantine SET quarantine_path = ' . $db->quote($target) . ' WHERE id = ' . $record)->execute();

        $result = $service->delete([$record], $userId, $user->username);

        $check('a record that points to ' . $target, $reason($result) === QuarantineService::REASON_NOT_HELD && is_file($held));
    }

    $db->setQuery('UPDATE #__mediaaudit_quarantine SET quarantine_path = ' . $db->quote($real) . ' WHERE id = ' . $record)->execute();

    $check('nothing outside the quarantine was touched', is_file($root . '/' . $other) && is_file($root . '/configuration.php') && $snapshot() === $before);

    file_put_contents($held, 'another file');
    $result = $service->delete([$record], $userId, $user->username);

    $check('a file that is not the one that was moved', $reason($result) === QuarantineService::REASON_CHANGED && is_file($held));

    file_put_contents($held, file_get_contents($root . '/' . $other) . "\0");

    // ---------------------------------------------------------------------------------------------
    echo "--- who may delete\n";

    // Groups with all the rights of the site are Super Users for Joomla, whatever they are called
    $super  = [];
    $others = [];

    foreach ($db->setQuery('SELECT id, title FROM #__usergroups ORDER BY lft')->loadObjectList() as $group) {
        if (Access::checkGroup((int) $group->id, 'core.admin')) {
            $super[] = $group->title;
        } elseif (Access::checkGroup((int) $group->id, 'mediaaudit.delete', 'com_mediaaudit')) {
            $others[] = $group->title;
        }
    }

    $check('no group but the ones with all the rights may delete', $others === [], 'with all the rights: ' . implode(', ', $super));
    $check('a Super User may', (bool) $user->authorise('mediaaudit.delete', 'com_mediaaudit'));
    $check('a guest may not', !(new \Joomla\CMS\User\User())->authorise('mediaaudit.delete', 'com_mediaaudit'));
    $check(
        'the right to move files is not the right to delete them',
        \in_array('mediaaudit.delete', array_column(Access::getActionsFromFile($root . '/administrator/components/com_mediaaudit/access.xml', "/access/section[@name='component']/") ?: [], 'name'), true)
    );

    // ---------------------------------------------------------------------------------------------
    echo "--- deleted\n";

    $logs    = (int) $one('SELECT COUNT(*) FROM #__action_logs WHERE extension = ' . $db->quote('com_mediaaudit'));
    $preview = $service->preview([$record])[0] ?? [];

    $check('the page that asks would offer it', ($preview['deletable'] ?? false) === true && ($preview['size'] ?? 0) === filesize($held));

    $result = $service->delete([$record], $userId, $user->username);

    $check('deleted', ($result['deleted'][0]['id'] ?? 0) === $record && $result['refused'] === [], $reason($result));
    $check('the file is gone from the quarantine', !file_exists($held));
    $check('and was not put anywhere else', !file_exists($root . '/' . $path));

    $row = $db->setQuery('SELECT * FROM #__mediaaudit_quarantine WHERE id = ' . $record)->loadObject();

    $check('the record says when and by whom', $row->deleted_at !== null && (int) $row->deleted_by === $userId && $row->restored_at === null && $row->sha1 === $sha1);
    $check('the index has the file no more', (int) $one('SELECT COUNT(*) FROM #__mediaaudit_files WHERE path = ' . $db->quote($path)) === 0);

    $log = $db->setQuery('SELECT * FROM #__action_logs WHERE extension = ' . $db->quote('com_mediaaudit') . ' ORDER BY id DESC', 0, 1)->loadObject();

    $check(
        'the log of the actions',
        (int) $one('SELECT COUNT(*) FROM #__action_logs WHERE extension = ' . $db->quote('com_mediaaudit')) === $logs + 1
        && $log->message_language_key === 'COM_MEDIAAUDIT_ACTION_LOG_DELETED' && (int) $log->user_id === $userId
        && (json_decode($log->message, true)['path'] ?? '') === $path
    );

    $result = $service->delete([$record], $userId, $user->username);

    $check('it cannot be deleted twice', $reason($result) === QuarantineService::REASON_DELETED);

    $result = $service->restore([$record], $userId, $user->username);

    $check('and it cannot be put back', $result['restored'] === [] && ($result['refused'][0]['reason'] ?? '') === QuarantineService::REASON_DELETED);

    $factory = $app->bootComponent('com_mediaaudit')->getMVCFactory();
    $model   = $factory->createModel('Quarantine', 'Administrator', ['ignore_request' => true]);
    $model->setState('list.start', 0);
    $model->setState('list.limit', 0);
    $model->setState('filter.state', 'deleted');

    $check('the list of what was deleted has it', \in_array($record, array_map('intval', array_column($model->getItems() ?: [], 'id')), true));

    $model = $factory->createModel('Quarantine', 'Administrator', ['ignore_request' => true]);
    $model->setState('list.start', 0);
    $model->setState('list.limit', 0);

    $check('the list of what is held has it no more', !\in_array($record, array_map('intval', array_column($model->getItems() ?: [], 'id')), true));
} finally {
    $db->setQuery('DELETE FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('verify'))->execute();

    // The file of this script, wherever a failure has left it
    foreach ([$root . '/' . $path, $held ?? ''] as $file) {
        if ($file !== '' && is_file($file) && str_ends_with($file, '/verify-delete.jpg')) {
            unlink($file);
        }
    }

    $db->setQuery('DELETE FROM #__mediaaudit_files WHERE path = ' . $db->quote($path))->execute();
}

$check('every other file of images/ is as it was', $snapshot() === $before);

echo $failed ? "FAILED: $failed\n" : "OK\n";
exit($failed ? 1 : 0);
