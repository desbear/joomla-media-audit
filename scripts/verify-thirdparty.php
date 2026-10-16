<?php

/**
 * Checks the extractors for FLEXIcontent, EasyBlog and Falang against the content of a LOCAL
 * development site that has these components. Reads only.
 *
 * The thumbnails of FLEXIcontent and the sizes of EasyBlog only count when they are on disk, and a
 * development site seldom has the images of the site it was copied from. So the extractors are told
 * here that every file is there, and what they would refer to is compared with what the settings of
 * the components say.
 *
 * Usage: php verify-thirdparty.php --root=/path/to/site
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser;
use Gss\Component\Mediaaudit\Administrator\Service\JsonReferenceWalker;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Gss\Plugin\Mediaaudit\Easyblog\Extractor\EasyblogExtractor;
use Gss\Plugin\Mediaaudit\Falang\Extractor\FalangExtractor;
use Gss\Plugin\Mediaaudit\Flexicontent\Extractor\FlexicontentExtractor;
use Gss\Plugin\Mediaaudit\Flexicontent\Extractor\FlexicontentFieldExtractor;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root:']);
$root    = rtrim($options['root'] ?? '', '/');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

$_SERVER['HTTP_HOST']   = 'localhost';
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
$db     = $container->get(DatabaseInterface::class);
$tables = $db->getTableList();
$has    = static fn (string $table): bool => \in_array($db->getPrefix() . $table, $tables, true);

$failed = 0;
$check  = static function (string $what, bool $ok, string $detail = '') use (&$failed): void {
    $failed += $ok ? 0 : 1;
    echo $ok ? 'ok      ' : 'FAILED  ', $what, $detail === '' ? '' : '  (' . $detail . ')', PHP_EOL;
};

$params  = ComponentHelper::getParams('com_mediaaudit');
$parser  = new HtmlReferenceParser();
$builder = new ReferenceBuilder(
    PathNormalizer::fromOptions((string) $params->get('site_domains', '')),
    $parser,
    new JsonReferenceWalker($parser)
);
$always  = static fn (string $path): bool => true;
$never   = static fn (string $path): bool => false;
$paths   = static fn (array $references, string $suffix = ''): array => array_values(array_map(
    static fn ($reference) => $reference->target->path,
    array_filter($references, static fn ($reference) => $suffix === '' ? !str_contains($reference->field, '#') : str_ends_with($reference->field, $suffix))
));

// ---------------------------------------------------------------------------------------------
if ($has('flexicontent_fields_item_relations')) {
    echo "--- FLEXIcontent\n";

    $component = json_decode((string) $db->setQuery("SELECT params FROM #__extensions WHERE type = 'component' AND element = 'com_flexicontent'")->loadResult(), true) ?: [];
    $uploads   = trim($component['file_path'] ?? 'components/com_flexicontent/uploads', '/');

    // One value of every way to keep the files that the site uses
    $fields = $db->setQuery("SELECT id, name, attribs FROM #__flexicontent_fields WHERE field_type = 'image'")->loadObjectList('id');
    $seen   = [];

    foreach ($fields as $field) {
        $settings = json_decode((string) $field->attribs, true) ?: [];
        $source   = (int) ($settings['image_source'] ?? 0);
        $shared   = $source === 0 && ($settings['list_all_media_files'] ?? 0) && (int) ($settings['unique_thumb_method'] ?? 0) === 0;
        $kind     = $source . ($shared ? ' shared' : '');

        if (isset($seen[$kind]) || $source < 0) {
            continue;
        }

        $value = $db->setQuery(
            'SELECT r.item_id, r.value FROM #__flexicontent_fields_item_relations r INNER JOIN #__content c ON c.id = r.item_id'
            . ' WHERE r.field_id = ' . (int) $field->id . " AND r.value LIKE 'a:%' ORDER BY r.item_id DESC",
            0,
            1
        )->loadObject();

        if (!$value) {
            continue;
        }

        $seen[$kind] = true;
        $name        = (string) (unserialize($value->value, ['allowed_classes' => false])['originalname'] ?? '');
        $folder      = trim($settings['dir'] ?? '', '/') ?: 'images/stories/flexicontent';
        $item        = (int) $value->item_id;

        if ($source >= 1) {
            $base     = $folder . '/item_' . $item . '_field_' . $field->id . '/';
            $original = $base . 'original/' . $name;
            $prefix   = '';
        } else {
            $base     = $folder . '/';
            $original = ((int) ($settings['target_dir'] ?? 1) ? $uploads : trim($component['media_path'] ?? 'components/com_flexicontent/medias', '/')) . '/' . $name;
            $prefix   = $shared ? 'fld' . $field->id . '_' : '';
        }

        $expected = array_map(static fn (string $size): string => $base . $size . '_' . $prefix . $name, ['l', 'm', 's', 'b']);
        $found    = (new FlexicontentExtractor($db, $builder, $root, $always))->extractOne($item);
        $ofField  = array_filter($found, static fn ($reference) => str_starts_with($reference->field, 'fields.' . $field->name));

        $label = 'field ' . $field->name . ', item ' . $item . ', files ' . ($source >= 1 ? 'by item' : 'of the component') . ($shared ? ', shared' : '');

        $check($label . ': the original', \in_array(PathNormalizer::composeUnicode($original), $paths($ofField), true), $original);
        $check($label . ': four thumbnails', array_values(array_intersect(array_map([PathNormalizer::class, 'composeUnicode'], $expected), $paths($ofField, '#thumbnail'))) === array_map([PathNormalizer::class, 'composeUnicode'], $expected), $expected[0]);

        $absent = (new FlexicontentExtractor($db, $builder, $root, $never))->extractOne($item);

        $check($label . ': no thumbnail that is not on disk', $paths($absent, '#thumbnail') === []);
    }

    $check('the ways to keep files that the site uses', $seen !== [], implode('; ', array_keys($seen)));

    // The image that a field shows for the items that have none
    foreach ($fields as $field) {
        $settings = json_decode((string) $field->attribs, true) ?: [];
        $default  = trim((string) ($settings['default_image'] ?? ''), '/ ');

        if ($default === '') {
            continue;
        }

        $folder   = trim($settings['dir'] ?? '', '/') ?: 'images/stories/flexicontent';
        $expected = array_map(static fn (string $size): string => $folder . '/' . $size . '_fld' . $field->id . '_' . basename($default), ['l', 'm', 's', 'b']);
        $found    = (new FlexicontentFieldExtractor($db, $builder, $root, $always))->extractOne((int) $field->id);

        $check('field ' . $field->name . ': the default image', \in_array($default, $paths($found), true), $default);
        $check('field ' . $field->name . ': four thumbnails of the default image', $paths($found, '#thumbnail') === $expected, $expected[0]);
        $check(
            'field ' . $field->name . ': no thumbnail that is not on disk',
            $paths((new FlexicontentFieldExtractor($db, $builder, $root, $never))->extractOne((int) $field->id), '#thumbnail') === []
        );
    }

    $state = $db->setQuery(
        'SELECT c.id, c.state FROM #__content c INNER JOIN #__flexicontent_fields_item_relations r ON r.item_id = c.id'
        . ' INNER JOIN #__flexicontent_fields f ON f.id = r.field_id WHERE f.field_type = ' . $db->quote('image') . ' AND c.state = -2',
        0,
        1
    )->loadObject();

    if ($state) {
        $found = (new FlexicontentExtractor($db, $builder, $root, $never))->extractOne((int) $state->id);

        $check('the images of a trashed item are of a trashed source', $found !== [] && array_unique(array_map(static fn ($r) => $r->sourceState, $found)) === [-2]);
    }
}

// ---------------------------------------------------------------------------------------------
if ($has('easyblog_post')) {
    echo "--- EasyBlog\n";

    foreach (['post:%' => 'images/easyblog_articles/', 'user:%' => 'images/easyblog_images/', 'shared/%' => 'images/easyblog_shared/'] as $like => $folder) {
        $post = $db->setQuery('SELECT id, image, published, state FROM #__easyblog_post WHERE image LIKE ' . $db->quote($like) . ' ORDER BY id DESC', 0, 1)->loadObject();

        if (!$post) {
            continue;
        }

        $expected = $folder . preg_replace('#^(?:post:|user:|shared/)#', '', $post->image);
        $found    = (new EasyblogExtractor($db, $builder, $root, $always))->extractOne((int) $post->id);
        $cover    = array_filter($found, static fn ($reference) => $reference->field === 'image');
        $sizes    = array_filter($found, static fn ($reference) => $reference->field === 'image#variation');

        $check('post ' . $post->id . ': the cover ' . $post->image, $paths($cover) === [PathNormalizer::composeUnicode($expected)], $expected);
        $check(
            'post ' . $post->id . ': six sizes of the cover',
            \count($sizes) === 6 && \in_array(PathNormalizer::composeUnicode(\dirname($expected) . '/b2ap3_large_' . basename($expected)), array_map(static fn ($r) => $r->target->path, $sizes), true)
        );
        $check(
            'post ' . $post->id . ': the state',
            array_unique(array_map(static fn ($r) => $r->sourceState, $found)) === [EasyblogExtractor::stateOf((int) $post->published, (int) $post->state)]
        );
    }

    $draft = $db->setQuery('SELECT id FROM #__easyblog_post WHERE published = 3 AND state = 0 AND (image <> ' . $db->quote('') . ' OR intro LIKE ' . $db->quote('%<img%') . ')', 0, 1)->loadResult();

    if ($draft) {
        $found = (new EasyblogExtractor($db, $builder, $root, $never))->extractOne((int) $draft);

        $check('the images of a draft are of an unpublished source', $found !== [] && array_unique(array_map(static fn ($r) => $r->sourceState, $found)) === [0]);
    }
}

// ---------------------------------------------------------------------------------------------
if ($has('falang_content')) {
    echo "--- Falang\n";

    $extractor = new FalangExtractor($db, $builder, $root);
    $where     = "f.published = 1 AND f.reference_table = 'content' AND (f.value LIKE '%<img%' OR f.value LIKE '%images/%')";

    foreach ([1 => 'published', 0 => 'unpublished', -2 => 'trashed'] as $state => $name) {
        $id = $db->setQuery(
            'SELECT f.id FROM #__falang_content f INNER JOIN #__content c ON c.id = f.reference_id WHERE ' . $where . ' AND c.state = ' . $state,
            0,
            1
        )->loadResult();

        if (!$id) {
            continue;
        }

        $found = $extractor->extractOne((int) $id);

        if ($found) {
            $check('the translation of a ' . $name . ' article is ' . $name, array_unique(array_map(static fn ($r) => $r->sourceState, $found)) === [$state], 'translation ' . $id);
        }
    }

    $id = $db->setQuery("SELECT f.id FROM #__falang_content f WHERE f.published = 0 AND (f.value LIKE '%<img%' OR f.value LIKE '%images/%')", 0, 1)->loadResult();

    if ($id && ($found = $extractor->extractOne((int) $id))) {
        $check('a translation that is not published is unpublished', array_unique(array_map(static fn ($r) => $r->sourceState, $found)) === [0], 'translation ' . $id);
    }
}

echo $failed ? "FAILED: $failed\n" : "OK\n";
exit($failed ? 1 : 0);
