<?php

/**
 * Checks the conversion of images, in the LOCAL development site.
 *
 * Three parts:
 *
 * 1. The converter, in a folder of its own outside the site: what it writes, what it refuses, the
 *    names it gives, and that an image is never changed.
 * 2. Saving an article, the way the editor does, with the system plugin: the images of the article
 *    are converted and the article loads the new files.
 * 3. A batch of selected images, and the content that is rewritten after it.
 *
 * Parts 2 and 3 use two images in images/_mediaaudit_convert/, which the script makes when they
 * are not there, and an article of its own. The images and what is converted of them stay in the
 * site: nothing deletes below images/. The article is emptied at the end.
 *
 * The format is WebP, which the GD of PHP writes. With --program, the path of avifenc, part 1 is
 * run for AVIF as well.
 *
 * Usage: php verify-convert.php [--root=/path/to/site] [--program=/opt/homebrew/bin/avifenc]
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Gss\Component\Mediaaudit\Administrator\Converter\BatchConverter;
use Gss\Component\Mediaaudit\Administrator\Converter\Encoder;
use Gss\Component\Mediaaudit\Administrator\Converter\ImageConverter;
use Gss\Component\Mediaaudit\Administrator\Helper\WaitDialog;
use Gss\Component\Mediaaudit\Administrator\Localizer\SourceRewriter;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Gss\Component\Mediaaudit\Administrator\Service\ProgressStore;
use Joomla\CMS\Component\ComponentHelper;
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

$options = getopt('', ['root::', 'program::']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');
$program = (string) ($options['program'] ?? '');

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

/**
 * A photo: gradients and noise, what JPEG is made for.
 */
$photo = static function (string $file, int $seed = 1): void {
    mt_srand($seed);

    $image = imagecreatetruecolor(1200, 800);

    for ($y = 0; $y < 800; $y += 4) {
        for ($x = 0; $x < 1200; $x += 4) {
            $colour = imagecolorallocate($image, (int) ($x / 5 + mt_rand(0, 20)) % 256, (int) ($y / 4 + mt_rand(0, 20)) % 256, (int) (($x + $y) / 9) % 256);
            imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $colour);
        }
    }

    imagejpeg($image, $file, 90);
    imagedestroy($image);
    mt_srand();
};

/**
 * A picture of a screen: flat colours, text, and a corner that is transparent.
 */
$screen = static function (string $file): void {
    $image = imagecreatetruecolor(1000, 700);

    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, 999, 699, imagecolorallocatealpha($image, 255, 255, 255, 0));
    imagefilledrectangle($image, 0, 0, 999, 50, imagecolorallocate($image, 30, 50, 90));

    for ($i = 0; $i < 22; $i++) {
        imagestring($image, 5, 40, 80 + $i * 26, "Row $i: the quick brown fox jumps over the lazy dog", imagecolorallocate($image, 20, 20, 20));
    }

    imagefilledrectangle($image, 800, 500, 999, 699, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagepng($image, $file);
    imagedestroy($image);
};

$tmp = rtrim((string) $app->get('tmp_path', sys_get_temp_dir()), '/');

// ---------------------------------------------------------------------------------------------
echo "\n1. The converter, in a folder outside the site\n";

$sandbox = $tmp . '/mediaaudit-verify-' . bin2hex(random_bytes(4));
$folder  = 'images/_mediaaudit_verify';

mkdir($sandbox . '/' . $folder, 0755, true);
mkdir($sandbox . '/images/_mediaaudit_quarantine', 0755, true);
mkdir($sandbox . '/tmp', 0755, true);

$forget = static function () use ($db, $folder): void {
    $db->setQuery('DELETE FROM #__mediaaudit_conversions WHERE source_path LIKE ' . $db->quote($folder . '/%'))->execute();
};

$forget();

