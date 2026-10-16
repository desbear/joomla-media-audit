<?php

/**
 * Checks the quarantine and the lists of the administrator, in the LOCAL development site.
 *
 * Moves the orphan of the fixtures to the quarantine and back, and tries to move what must not be
 * moved. Every file is where it was at the end. Nothing is deleted.
 *
 * Usage: php verify-quarantine.php [--root=/path/to/site] [--phase=4]
 *
 * --phase is the phase of the project that is installed, as for verify-fixtures.php.
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Gss\Component\Mediaaudit\Administrator\Controller\ExportController;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Gss\Component\Mediaaudit\Administrator\Service\QuarantineService;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root::', 'domain::', 'phase::']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');
$domain  = $options['domain'] ?? (getenv('MEDIAAUDIT_DOMAIN') ?: 'localhost');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

$_SERVER['HTTP_HOST']   = $domain;
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
$http = static function (string $path) use ($domain): int {
    $handle = curl_init('https://' . $domain . '/' . implode('/', array_map('rawurlencode', explode('/', $path))));
    curl_setopt_array($handle, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 15]);
    curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return $status;
};

$userId = (int) $one('SELECT MIN(u.id) FROM #__users u INNER JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0');
$user   = $container->get(UserFactoryInterface::class)->loadUserById($userId);
$app->loadIdentity($user);

$params = ComponentHelper::getParams('com_mediaaudit');
$runner = ScanRunner::create($db, $params, JPATH_ROOT, $app->getDispatcher(), (string) $app->get('tmp_path'));
$folder = 'images/_mediaaudit_fixtures';
$orphan = $folder . '/orphan.jpg';
$used   = $folder . '/a.jpg';

// Everything of images/ as it is now: names, sizes, contents of the fixtures
$snapshot = static function () use ($root, $folder): array {
    $files = [];

    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/images', \FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = substr($file->getPathname(), \strlen($root) + 1);

        if (!str_starts_with($path, 'images/_mediaaudit_quarantine/')) {
            $files[$path] = str_starts_with($path, $folder . '/') ? sha1_file($file->getPathname()) : $file->getSize();
        }
    }

    ksort($files);

    return $files;
};

$before = $snapshot();
$sha1   = sha1_file($root . '/' . $orphan);

// ---------------------------------------------------------------------------------------------
echo "--- a scan that is too old\n";

$scan = $runner->run(true);
$runner->getHistory()->record('cli', (float) $scan['seconds'], $runner->getReport()->summary());

$last = (int) $one('SELECT MAX(id) FROM #__mediaaudit_scans');
$time = $one('SELECT scanned_at FROM #__mediaaudit_scans WHERE id = ' . $last);

$db->setQuery('UPDATE #__mediaaudit_scans SET scanned_at = ' . $db->quote(gmdate('Y-m-d H:i:s', time() - 3 * 86400)) . ' WHERE id = ' . $last)->execute();

$result = $runner->getQuarantine()->move([$orphan], $userId, '', $user->username);

$check('refused', !$result['moved'] && ($result['refused'][0]['reason'] ?? '') === QuarantineService::REASON_SCAN_TOO_OLD);
$check('the file is where it was', is_file($root . '/' . $orphan));

$db->setQuery('UPDATE #__mediaaudit_scans SET scanned_at = ' . $db->quote($time) . ' WHERE id = ' . $last)->execute();

// ---------------------------------------------------------------------------------------------
echo "--- what must not be moved\n";

$refused = [
    $used                               => QuarantineService::REASON_REFERENCED,
    $folder . '/g.jpg'                  => QuarantineService::REASON_REFERENCED,
    $folder . '/missing.jpg'            => QuarantineService::REASON_NOT_FOUND,
    $folder . '/Orphan.JPG'             => QuarantineService::REASON_NOT_FOUND,
    'configuration.php'                 => QuarantineService::REASON_NOT_AN_IMAGE,
    'images/../configuration.php'       => QuarantineService::REASON_NOT_AN_IMAGE,
    'images/../images/' . basename($orphan) => QuarantineService::REASON_NOT_AN_IMAGE,
    $folder . '/index.html'             => QuarantineService::REASON_NOT_AN_IMAGE,
    'media/system/images/joomla-favicon.svg' => QuarantineService::REASON_NOT_AN_IMAGE,
];

$result  = $runner->getQuarantine()->move(array_keys($refused), $userId, '', $user->username);
$reasons = array_column($result['refused'], 'reason', 'path');

$check('nothing was moved', $result['moved'] === []);

foreach ($refused as $path => $reason) {
    $check($path, ($reasons[$path] ?? null) === $reason, $reasons[$path] ?? 'not refused');
}

$check('every file is where it was', $snapshot() === $before);

// ---------------------------------------------------------------------------------------------
echo "--- to the quarantine\n";

$logs   = (int) $one('SELECT COUNT(*) FROM #__action_logs WHERE extension = ' . $db->quote('com_mediaaudit'));
$result = $runner->getQuarantine()->move([$orphan], $userId, 'verify-quarantine.php', $user->username);
$moved  = $result['moved'][0] ?? null;

$check('moved', $moved !== null && $result['refused'] === [], $moved['quarantine_path'] ?? json_encode($result['refused']));

if ($moved === null) {
    exit(1);
}

$check('the path is images/_mediaaudit_quarantine/{date}/{path}', $moved['quarantine_path'] === 'images/_mediaaudit_quarantine/' . gmdate('Ymd') . '/' . $orphan);
$check('the file is gone from its place', !file_exists($root . '/' . $orphan));
$check('the file is in the quarantine, unchanged', is_file($root . '/' . $moved['quarantine_path']) && sha1_file($root . '/' . $moved['quarantine_path']) === $sha1);

$record = $db->setQuery('SELECT * FROM #__mediaaudit_quarantine WHERE id = ' . (int) $moved['id'])->loadObject();

$check(
    'the record',
    $record && $record->original_path === $orphan && $record->sha1 === $sha1 && (int) $record->moved_by === $userId && $record->restored_at === null
);
$check('the index calls the file quarantined', $one('SELECT status FROM #__mediaaudit_files WHERE path = ' . $db->quote($orphan)) === 'quarantined');
$check('the log of the actions', (int) $one('SELECT COUNT(*) FROM #__action_logs WHERE extension = ' . $db->quote('com_mediaaudit')) === $logs + 1);
$check('visitors get the image no more', ($status = $http($orphan)) === 404, 'HTTP ' . $status);
$check('visitors cannot read the quarantine', ($status = $http($moved['quarantine_path'])) === 403, 'HTTP ' . $status);

// The lists
$factory = $app->bootComponent('com_mediaaudit')->getMVCFactory();
$list    = static function (string $name, array $state = []) use ($factory): array {
    $model = $factory->createModel($name, 'Administrator', ['ignore_request' => true]);
    $model->setState('list.start', 0);
    $model->setState('list.limit', 0);

    foreach ($state as $key => $value) {
        $model->setState($key, $value);
    }

    return $model->getItems() ?: [];
};

$orphans = array_column($list('Orphans', ['filter.search' => '_mediaaudit_fixtures/']), null, 'path');

$check('the list of orphans has it no more', !isset($orphans[$orphan]));

$held = array_column($list('Quarantine', ['filter.state' => 'held']), null, 'original_path');

$check('the list of the quarantine has it', isset($held[$orphan]) && $held[$orphan]->moved_by_name === $user->name);

// A scan while the file is away
$scan = $runner->run(true);

$check('a scan leaves the quarantine alone', is_file($root . '/' . $moved['quarantine_path']) && $one('SELECT status FROM #__mediaaudit_files WHERE path = ' . $db->quote($orphan)) === 'quarantined');
$check(
    'a scan does not take the quarantine for images of the site',
    (int) $one('SELECT COUNT(*) FROM #__mediaaudit_files WHERE path LIKE ' . $db->quote('images/\_mediaaudit\_quarantine/%')) === 0
);

// ---------------------------------------------------------------------------------------------
echo "--- back\n";

$result = $runner->getQuarantine()->restore([(int) $moved['id']], $userId, $user->username);

$check('restored', \count($result['restored']) === 1 && $result['refused'] === [], json_encode($result['refused']));
$check('the file is where it was, unchanged', is_file($root . '/' . $orphan) && sha1_file($root . '/' . $orphan) === $sha1);
$check('the quarantine has it no more', !file_exists($root . '/' . $moved['quarantine_path']));

$record = $db->setQuery('SELECT * FROM #__mediaaudit_quarantine WHERE id = ' . (int) $moved['id'])->loadObject();

$check('the record says when and by whom', $record->restored_at !== null && (int) $record->restored_by === $userId);
$check('the index calls the file active', $one('SELECT status FROM #__mediaaudit_files WHERE path = ' . $db->quote($orphan)) === 'active');
$check('the log of the actions', (int) $one('SELECT COUNT(*) FROM #__action_logs WHERE extension = ' . $db->quote('com_mediaaudit')) === $logs + 2);
$check('visitors get the image again', ($status = $http($orphan)) === 200, 'HTTP ' . $status);

$result = $runner->getQuarantine()->restore([(int) $moved['id']], $userId, $user->username);

$check('it cannot be put back twice', $result['restored'] === [] && ($result['refused'][0]['reason'] ?? '') === QuarantineService::REASON_RESTORED);
$check('every file of images/ is as it was', $snapshot() === $before);

// ---------------------------------------------------------------------------------------------
echo "--- the lists\n";

$runner->run(true);

$expected = json_decode(file_get_contents(\dirname(__DIR__) . '/fixtures/expected.json'), true);
$search   = ['filter.search' => '_mediaaudit_fixtures/'];
$orphans  = array_column($list('Orphans', $search), null, 'path');
$semi     = array_column($list('Semiorphans', $search), null, 'path');
$phase    = (int) ($options['phase'] ?? 4);

foreach ($expected['files'] as $path => $file) {
    $class = ($file['since_phase'] ?? 0) > $phase ? 'orphan' : $file['class'];

    $check(
        basename($path) . ' is ' . $class,
        isset($orphans[$path]) === ($class === 'orphan') && isset($semi[$path]) === ($class === 'semi_orphan')
    );
}

$check('photo.jpg is marked as referenced in another case', ($orphans[$folder . '/photo.jpg']->case_suspect ?? null) === $folder . '/Photo.JPG');

$reference = $semi[$folder . '/g.jpg']->references[0] ?? null;

$check(
    'a reference names its source and the way to edit it',
    $reference !== null && $reference['type'] === 'article' && $reference['state'] === -2
    && str_starts_with($reference['title'], 'MediaAudit Fixture') && $reference['link'] === 'index.php?option=com_content&task=article.edit&id=' . $reference['id']
);

$check('the filter by kind of source', array_keys(array_column($list('Semiorphans', $search + ['filter.source_type' => 'module']), null, 'path')) === [$folder . '/h.jpg']);

$broken = array_column($list('Broken', $search), null, 'target');

$check('broken: the two of the fixtures', array_keys($broken) === [$folder . '/Photo.JPG', $folder . '/missing.jpg']);
$check('broken: the file in another case', ($broken[$folder . '/Photo.JPG']->case_suspect ?? null) === $folder . '/photo.jpg' && $broken[$folder . '/missing.jpg']->case_suspect === null);

$duplicates = $list('Duplicates', $search);

$check(
    'duplicates: one group of two',
    \count($duplicates) === 1 && (int) $duplicates[0]->copies === 2
    && array_column($duplicates[0]->files, 'path') === [$folder . '/dup1.jpg', $folder . '/dup2.jpg']
    && (int) $duplicates[0]->wasted === (int) $duplicates[0]->size
);

// What a component made of an image is no duplicate: tell the index that dup2.jpg is a thumbnail
$thumbnail = (object) [
    'path'         => $folder . '/dup2.jpg',
    'path_hash'    => sha1($folder . '/dup2.jpg'),
    'path_ci_hash' => sha1(mb_strtolower($folder . '/dup2.jpg')),
    'raw'          => $folder . '/dup2.jpg',
    'is_external'  => 0,
    'source_type'  => 'verify',
    'source_id'    => 1,
    'field'        => 'fields.picture#thumbnail',
    'source_state' => 1,
    'exists_local' => 1,
    'updated_at'   => gmdate('Y-m-d H:i:s'),
];

$db->insertObject('#__mediaaudit_refs', $thumbnail);

try {
    $check('duplicates: a thumbnail made by a component is left out', $list('Duplicates', $search) === []);

    $with = $list('Duplicates', $search + ['filter.derived' => '1']);

    $check(
        'duplicates: and counted when asked for, marked as made by a component',
        \count($with) === 1 && array_column($with[0]->files, 'derived', 'path') == [$folder . '/dup1.jpg' => 0, $folder . '/dup2.jpg' => 1]
    );
    $check('duplicates: the summary leaves it out too', $runner->getReport()->duplicates($folder . '/') === [] && \count($runner->getReport()->duplicates($folder . '/', true)) === 1);
} finally {
    $db->setQuery('DELETE FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('verify'))->execute();
}

$check('duplicates: as before', \count($list('Duplicates', $search)) === 1);

$paged = $factory->createModel('Orphans', 'Administrator', ['ignore_request' => true]);
$paged->setState('list.start', 0);
$paged->setState('list.limit', 2);
$paged->setState('filter.search', '_mediaaudit_fixtures/');

$check('pages', \count($paged->getItems()) === 2 && $paged->getTotal() === \count($orphans), $paged->getTotal() . ' in all');

$csv = ExportController::toCsv([['path', 'size'], [$folder . '/產品圖.png', 990], ['=cmd()', 1], ['a "b", c', null]]);

$check('CSV', $csv === "path,size\n" . $folder . "/產品圖.png,990\n'=cmd(),1\n\"a \"\"b\"\", c\",\n", json_encode($csv, JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------------------------------------
echo "--- who may do what\n";

$facade = static fn (\Joomla\CMS\User\User $user) => new \Gss\Component\Mediaaudit\Administrator\Service\AuditFacade($app, $db, $user);
$denied = static function (callable $work): bool {
    try {
        $work();
    } catch (\RuntimeException $e) {
        return $e->getCode() === 403;
    }

    return false;
};

$guest = new \Joomla\CMS\User\User();

$check('a guest sees no report', $denied(static fn () => $facade($guest)->report('orphan')) && $denied(static fn () => $facade($guest)->references($used)));
$check('a guest moves nothing', $denied(static fn () => $facade($guest)->quarantine([$orphan])) && is_file($root . '/' . $orphan));

// Someone who manages the site without being a Super User
$managerId = (int) $one(
    'SELECT MIN(u.id) FROM #__users u INNER JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 7 AND u.block = 0'
    . ' AND u.id NOT IN (SELECT user_id FROM #__user_usergroup_map WHERE group_id = 8)'
);

if ($managerId) {
    $manager = $container->get(UserFactoryInterface::class)->loadUserById($managerId);

    $check('an administrator who is no Super User sees the report', \count($facade($manager)->report('orphan', ['search' => '_mediaaudit_fixtures/'])['items']) === 2);
    $check('and moves nothing', $denied(static fn () => $facade($manager)->quarantine([$orphan])) && is_file($root . '/' . $orphan));
    $check('and puts nothing back', $denied(static fn () => $facade($manager)->restore([1])));
} else {
    echo "        (the site has no administrator who is not a Super User: not checked)\n";
}

$check('a Super User may do both', $facade($user)->canRead() && $facade($user)->canQuarantine());

echo $failed ? "FAILED: $failed\n" : "OK\n";
exit($failed ? 1 : 0);