try {
    $photo("$sandbox/$folder/photo.jpg");
    $screen("$sandbox/$folder/photo.png");
    $photo("$sandbox/images/_mediaaudit_quarantine/held.jpg");
    $photo("$sandbox/outside.jpg");
    file_put_contents("$sandbox/$folder/fake.png", 'no image at all');

    // One pixel: next to nothing to save
    $pixel = imagecreatetruecolor(1, 1);
    imagepng($pixel, "$sandbox/$folder/pixel.png");

    $formats = ['webp' => ''];

    if ($program !== '') {
        $formats['avif'] = $program;
    }

    foreach ($formats as $format => $binary) {
        $encoder   = new Encoder($binary);
        $converter = new ImageConverter($db, $sandbox, $sandbox . '/tmp', $encoder);

        echo "   $format, with ", implode(', ', $encoder->capabilities()[$format]) ?: 'nothing', "\n";

        $check("$format: this server can write it", $encoder->can($format));

        if (!$encoder->can($format)) {
            continue;
        }

        $quality = $format === 'webp' ? 82 : 60;
        $before  = sha1_file("$sandbox/$folder/photo.jpg");
        $result  = $converter->convert("$folder/photo.jpg", $format, $quality, 10, 7);

        $check(
            "$format: a JPEG is converted",
            $result['status'] === ImageConverter::CONVERTED && $result['target'] === "$folder/photo.$format" && !$result['reused'],
            $result['source_size'] . ' -> ' . $result['target_size'] . ' bytes ' . $result['error']
        );
        $check("$format: the new file is one of the format", Encoder::isOf("$sandbox/$folder/photo.$format", $format));
        $check("$format: the new file is smaller by a tenth at least", $result['target_size'] <= $result['source_size'] * 0.9 && $result['target_size'] === filesize("$sandbox/$folder/photo.$format"));
        $check("$format: the image is as it was", sha1_file("$sandbox/$folder/photo.jpg") === $before);

        $row = $db->setQuery('SELECT * FROM #__mediaaudit_conversions WHERE source_hash = ' . $db->quote(sha1("$folder/photo.jpg")) . ' AND format = ' . $db->quote($format))->loadObject();

        $check(
            "$format: what was done is kept",
            $row !== null && $row->status === 'converted' && $row->source_sha1 === $before && $row->target_path === "$folder/photo.$format"
            && (int) $row->quality === $quality && (int) $row->converted_by === 7 && $row->encoder !== ''
        );

        $again = $converter->convert("$folder/photo.jpg", $format, $quality, 10, 7);

        $check("$format: an image that was converted is not converted again", $again['reused'] && $again['target'] === "$folder/photo.$format");

        $other = $converter->convert("$folder/photo.png", $format, $quality);

        $check(
            "$format: the name of another image is not taken",
            $other['status'] === ImageConverter::CONVERTED && $other['target'] === "$folder/photo-png.$format",
            (string) $other['target'] . ' ' . $other['source_size'] . ' -> ' . $other['target_size'] . ' bytes'
        );

        $small = $converter->convert("$folder/pixel.png", $format, $quality, 90);

        $check(
            "$format: a file that is not smaller by enough is not written",
            $small['status'] === ImageConverter::NOT_SMALLER && $small['target'] === null && !glob("$sandbox/$folder/pixel*.$format"),
            $small['source_size'] . ' -> ' . $small['target_size'] . ' bytes'
        );

        $fake = $converter->convert("$folder/fake.png", $format, $quality);

        $check("$format: what is no image fails, and says why", $fake['status'] === ImageConverter::FAILED && $fake['error'] !== '' && !glob("$sandbox/$folder/fake*.$format"), $fake['error']);

        foreach (['outside.jpg', 'images/_mediaaudit_quarantine/held.jpg', "$folder/../../outside.jpg", "$folder/PHOTO.JPG", "$folder/none.jpg", "/$folder/photo.jpg"] as $path) {
            $refused = $converter->convert($path, $format, $quality);

            $check("$format: refuses $path", $refused['status'] === ImageConverter::FAILED && $refused['target'] === null);
        }

        $check("$format: nothing was written outside the folder", !glob("$sandbox/*.$format") && !glob("$sandbox/images/_mediaaudit_quarantine/*.$format"));

        // The image is replaced by another one of the same name
        $kept = sha1_file("$sandbox/$folder/photo.$format");

        $photo("$sandbox/$folder/photo.jpg", 2);

        $changed = $converter->convert("$folder/photo.jpg", $format, $quality);

        $check(
            "$format: an image that changed gets a new file, and the old one stays",
            $changed['status'] === ImageConverter::CONVERTED && !$changed['reused']
            && $changed['target'] === "$folder/photo-jpg.$format"
            && sha1_file("$sandbox/$folder/photo.$format") === $kept,
            (string) $changed['target']
        );

        // Once more: the two first names are taken
        $photo("$sandbox/$folder/photo.jpg", 3);

        $third = $converter->convert("$folder/photo.jpg", $format, $quality);

        $check(
            "$format: when the names are taken, the new file is named by the content of the image",
            $third['target'] === "$folder/photo-" . substr(sha1_file("$sandbox/$folder/photo.jpg"), 0, 8) . ".$format",
            (string) $third['target']
        );

        $changed = $third;

        $targets = $converter->getTargets($format, [sha1("$folder/photo.jpg"), sha1("$folder/pixel.png")]);

        $check("$format: the converted files are known by their images", array_keys($targets) === [sha1("$folder/photo.jpg")] && $targets[sha1("$folder/photo.jpg")]['target'] === $changed['target']);

        // Another file under the name of the converted one: not the file that was written
        file_put_contents("$sandbox/" . $changed['target'], 'put here by someone else');

        $check(
            "$format: a file that took the place of the converted one is not what content is made to load",
            $converter->getTargets($format, [sha1("$folder/photo.jpg")]) === [] && !$converter->convert("$folder/photo.jpg", $format, $quality)['reused']
        );

        // As it was, for the next format
        $photo("$sandbox/$folder/photo.jpg");
        $check("$format: no temporary file is left", !glob("$sandbox/tmp/*"));
    }

    try {
        (new ImageConverter($db, $sandbox, $sandbox . '/tmp', new Encoder()))->convert("$folder/photo.jpg", 'gif', 80);
        $check('an unknown format is refused', false);
    } catch (\InvalidArgumentException $e) {
        $check('an unknown format is refused', true);
    }

    $check('a program that is not known is not run', (new Encoder('/bin/sh'))->capabilities() === (new Encoder())->capabilities());

    // The place where a request tells how far it is
    $store = new ProgressStore($sandbox . '/tmp');
    $token = bin2hex(random_bytes(16));

    $store->write($token, ['stage' => 'convert', 'done' => 2, 'total' => 5, 'current' => 'a.png']);

    $check('progress is read by its token', ($store->read($token)['done'] ?? null) === 2 && $store->read(bin2hex(random_bytes(16))) === null);

    $store->write('../../etc/passwd', ['stage' => 'convert']);
    $store->write('short', ['stage' => 'convert']);

    $check('a token that is none writes nothing', \count(glob("$sandbox/tmp/*")) === 1 && $store->read('../../etc/passwd') === null);

    $store->clear($token);

    $check('progress is gone when the work is done', $store->read($token) === null && !glob("$sandbox/tmp/*"));
} finally {
    $forget();

    // A folder of this script, outside the site
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sandbox, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($sandbox);
}

// ---------------------------------------------------------------------------------------------
echo "\n2. Saving an article\n";

$params = ComponentHelper::getParams('com_mediaaudit');

// In this request only: the options of the site stay as they are
$params->set('convert_format', 'webp');
$params->set('convert_on_save', 1);
$params->set('convert_quality_webp', 82);
$params->set('convert_program', '');
$params->set('localize_on_save', 0);

$folder = 'images/_mediaaudit_convert';

if (!is_dir("$root/$folder")) {
    mkdir("$root/$folder", 0755, true);
}

foreach (['verify photo.jpg' => $photo, 'verify-screen.png' => $screen] as $name => $make) {
    if (!is_file("$root/$folder/$name")) {
        $make("$root/$folder/$name");
    }
}

$images = ["$folder/verify photo.jpg" => sha1_file("$root/$folder/verify photo.jpg"), "$folder/verify-screen.png" => sha1_file("$root/$folder/verify-screen.png")];
$known  = array_flip(array_map('basename', glob("$root/$folder/*")));

$userId = (int) $one('SELECT MIN(u.id) FROM #__users u INNER JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0');
$user   = $container->get(UserFactoryInterface::class)->loadUserById($userId);
$app->loadIdentity($user);

$dispatcher = new Dispatcher();
$plugin     = $app->bootPlugin('mediaaudit', 'system');

$check('the system plugin is installed', str_starts_with(\get_class($plugin), 'Gss\\Plugin\\System\\'));

if ($failed) {
    echo "FAILED: $failed\n";
    exit(1);
}

$plugin->setDispatcher($dispatcher);
$plugin->registerListeners();

$table = new Content($db);
$table->setCurrentUser($user);

if (!$table->load(['alias' => 'mediaaudit-convert-verify'])) {
    $category = (int) $one('SELECT catid FROM #__content WHERE alias = ' . $db->quote('mediaaudit-fixture-01'));

    if (!$category) {
        fwrite(STDERR, "The fixtures are not there. Run seed-fixtures.php first.\n");
        exit(1);
    }

    $table->bind([
        'title' => 'Media Audit: conversion', 'alias' => 'mediaaudit-convert-verify', 'catid' => $category, 'state' => 0, 'access' => 1,
        'language' => '*', 'introtext' => '', 'fulltext' => '', 'images' => '{}', 'urls' => '{}', 'attribs' => '{}', 'metadata' => '{}',
        'metakey' => '', 'metadesc' => '', 'created' => gmdate('Y-m-d H:i:s'), 'created_by' => $userId,
    ]);

    if (!$table->check() || !$table->store()) {
        fwrite(STDERR, 'Could not make the article: ' . $table->getError() . "\n");
        exit(1);
    }
}

$id      = (int) $table->id;
$backups = (int) $one('SELECT COALESCE(MAX(id), 0) FROM #__mediaaudit_rewrites');

$save = static function (Content $table) use ($dispatcher): void {
    $dispatcher->dispatch('onContentBeforeSave', new BeforeSaveEvent('onContentBeforeSave', [
        'context' => 'com_content.article', 'subject' => $table, 'isNew' => false, 'data' => [],
    ]));

    if (!$table->store()) {
        throw new \RuntimeException($table->getError());
    }

    $dispatcher->dispatch('onContentAfterSave', new AfterSaveEvent('onContentAfterSave', [
        'context' => 'com_content.article', 'subject' => $table, 'isNew' => false, 'data' => [],
    ]));
};

$text = '<p>Before.</p>'
    . '<p><img src="images/_mediaaudit_convert/verify%20photo.jpg?v=2" alt="escaped, with a query" width="600">'
    . '<a href="/images/_mediaaudit_convert/verify-screen.png"><img src="https://' . $_SERVER['HTTP_HOST'] . '/images/_mediaaudit_convert/verify-screen.png" alt="with the domain"></a>'
    . '<img src="images/_mediaaudit_convert/none.png" alt="not there"><img src="images/_mediaaudit_convert/moving.gif" alt="a GIF">'
    . '<a href="index.php?option=com_content&amp;view=article&amp;id=1">a link to photo.jpg, in words</a></p>';

$intro = "$folder/verify photo.jpg#joomlaImage://local-images/_mediaaudit_convert/verify photo.jpg?width=1200&height=800";

try {
    $table->introtext = $text;
    $table->images    = json_encode(['image_intro' => $intro, 'image_intro_alt' => 'photo.jpg', 'image_fulltext' => '']);

    $app->getInput()->set(WaitDialog::FIELD, bin2hex(random_bytes(16)));

    $start = microtime(true);
    $save($table);
    $seconds = round(microtime(true) - $start, 2);

    $stored = $db->setQuery('SELECT introtext, images FROM #__content WHERE id = ' . $id)->loadObject();
    $made   = array_values(array_diff(array_map('basename', glob("$root/$folder/*")), array_keys($known)));

    echo '   ', $seconds, ' s, new files: ', implode(', ', $made) ?: 'none (converted by a run before)', "\n";

    $check('the converted files are next to the images', is_file("$root/$folder/verify photo.webp") && is_file("$root/$folder/verify-screen.webp"));
    $check(
        'an escaped address with a query stays escaped, with its query',
        str_contains($stored->introtext, '<img src="images/_mediaaudit_convert/verify%20photo.webp?v=2" alt="escaped, with a query" width="600">')
    );
    $check('an address with the domain keeps the domain', str_contains($stored->introtext, 'src="https://' . $_SERVER['HTTP_HOST'] . '/images/_mediaaudit_convert/verify-screen.webp"'));
    $check('a link to an image leads to the new file', str_contains($stored->introtext, '<a href="/images/_mediaaudit_convert/verify-screen.webp">'));
    $check('an image that is not there stays', str_contains($stored->introtext, 'src="images/_mediaaudit_convert/none.png"'));
    $check('a GIF stays', str_contains($stored->introtext, 'src="images/_mediaaudit_convert/moving.gif"'));
    $check('the rest of the text stays', str_starts_with($stored->introtext, '<p>Before.</p>') && str_contains($stored->introtext, '<a href="index.php?option=com_content&amp;view=article&amp;id=1">a link to photo.jpg, in words</a>'));
    $check('nothing else changed', str_replace(['verify%20photo.webp', 'verify-screen.webp'], ['verify%20photo.jpg', 'verify-screen.png'], $stored->introtext) === $text);

    $json = json_decode($stored->images, true);

    $check(
        'the image of the media field is the new file, in both of its places',
        ($json['image_intro'] ?? '') === "$folder/verify photo.webp#joomlaImage://local-images/_mediaaudit_convert/verify photo.webp?width=1200&height=800",
        (string) ($json['image_intro'] ?? '')
    );
    $check('what is no address stays', ($json['image_intro_alt'] ?? '') === 'photo.jpg');

    foreach ($images as $path => $sha1) {
        $check('the image is as it was: ' . basename($path), sha1_file("$root/$path") === $sha1);
    }

    $paths = $db->setQuery(
        'SELECT path, exists_local FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article') . ' AND source_id = ' . $id . ' AND is_external = 0 ORDER BY path'
    )->loadAssocList('path', 'exists_local');

    $check(
        'the index has the references to the new files',
        (int) ($paths["$folder/verify photo.webp"] ?? 0) === 1 && (int) ($paths["$folder/verify-screen.webp"] ?? 0) === 1
        && !isset($paths["$folder/verify photo.jpg"]) && !isset($paths["$folder/verify-screen.png"]),
        implode(', ', array_keys($paths))
    );
    $check(
        'the index of the files has the new files',
        (int) $one('SELECT COUNT(*) FROM #__mediaaudit_files WHERE status = ' . $db->quote('active') . ' AND path_hash IN (' . $db->quote(sha1("$folder/verify photo.webp")) . ',' . $db->quote(sha1("$folder/verify-screen.webp")) . ')') === 2
    );

    $kept = $db->setQuery('SELECT field, kind, original, rewritten_hash, rewritten_by FROM #__mediaaudit_rewrites WHERE id > ' . $backups . ' AND source_id = ' . $id . ' ORDER BY id')->loadObjectList('field');

    $check(
        'the text before the change is kept, as a change for a conversion',
        isset($kept['introtext'], $kept['images']) && $kept['introtext']->kind === 'convert' && $kept['introtext']->original === $text
        && $kept['introtext']->rewritten_hash === sha1($stored->introtext) && (int) $kept['introtext']->rewritten_by === $userId
    );

    $runner = ScanRunner::create($db, $params, $root, $dispatcher, $tmp);

    // Only counted: putting back would put back what others converted as well
    $all      = $runner->getRewriter()->revert(true);
    $convert  = $runner->getRewriter()->revert(true, [SourceRewriter::KIND_CONVERT]);
    $localize = $runner->getRewriter()->revert(true, [SourceRewriter::KIND_LOCALIZE, SourceRewriter::KIND_REMOVE_LOST]);

    $check(
        'changes can be put back by their kind',
        $convert['reverted'] >= 2 && array_sum($all) === array_sum($convert) + array_sum($localize),
        'convert ' . array_sum($convert) . ', others ' . array_sum($localize) . ', all ' . array_sum($all)
    );

    // Saved again: nothing to do
    $count = (int) $one('SELECT COUNT(*) FROM #__mediaaudit_rewrites WHERE id > ' . $backups);

    $save($table);

    $check('saving again changes nothing', (int) $one('SELECT COUNT(*) FROM #__mediaaudit_rewrites WHERE id > ' . $backups) === $count
        && (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id) === $stored->introtext);

    // A form that is sent without loading the page: what the request tells last
    $sent = (string) $app->getInput()->getCmd(WaitDialog::FIELD, '');
    $last = (new ProgressStore($tmp))->read($sent);

    $check('the request tells last that it is done', ($last['stage'] ?? '') === 'done', json_encode($last, JSON_UNESCAPED_UNICODE));

    $app->getInput()->set('token', $sent);

    $event = class_exists(\Joomla\CMS\Event\Plugin\AjaxEvent::class)
        ? new \Joomla\CMS\Event\Plugin\AjaxEvent('onAjaxMediaaudit', ['subject' => $app])
        : new \Joomla\Event\Event('onAjaxMediaaudit');

    $answer = $dispatcher->dispatch('onAjaxMediaaudit', $event)->getArgument('result', []);

    $check('it is told once, and then forgotten', ($answer[0]['stage'] ?? '') === 'done' && (new ProgressStore($tmp))->read($sent) === null);

    // The page that waits
    $token = bin2hex(random_bytes(16));

    (new ProgressStore($tmp))->write($token, ['stage' => 'convert', 'done' => 1, 'total' => 3, 'current' => 'a.png']);
    $app->getInput()->set('token', $token);

    $event = class_exists(\Joomla\CMS\Event\Plugin\AjaxEvent::class)
        ? new \Joomla\CMS\Event\Plugin\AjaxEvent('onAjaxMediaaudit', ['subject' => $app])
        : new \Joomla\Event\Event('onAjaxMediaaudit');

    $answer = $dispatcher->dispatch('onAjaxMediaaudit', $event)->getArgument('result', []);

    $check('the page that waits is told how far the work is', ($answer[0]['stage'] ?? '') === 'convert' && (int) ($answer[0]['total'] ?? 0) === 3);

    // The event of Joomla 4, which has no class of its own
    $answer = $dispatcher->dispatch('onAjaxMediaaudit', new \Joomla\Event\Event('onAjaxMediaaudit'))->getArgument('result', []);

    $check('and so it is with the event of Joomla 4', ($answer[0]['stage'] ?? '') === 'convert');

    (new ProgressStore($tmp))->clear($token);

    $app->loadIdentity($container->get(UserFactoryInterface::class)->loadUserById(0));
    (new ProgressStore($tmp))->write($token, ['stage' => 'convert', 'done' => 1, 'total' => 3]);

    $event = class_exists(\Joomla\CMS\Event\Plugin\AjaxEvent::class)
        ? new \Joomla\CMS\Event\Plugin\AjaxEvent('onAjaxMediaaudit', ['subject' => $app])
        : new \Joomla\Event\Event('onAjaxMediaaudit');

    $answer = $dispatcher->dispatch('onAjaxMediaaudit', $event)->getArgument('result', []);

    $check('who is not logged in is told nothing', ($answer[0]['stage'] ?? null) === '');

    (new ProgressStore($tmp))->clear($token);
    $app->loadIdentity($user);

    // -----------------------------------------------------------------------------------------
    echo "\n2a. A component that stores the text of the form, whatever the event made of the row\n";

    // What FLEXIcontent does: the event before the save, then the text of the form is bound again
    $before = (int) $one('SELECT COALESCE(MAX(id), 0) FROM #__mediaaudit_rewrites');

    $db->setQuery('UPDATE #__content SET introtext = ' . $db->quote('') . ', images = ' . $db->quote('{}') . ' WHERE id = ' . $id)->execute();

    $table->load($id);
    $table->introtext = $text;
    $table->images    = '{}';

    $sent = bin2hex(random_bytes(16));

    $app->getInput()->set(WaitDialog::FIELD, $sent);

    $dispatcher->dispatch('onContentBeforeSave', new BeforeSaveEvent('onContentBeforeSave', [
        'context' => 'com_content.article', 'subject' => $table, 'isNew' => false, 'data' => [],
    ]));

    $check('the event changed the row', $table->introtext !== $text);

    $table->introtext = $text;

    if (!$table->store()) {
        throw new \RuntimeException($table->getError());
    }

    $check('the component stored the text of the form', (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id) === $text);

    $dispatcher->dispatch('onContentAfterSave', new AfterSaveEvent('onContentAfterSave', [
        'context' => 'com_content.article', 'subject' => $table, 'isNew' => false, 'data' => [],
    ]));

    $check('after the save, the article loads the new files all the same', (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id) === $stored->introtext);

    $last = (new ProgressStore($tmp))->read($sent);

    $check(
        'the page is told that what it shows is not what was stored',
        ($last['stage'] ?? '') === 'done' && (int) ($last['replaced'] ?? 0) > 0 && str_contains((string) ($last['messages'] ?? ''), 'WEBP'),
        json_encode($last, JSON_UNESCAPED_UNICODE)
    );

    (new ProgressStore($tmp))->clear($sent);

    $kept = $db->setQuery('SELECT field, kind, original, rewritten_hash FROM #__mediaaudit_rewrites WHERE id > ' . $before . ' AND source_id = ' . $id . ' ORDER BY id')->loadObjectList();

    $check(
        'what is kept is the text that was stored, once',
        \count($kept) === 1 && $kept[0]->field === 'introtext' && $kept[0]->kind === 'convert' && $kept[0]->original === $text && $kept[0]->rewritten_hash === sha1($stored->introtext)
    );

    $paths = $db->setQuery(
        'SELECT path FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article') . ' AND source_id = ' . $id . ' AND is_external = 0 ORDER BY path'
    )->loadColumn();

    $check(
        'the index has the new files and not the images',
        \in_array("$folder/verify photo.webp", $paths, true) && \in_array("$folder/verify-screen.webp", $paths, true)
        && !\in_array("$folder/verify photo.jpg", $paths, true) && !\in_array("$folder/verify-screen.png", $paths, true)
    );

    $db->setQuery('DELETE FROM #__mediaaudit_rewrites WHERE id > ' . $before . ' AND source_id = ' . $id)->execute();

    // -----------------------------------------------------------------------------------------
    echo "\n2b. An article of the GSS Markdown editor\n";

    $library = $root . '/libraries/gssmarkdown/src/Bootstrap.php';

    if (!is_file($library)) {
        echo "   skipped: the library of the editor is not in this site\n";
    } else {
        foreach (['Bootstrap', 'Extracted', 'ContentCodec'] as $class) {
            require_once \dirname($library) . '/' . $class . '.php';
        }

        $codec = new \Gss\Library\GssMarkdown\ContentCodec();

        // What the editor keeps: the HTML that it made, and the Markdown that it made it of
        $source = "# Title\n\nText.\n\n![escaped](images/_mediaaudit_convert/verify%20photo.jpg \"A title\")\n\n"
            . "[![screen](/images/_mediaaudit_convert/verify-screen.png)](/images/_mediaaudit_convert/verify-screen.png)\n\n"
            . "```html\n<img src=\"images/_mediaaudit_convert/sample.png\">\n```\n";
        $made   = '<h1>Title</h1>' . "\n" . '<p>Text.</p>' . "\n"
            . '<p><img src="images/_mediaaudit_convert/verify%20photo.jpg" alt="escaped" title="A title" /></p>' . "\n"
            . '<p><a href="/images/_mediaaudit_convert/verify-screen.png"><img src="/images/_mediaaudit_convert/verify-screen.png" alt="screen" /></a></p>' . "\n"
            . '<pre class="gssmd-code"><code class="language-html">&lt;img src="images/_mediaaudit_convert/sample.png"&gt;</code></pre>';

        $before = (int) $one('SELECT COALESCE(MAX(id), 0) FROM #__mediaaudit_rewrites');

        $table->introtext = $codec->encode($made, $source);
        $table->images    = '{}';

        $save($table);

        $kept      = (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id);
        $extracted = $codec->extract($kept);

        $check('the editor finds its Markdown', $extracted !== null);
        $check('the HTML loads the new files', $extracted !== null
            && str_contains($extracted->html, 'src="images/_mediaaudit_convert/verify%20photo.webp"')
            && str_contains($extracted->html, '<a href="/images/_mediaaudit_convert/verify-screen.webp"><img src="/images/_mediaaudit_convert/verify-screen.webp"'));
        $check(
            'the Markdown loads the new files',
            $extracted !== null && $extracted->markdown === str_replace(
                ['verify%20photo.jpg "A title"', '(/images/_mediaaudit_convert/verify-screen.png)'],
                ['verify%20photo.webp "A title"', '(/images/_mediaaudit_convert/verify-screen.webp)'],
                $source
            ),
            $extracted === null ? '' : str_replace("\n", ' / ', $extracted->markdown)
        );
        $check('code that is shown stays', $extracted !== null
            && str_contains($extracted->markdown, "```html\n<img src=\"images/_mediaaudit_convert/sample.png\">\n```")
            && str_contains($extracted->html, '&lt;img src="images/_mediaaudit_convert/sample.png"&gt;'));
        $check('the editor takes the HTML for what it made of the Markdown', $extracted !== null && $extracted->inSync);

        $paths = $db->setQuery(
            // Without what the text of the page shows as code, which the index takes for a reference to be safe
            'SELECT path, field FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article') . ' AND source_id = ' . $id
            . ' AND field NOT LIKE ' . $db->quote('%#text') . ' ORDER BY path'
        )->loadAssocList('path', 'field');

        $check(
            'the index has the new files, once, and not the images',
            array_keys($paths) === ["$folder/verify photo.webp", "$folder/verify-screen.webp"] && array_unique(array_values($paths)) === ['introtext'],
            json_encode($paths, JSON_UNESCAPED_SLASHES)
        );

        // What was saved before this was mended: the HTML loads the new files, the Markdown the images
        $behind = $codec->encode($extracted->html, $source);

        $db->setQuery('UPDATE #__content SET introtext = ' . $db->quote($behind) . ' WHERE id = ' . $id)->execute();

        $runner    = ScanRunner::create($db, $params, $root, $dispatcher, $tmp);
        $extractor = $runner->getExtractors()->get('article');

        $runner->getFileScanner()->add(array_keys($images));
        $runner->getIndexer()->indexOne('article', $id, $extractor->extractOne($id));

        $paths = $db->setQuery(
            'SELECT path, field FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article') . ' AND source_id = ' . $id . ' ORDER BY path'
        )->loadAssocList('path', 'field');

        $check(
            'Markdown that was left behind keeps its images in use',
            ($paths["$folder/verify photo.jpg"] ?? '') === 'introtext#markdown' && ($paths["$folder/verify-screen.png"] ?? '') === 'introtext#markdown'
            && ($paths["$folder/verify photo.webp"] ?? '') === 'introtext',
            json_encode($paths, JSON_UNESCAPED_SLASHES)
        );

        $mended = $runner->getRewriter()->rewriteConverted($runner->getConverter()->getTargets('webp', array_map('sha1', array_keys($images))), false, $userId);
        $after  = $codec->extract((string) $one('SELECT introtext FROM #__content WHERE id = ' . $id));

        $check(
            'and is brought up to date by the next run',
            $mended['rewritten'] === 1 && $after !== null && $after->markdown === $extracted->markdown && $after->html === $extracted->html && $after->inSync,
            json_encode($mended)
        );

        $db->setQuery('DELETE FROM #__mediaaudit_rewrites WHERE id > ' . $before . ' AND source_id = ' . $id)->execute();
    }

    // -----------------------------------------------------------------------------------------
    echo "\n2c. Custom fields, and what is left as it is\n";

    $runner   = ScanRunner::create($db, $params, $root, $dispatcher, $tmp);
    $rewriter = $runner->getRewriter();
    $fields   = $db->setQuery('SELECT name, id FROM #__fields WHERE name IN (' . $db->quote('mediaaudit-fixture-media') . ',' . $db->quote('mediaaudit-fixture-editor') . ')')->loadAssocList('name', 'id');

    $check('the fields of the fixtures are there', \count($fields) === 2);

    $media  = json_encode(['imagefile' => "$folder/verify photo.jpg#joomlaImage://local-images/_mediaaudit_convert/verify photo.jpg?width=1200&height=800", 'alt_text' => 'photo.jpg']);
    $editor = '<p><img src="images/_mediaaudit_convert/verify-screen.png" alt="in a field"> and <a href="index.php">a link</a></p>';
    $values = static fn (): array => $db->setQuery('SELECT field_id, value FROM #__fields_values WHERE item_id = ' . $db->quote((string) $id) . ' ORDER BY field_id')->loadAssocList('field_id', 'value');
    $fill   = static function () use ($db, $id, $fields, $media, $editor): void {
        $db->setQuery('DELETE FROM #__fields_values WHERE item_id = ' . $db->quote((string) $id))->execute();

        foreach ([$fields['mediaaudit-fixture-media'] ?? 0 => $media, $fields['mediaaudit-fixture-editor'] ?? 0 => $editor] as $field => $value) {
            $row = (object) ['field_id' => (int) $field, 'item_id' => (string) $id, 'value' => $value];

            $db->insertObject('#__fields_values', $row);
        }
    };

    $before  = (int) $one('SELECT COALESCE(MAX(id), 0) FROM #__mediaaudit_rewrites');
    $targets = $runner->getConverter()->getTargets('webp', array_map('sha1', array_keys($images)));

    try {
        $fill();

        $extractor = $runner->getExtractors()->get('article_field');

        $runner->getIndexer()->indexOne('article_field', $id, $extractor->extractOne($id));

        $dry = $rewriter->rewriteConverted($targets, true, $userId);

        $check('a dry run counts the fields and writes nothing', $dry['rewritten'] >= 1 && $values() === [(int) $fields['mediaaudit-fixture-media'] => $media, (int) $fields['mediaaudit-fixture-editor'] => $editor], json_encode($dry));

        $done  = $rewriter->rewriteConverted($targets, false, $userId);
        $after = $values();
        $image = json_decode($after[(int) $fields['mediaaudit-fixture-media']] ?? '{}', true);

        $check(
            'the value of a media field loads the new file, in both of its places',
            ($image['imagefile'] ?? '') === "$folder/verify photo.webp#joomlaImage://local-images/_mediaaudit_convert/verify photo.webp?width=1200&height=800"
            && ($image['alt_text'] ?? '') === 'photo.jpg',
            (string) ($after[(int) $fields['mediaaudit-fixture-media']] ?? '')
        );
        $check(
            'the value of an editor field loads the new file',
            ($after[(int) $fields['mediaaudit-fixture-editor']] ?? '') === str_replace('verify-screen.png', 'verify-screen.webp', $editor)
        );

        $kept = $db->setQuery('SELECT field, kind, original FROM #__mediaaudit_rewrites WHERE id > ' . $before . ' AND source_type = ' . $db->quote('article_field') . ' AND source_id = ' . $id . ' ORDER BY field')->loadObjectList('field');

        $check(
            'what the values were is kept, by the name of the field',
            \count($kept) === 2 && ($kept['fields.mediaaudit-fixture-media']->original ?? '') === $media
            && ($kept['fields.mediaaudit-fixture-editor']->original ?? '') === $editor && $kept['fields.mediaaudit-fixture-editor']->kind === 'convert'
        );

        $paths = $db->setQuery('SELECT path FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article_field') . ' AND source_id = ' . $id . ' ORDER BY path')->loadColumn();

        // The alternative text names a file, which the index takes for a reference to be safe
        $check(
            'the index has the new files of the fields, and not the images',
            array_values(array_filter($paths, static fn (string $path): bool => str_starts_with($path, $folder . '/'))) === ["$folder/verify photo.webp", "$folder/verify-screen.webp"],
            implode(', ', $paths)
        );

        $back = $rewriter->revert(false, [SourceRewriter::KIND_CONVERT], ['article_field', $id]);

        $check('the values can be put back, of this item only', $back['reverted'] === 2 && $values() === [(int) $fields['mediaaudit-fixture-media'] => $media, (int) $fields['mediaaudit-fixture-editor'] => $editor], json_encode($back));

        // Saved with the form: the values are written after the event, and read at the end of the request
        $db->setQuery('UPDATE #__content SET introtext = ' . $db->quote('') . ', images = ' . $db->quote('{}') . ' WHERE id = ' . $id)->execute();
        $table->load($id);
        $save($table);

        $check('the values wait for the end of the request', $values()[(int) $fields['mediaaudit-fixture-editor']] === $editor);

        $plugin->flush();

        $check(
            'at the end of the request, the values load the new files',
            $values()[(int) $fields['mediaaudit-fixture-editor']] === str_replace('verify-screen.png', 'verify-screen.webp', $editor)
            && str_contains($values()[(int) $fields['mediaaudit-fixture-media']], 'verify photo.webp#joomlaImage')
        );

        // What is left as it is
        $convertible = $rewriter->getConvertible();

        $check(
            'templates and posts of EasyBlog are not rewritten for a conversion; articles and their fields are',
            !\in_array('template_style', $convertible['types'], true) && !\in_array('easyblog', $convertible['types'], true) && !\in_array('template_css', $convertible['types'], true)
            && \in_array('article', $convertible['types'], true) && \in_array('article_field', $convertible['types'], true) && \in_array('category', $convertible['types'], true),
            implode(', ', $convertible['types'])
        );
        $check(
            'a template is rewritten for a copy of an external image, as before',
            $rewriter->canRewrite($runner->getExtractors()->get('template_style'), SourceRewriter::KIND_LOCALIZE)
            && !$rewriter->canRewrite($runner->getExtractors()->get('template_style'), SourceRewriter::KIND_CONVERT)
        );

        // An image that only a template loads does not wait to be converted
        $logo = $db->setQuery(
            'SELECT f.id, f.path FROM #__mediaaudit_files f INNER JOIN #__mediaaudit_refs r ON r.path_hash = f.path_hash AND r.is_external = 0'
            . ' WHERE f.status = ' . $db->quote('active') . ' AND (LOWER(f.path) LIKE ' . $db->quote('%.png') . ' OR LOWER(f.path) LIKE ' . $db->quote('%.jpg') . ')'
            . ' GROUP BY f.id, f.path HAVING SUM(r.source_type NOT IN (' . $db->quote('template_style') . ',' . $db->quote('template_css') . ')) = 0',
            0,
            1
        )->loadObject();

        if ($logo) {
            $waiting = array_column($runner->getBatchConverter()->candidates('webp'), 'path');

            $check('an image that only a template loads does not wait to be converted', !\in_array($logo->path, $waiting, true), $logo->path);
            $check('it is converted when it is selected, and no content is rewritten for it', \count($runner->getBatchConverter()->candidates('webp', 0, [(int) $logo->id])) === 1);
        } else {
            echo "   no image of this site is loaded by a template only\n";
        }
    } finally {
        $db->setQuery('DELETE FROM #__fields_values WHERE item_id = ' . $db->quote((string) $id))->execute();
        $db->setQuery('DELETE FROM #__mediaaudit_rewrites WHERE id > ' . $before . ' AND source_id = ' . $id)->execute();
        $db->setQuery('DELETE FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article_field') . ' AND source_id = ' . $id)->execute();
    }

    // -----------------------------------------------------------------------------------------
    echo "\n3. A batch of selected images\n";

    // The article as the author wrote it, without the plugin
    $db->setQuery('UPDATE #__content SET introtext = ' . $db->quote($text) . ', images = ' . $db->quote(json_encode(['image_intro' => $intro])) . ' WHERE id = ' . $id)->execute();

    $extractor = $runner->getExtractors()->get('article');

    $runner->getFileScanner()->add(array_keys($images));
    $runner->getIndexer()->indexOne('article', $id, $extractor->extractOne($id));

    $ids = array_map('intval', $db->setQuery(
        'SELECT id FROM #__mediaaudit_files WHERE path_hash IN (' . implode(',', array_map([$db, 'quote'], array_map('sha1', array_keys($images)))) . ')'
    )->loadColumn());

    $check('the images are in the index of the files', \count($ids) === 2);

    $batch  = $runner->getBatchConverter();
    $listed = $batch->candidates('webp', 0, $ids);

    $check('the selected images are the batch, the largest first', \count($listed) === 2 && $listed[0]['size'] >= $listed[1]['size']);

    $waiting = $batch->candidates('webp', 50);
    $wrong   = array_filter($waiting, static fn (array $image): bool => !preg_match('/\.(jpe?g|png)$/i', $image['path']) || str_contains($image['path'], '_mediaaudit_quarantine'));

    $check('the images that wait are JPEG and PNG outside the quarantine', !$wrong, \count($waiting) . ' of the first 50');
    $check('images that were converted do not wait', !array_intersect(array_column($batch->candidates('webp'), 'path'), array_keys($images)));

    $stats = $batch->run('webp', 82, 10, 1, 0.0, $ids, $userId);

    $check('a batch takes no more images than it may', $stats['images'] === 1 && $stats['left'] === 1 && \count($stats['targets']) === 1, json_encode(array_diff_key($stats, ['targets' => 1])));

    $steps = [];
    $stats = $batch->run('webp', 82, 10, 0, 0.0, $ids, $userId, 40000000, false, static function (int $done, int $total, string $path, ?array $result) use (&$steps): void {
        $steps[] = $done . '/' . $total . ($result === null ? ' before' : ' after');
    });

    $check('a batch tells how far it is', $steps === ['0/2 before', '1/2 after', '1/2 before', '2/2 after'], implode(', ', $steps));
    $check('the batch has the converted files of its images', $stats['converted'] === 2 && $stats['reused'] === 2 && $stats['left'] === 0 && \count($stats['targets']) === 2);

    $dry = $runner->getRewriter()->rewriteConverted($stats['targets'], true, $userId);

    $check(
        'a dry run counts and writes nothing',
        $dry['rewritten'] >= 1 && $dry['replaced'] >= 4 && (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id) === $text,
        json_encode($dry)
    );

    $done = $runner->getRewriter()->rewriteConverted($stats['targets'], false, $userId);

    $check('the content is rewritten', $done['rewritten'] === $dry['rewritten'] && $done['replaced'] === $dry['replaced']);
    $check('the article is what saving made of it', (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id) === $stored->introtext);
    $check(
        'the images have no reference of the article any more',
        (int) $one('SELECT COUNT(*) FROM #__mediaaudit_refs WHERE source_type = ' . $db->quote('article') . ' AND source_id = ' . $id
            . ' AND path_hash IN (' . implode(',', array_map([$db, 'quote'], array_map('sha1', array_keys($images)))) . ')') === 0
    );
} finally {
    // Emptied, and forgotten
    $db->setQuery('UPDATE #__content SET introtext = ' . $db->quote('') . ', images = ' . $db->quote('{}') . ' WHERE id = ' . $id)->execute();
    $db->setQuery('DELETE FROM #__mediaaudit_rewrites WHERE id > ' . $backups . ' AND source_id = ' . $id)->execute();
    $db->setQuery('DELETE FROM #__mediaaudit_refs WHERE source_type IN (' . $db->quote('article') . ',' . $db->quote('article_field') . ') AND source_id = ' . $id)->execute();
}

foreach ($images as $path => $sha1) {
    $check('at the end, the image is as it was: ' . basename($path), sha1_file("$root/$path") === $sha1);
}

$check('the article is empty again', (string) $one('SELECT introtext FROM #__content WHERE id = ' . $id) === '');

echo $failed ? "\nFAILED: $failed\n" : "\nOK\n";
exit($failed ? 1 : 0);
