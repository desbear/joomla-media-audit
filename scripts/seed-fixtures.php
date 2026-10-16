<?php

/**
 * Creates the test images and content of Media Audit in a LOCAL development site.
 *
 * Every record and file carries "mediaaudit-fixture" or lives in images/_mediaaudit_fixtures/, so that
 * the fixtures never mix with the real content of the site. Running the script again updates the
 * records in place.
 *
 * Records are written through the Joomla table classes without loading any plugin, so nothing is
 * indexed, translated or sent to another system.
 *
 * Usage:
 *   php seed-fixtures.php [--root=/path/to/site] [--domain=site.test]
 *                         [--site-domains=www.example.com]
 *                         [--localize-hosts=cdn.example.org,old.example.net]
 *                         [--cleanup]
 *
 * --domain is the domain of the local site, used in the absolute URL of scenario 4. Without it,
 * MEDIAAUDIT_DOMAIN of the environment, or localhost.
 * --site-domains are the other domains that serve the files of this site, like the production
 * domain. Both are written to the "Site domains" option of the component, replacing what is there.
 *
 * --localize-hosts are the sites that the articles load images from and whose images are to be
 * copied to this site. They are written to the "Hosts to copy from" option when that is empty.
 * Without them, the option is left as it is.
 *
 * --cleanup removes the records and the CSS file again. Image files are never deleted: remove
 * images/_mediaaudit_fixtures/ by hand when it is no longer needed.
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Category;
use Joomla\CMS\Table\Content;
use Joomla\CMS\Table\Menu;
use Joomla\CMS\Table\MenuType;
use Joomla\CMS\Table\Module;
use Joomla\CMS\Table\Table;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root::', 'domain::', 'site-domains::', 'localize-hosts::', 'cleanup']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');
$domain  = $options['domain'] ?? (getenv('MEDIAAUDIT_DOMAIN') ?: 'localhost');
$domains = array_filter(array_map('trim', explode(',', $options['site-domains'] ?? '')));
$copyFrom = array_filter(array_map('trim', explode(',', $options['localize-hosts'] ?? '')));
$cleanup  = isset($options['cleanup']);

// Never run against a site whose database is somewhere else
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

const _JEXEC = 1;
\define('JPATH_BASE', $root);

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

// The application is created but never executed, which is what keeps the plugins out
$app                  = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;

/** @var \Joomla\Database\DatabaseDriver $db */
$db = $container->get(DatabaseInterface::class);

const FIXTURE_DIR   = 'images/_mediaaudit_fixtures';
const FIXTURE_ALIAS = 'mediaaudit-fixture';
const FIXTURE_TITLE = 'MediaAudit Fixture';
const FIXTURE_CSS   = 'media/templates/site/cassiopeia/css/mediaaudit-fixture.css';

$dir = FIXTURE_DIR;

/*
 * The scenarios of section 7 of the specification.
 *
 * files:    images to create, with the class the report has to give them.
 * sources:  records that refer to them.
 * broken:   references that have to be reported as broken.
 */
$files = [
    'a.jpg'       => ['scenario' => 1, 'expect' => 'in_use'],
    'b.jpg'       => ['scenario' => 2, 'expect' => 'in_use'],
    '產品圖.png'     => ['scenario' => 3, 'expect' => 'in_use'],
    'c.jpg'       => ['scenario' => 4, 'expect' => 'in_use'],
    'd-800.jpg'   => ['scenario' => 5, 'expect' => 'in_use'],
    'd-400.jpg'   => ['scenario' => 5, 'expect' => 'in_use'],
    'e.jpg'       => ['scenario' => 6, 'expect' => 'in_use'],
    'f.jpg'       => ['scenario' => 7, 'expect' => 'in_use'],
    'g.jpg'       => ['scenario' => 8, 'expect' => 'semi_orphan'],
    'h.jpg'       => ['scenario' => 9, 'expect' => 'semi_orphan'],
    'orphan.jpg'  => ['scenario' => 10, 'expect' => 'orphan'],
    // The only reference is spelled Photo.JPG, which is another file on a case sensitive file system
    'photo.jpg'   => ['scenario' => 12, 'expect' => 'orphan', 'case_suspect_of' => 'Photo.JPG'],
    'dup1.jpg'    => ['scenario' => 13, 'expect' => 'in_use', 'duplicate_group' => 'dup'],
    'dup2.jpg'    => ['scenario' => 13, 'expect' => 'in_use', 'duplicate_group' => 'dup', 'copy_of' => 'dup1.jpg'],
    // Found by the extractors of Phase 4 only; orphans until then
    'cat.jpg'     => ['scenario' => 14, 'expect' => 'in_use', 'since_phase' => 4],
    'menu.png'    => ['scenario' => 14, 'expect' => 'in_use', 'since_phase' => 4],
    'logo.png'    => ['scenario' => 14, 'expect' => 'in_use', 'since_phase' => 4],
    'cssbg.png'   => ['scenario' => 14, 'expect' => 'in_use', 'since_phase' => 4],
    // Not in the specification: a custom field of the media type, and one of the editor type
    'field.jpg'   => ['scenario' => 15, 'expect' => 'in_use', 'since_phase' => 4],
    'field2.jpg'  => ['scenario' => 15, 'expect' => 'in_use', 'since_phase' => 4],
];

$broken = [
    'missing.jpg' => ['scenario' => 11, 'case_suspect' => null],
    'Photo.JPG'   => ['scenario' => 12, 'case_suspect' => 'photo.jpg'],
];

$encoded  = rawurlencode('產品圖.png');
$articles = [
    1  => ['state' => 1, 'introtext' => "<p><img src=\"$dir/a.jpg\" alt=\"a\"></p>"],
    2  => ['state' => 1, 'introtext' => '<p>Intro image field.</p>', 'images' => [
        'image_intro'     => "$dir/b.jpg#joomlaImage://local-$dir/b.jpg?width=320&height=200",
        'image_intro_alt' => 'b',
    ]],
    3  => ['state' => 1, 'introtext' => "<p><img src=\"$dir/$encoded\" alt=\"URL encoded name\"></p>"],
    4  => ['state' => 1, 'introtext' => "<p><img src=\"https://$domain/$dir/c.jpg\" alt=\"absolute\"></p>"],
    5  => ['state' => 1, 'introtext' => "<p><img src=\"$dir/d-800.jpg\" srcset=\"$dir/d-800.jpg 800w, $dir/d-400.jpg 400w\" sizes=\"100vw\" alt=\"srcset\"></p>"],
    6  => ['state' => 1, 'introtext' => "<p><img class=\"lazyload\" data-src=\"$dir/e.jpg\" alt=\"lazy\"></p>"],
    7  => ['state' => 1, 'introtext' => "<div style=\"background-image:url('/$dir/f.jpg')\">inline style</div>"],
    8  => ['state' => -2, 'introtext' => "<p><img src=\"$dir/g.jpg\" alt=\"trashed\"></p>"],
    11 => ['state' => 1, 'introtext' => "<p><img src=\"$dir/missing.jpg\" alt=\"missing\"></p>"],
    12 => ['state' => 1, 'introtext' => "<p><img src=\"$dir/Photo.JPG\" alt=\"wrong case\"></p>"],
    13 => ['state' => 1, 'introtext' => "<p><img src=\"$dir/dup1.jpg\" alt=\"dup1\"><img src=\"$dir/dup2.jpg\" alt=\"dup2\"></p>"],
];

$user = firstSuperUser($db, $container);
$app->loadIdentity($user);

if ($cleanup) {
    cleanup($db);
    exit(0);
}

// ---------------------------------------------------------------------------------------------
// Images
// ---------------------------------------------------------------------------------------------

$target = JPATH_ROOT . '/' . FIXTURE_DIR;

if (!is_dir($target) && !mkdir($target, 0755, true)) {
    fail("Cannot create $target");
}

if (!is_file("$target/index.html")) {
    file_put_contents("$target/index.html", '<!DOCTYPE html><title></title>');
}

foreach ($files as $name => $file) {
    $path = "$target/$name";

    // Existing files stay as they are, so that their modification time does not move
    if (is_file($path)) {
        continue;
    }

    $bytes = isset($file['copy_of']) ? file_get_contents("$target/{$file['copy_of']}") : drawImage($name);

    if (file_put_contents($path, $bytes) === false) {
        fail("Cannot write $path");
    }
}

out(\count($files) . ' images in ' . FIXTURE_DIR);

// ---------------------------------------------------------------------------------------------
// Category, with its image (scenario 14)
// ---------------------------------------------------------------------------------------------

$category = new Category($db);
$isNew    = !$category->load(['alias' => FIXTURE_ALIAS, 'extension' => 'com_content']);

if ($isNew) {
    $category->setLocation(1, 'last-child');
}

save($category, [
    'title'           => FIXTURE_TITLE,
    'alias'           => FIXTURE_ALIAS,
    'extension'       => 'com_content',
    'parent_id'       => 1,
    'published'       => 1,
    'access'          => 1,
    'language'        => '*',
    'description'     => '<p>Test content of Media Audit. Safe to remove with seed-fixtures.php --cleanup.</p>',
    'params'          => json_encode(['image' => "$dir/cat.jpg", 'image_alt' => 'cat']),
    'metadata'        => '{}',
    'created_user_id' => $user->id,
]);
$category->rebuildPath($category->id);

$seeded = ['category' => (int) $category->id, 'articles' => []];

// ---------------------------------------------------------------------------------------------
// Articles (scenarios 1 to 8 and 11 to 13)
// ---------------------------------------------------------------------------------------------

$stage = defaultStage($db);

foreach ($articles as $number => $article) {
    $alias = sprintf('%s-%02d', FIXTURE_ALIAS, $number);
    $table = new Content($db);
    $table->load(['alias' => $alias, 'catid' => $category->id]);

    save($table, [
        'title'      => sprintf('%s %02d', FIXTURE_TITLE, $number),
        'alias'      => $alias,
        'catid'      => $category->id,
        'state'      => $article['state'],
        'introtext'  => $article['introtext'],
        'fulltext'   => '',
        'images'     => json_encode($article['images'] ?? new \stdClass()),
        'urls'       => '{}',
        'attribs'    => '{}',
        'metadata'   => '{}',
        'metakey'    => '',
        'metadesc'   => '',
        'access'     => 1,
        'language'   => '*',
        'created_by' => $user->id,
    ], $user);

    associateStage($db, (int) $table->id, $stage);

    $seeded['articles'][$number] = (int) $table->id;
}

// ---------------------------------------------------------------------------------------------
// Unpublished custom HTML module (scenario 9)
// ---------------------------------------------------------------------------------------------

$module = new Module($db);
$module->load(['title' => FIXTURE_TITLE, 'module' => 'mod_custom']);

save($module, [
    'title'     => FIXTURE_TITLE,
    'module'    => 'mod_custom',
    'position'  => '',
    'content'   => "<p><img src=\"$dir/h.jpg\" alt=\"unpublished module\"></p>",
    'published' => 0,
    'access'    => 1,
    'client_id' => 0,
    'showtitle' => 0,
    'language'  => '*',
    'params'    => json_encode(['prepare_content' => 0, 'backgroundimage' => '']),
]);

$query = $db->getQuery(true)
    ->select('COUNT(*)')
    ->from($db->quoteName('#__modules_menu'))
    ->where($db->quoteName('moduleid') . ' = ' . (int) $module->id);

if (!$db->setQuery($query)->loadResult()) {
    $link = (object) ['moduleid' => (int) $module->id, 'menuid' => 0];
    $db->insertObject('#__modules_menu', $link);
}

$seeded['module'] = (int) $module->id;

// ---------------------------------------------------------------------------------------------
// Custom fields of the first article (scenario 15)
// ---------------------------------------------------------------------------------------------

$fields = [
    FIXTURE_ALIAS . '-media'  => ['media', json_encode(['imagefile' => "$dir/field.jpg#joomlaImage://local-$dir/field.jpg?width=320&height=200", 'alt_text' => 'field'])],
    FIXTURE_ALIAS . '-editor' => ['editor', "<p><img src=\"$dir/field2.jpg\" alt=\"field2\"></p>"],
];

foreach ($fields as $name => [$type, $value]) {
    $query = $db->getQuery(true)
        ->select($db->quoteName('id'))
        ->from($db->quoteName('#__fields'))
        ->where($db->quoteName('name') . ' = ' . $db->quote($name))
        ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'));

    $fieldId = (int) $db->setQuery($query)->loadResult();

    if (!$fieldId) {
        $field = (object) [
            'context'     => 'com_content.article',
            'group_id'    => 0,
            'title'       => FIXTURE_TITLE . ' ' . $type,
            'name'        => $name,
            'label'       => FIXTURE_TITLE . ' ' . $type,
            'default_value' => '',
            'type'        => $type,
            'note'        => '',
            'description' => '',
            // Not shown on the site, and asked of nobody
            'state'       => 0,
            'required'    => 0,
            'only_use_in_subform' => 0,
            'language'    => '*',
            'created_time' => gmdate('Y-m-d H:i:s'),
            'created_user_id' => $user->id,
            'modified_time' => gmdate('Y-m-d H:i:s'),
            'modified_by' => $user->id,
            'params'      => '{}',
            'fieldparams' => '{}',
            'access'      => 1,
        ];

        $columns = $db->getTableColumns('#__fields');

        foreach (array_keys(get_object_vars($field)) as $column) {
            if (!isset($columns[$column])) {
                unset($field->$column);
            }
        }

        $db->insertObject('#__fields', $field, 'id');
        $fieldId = (int) $field->id;
    }

    $query = $db->getQuery(true)
        ->delete($db->quoteName('#__fields_values'))
        ->where($db->quoteName('field_id') . ' = ' . $fieldId);
    $db->setQuery($query)->execute();

    $row = (object) ['field_id' => $fieldId, 'item_id' => (string) $seeded['articles'][1], 'value' => $value];
    $db->insertObject('#__fields_values', $row);

    $seeded['fields'][$name] = $fieldId;
}

// ---------------------------------------------------------------------------------------------
// Menu item with an icon, in a menu of its own that no module shows (scenario 14)
// ---------------------------------------------------------------------------------------------

$menuType = new MenuType($db);
$menuType->load(['menutype' => FIXTURE_ALIAS]);

save($menuType, [
    'menutype'    => FIXTURE_ALIAS,
    'title'       => FIXTURE_TITLE,
    'description' => 'Test content of Media Audit',
    'client_id'   => 0,
]);

$menu  = new Menu($db);
$isNew = !$menu->load(['alias' => FIXTURE_ALIAS, 'menutype' => FIXTURE_ALIAS]);

if ($isNew) {
    $menu->setLocation(1, 'last-child');
}

save($menu, [
    'menutype'     => FIXTURE_ALIAS,
    'title'        => FIXTURE_TITLE,
    'alias'        => FIXTURE_ALIAS,
    'link'         => '#',
    'type'         => 'url',
    'published'    => 1,
    'parent_id'    => 1,
    'component_id' => 0,
    'access'       => 1,
    'client_id'    => 0,
    'language'     => '*',
    'img'          => '',
    'params'       => json_encode(['menu_image' => "$dir/menu.png", 'menu_image_css' => '', 'menu_text' => 1]),
]);
$menu->rebuildPath($menu->id);

$seeded['menu'] = (int) $menu->id;

// ---------------------------------------------------------------------------------------------
// Template style with a logo: a copy of Cassiopeia that is not the default (scenario 14)
// ---------------------------------------------------------------------------------------------

$query = $db->getQuery(true)
    ->select($db->quoteName('id'))
    ->from($db->quoteName('#__template_styles'))
    ->where($db->quoteName('title') . ' = ' . $db->quote(FIXTURE_TITLE));

$style = (object) [
    'id'        => (int) $db->setQuery($query)->loadResult(),
    'template'  => 'cassiopeia',
    'client_id' => 0,
    'home'      => '0',
    'title'     => FIXTURE_TITLE,
    'params'    => json_encode(['brand' => 1, 'logoFile' => "$dir/logo.png#joomlaImage://local-$dir/logo.png?width=320&height=200"]),
];

if ($style->id) {
    $db->updateObject('#__template_styles', $style, 'id');
} else {
    unset($style->id);
    $db->insertObject('#__template_styles', $style, 'id');
}

$seeded['template_style'] = (int) $style->id;

// ---------------------------------------------------------------------------------------------
// Style sheet with url(), relative to the style sheet (scenario 14)
// ---------------------------------------------------------------------------------------------

$css = JPATH_ROOT . '/' . FIXTURE_CSS;

if (!is_dir(\dirname($css))) {
    fail('Cannot find ' . \dirname(FIXTURE_CSS));
}

file_put_contents(
    $css,
    "/* Test content of Media Audit */\n.mediaaudit-fixture {\n    background-image: url(\"../../../../../$dir/cssbg.png\");\n}\n"
);

$seeded['css'] = FIXTURE_CSS;

// ---------------------------------------------------------------------------------------------
// Our own options, and the expected result for the acceptance tests
// ---------------------------------------------------------------------------------------------

rememberDomains($db, array_merge($domains, [$domain]), $copyFrom);

$expected = ['prefix' => "$dir/", 'domain' => $domain, 'files' => [], 'broken' => [], 'duplicates' => []];

foreach ($files as $name => $file) {
    $entry = ['scenario' => $file['scenario'], 'class' => $file['expect']];

    if (isset($file['since_phase'])) {
        $entry['since_phase'] = $file['since_phase'];
    }

    if (isset($file['case_suspect_of'])) {
        $entry['case_suspect_of'] = "$dir/{$file['case_suspect_of']}";
    }

    if (isset($file['duplicate_group'])) {
        $expected['duplicates'][$file['duplicate_group']][] = "$dir/$name";
    }

    $expected['files']["$dir/$name"] = $entry;
}

foreach ($broken as $name => $entry) {
    $expected['broken']["$dir/$name"] = [
        'scenario'     => $entry['scenario'],
        'case_suspect' => $entry['case_suspect'] === null ? null : "$dir/{$entry['case_suspect']}",
    ];
}

$expected['duplicates'] = array_values($expected['duplicates']);
$flags                  = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

file_put_contents(\dirname(__DIR__) . '/fixtures/expected.json', json_encode($expected, $flags) . "\n");
file_put_contents(\dirname(__DIR__) . '/fixtures/seeded.json', json_encode($seeded, $flags) . "\n");

out('category ' . $seeded['category'] . ', ' . \count($seeded['articles']) . ' articles, module ' . $seeded['module']
    . ', menu item ' . $seeded['menu'] . ', template style ' . $seeded['template_style']);
out('Expected result written to fixtures/expected.json');

// ---------------------------------------------------------------------------------------------

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * Binds, checks and stores, and stops at the first error.
 */
function save(Table $table, array $data, ?\Joomla\CMS\User\User $user = null): void
{
    if ($user !== null && method_exists($table, 'setCurrentUser')) {
        $table->setCurrentUser($user);
    }

    if (!$table->bind($data) || !$table->check() || !$table->store()) {
        fail(\get_class($table) . ': ' . $table->getError());
    }
}

function firstSuperUser(DatabaseInterface $db, \Joomla\DI\Container $container): \Joomla\CMS\User\User
{
    $query = $db->getQuery(true)
        ->select('MIN(' . $db->quoteName('u.id') . ')')
        ->from($db->quoteName('#__users', 'u'))
        ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm'), $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id'))
        ->where($db->quoteName('m.group_id') . ' = 8')
        ->where($db->quoteName('u.block') . ' = 0');

    $id = (int) $db->setQuery($query)->loadResult();

    if (!$id) {
        fail('The site has no active Super User.');
    }

    return $container->get(UserFactoryInterface::class)->loadUserById($id);
}

function defaultStage(DatabaseInterface $db): int
{
    $query = $db->getQuery(true)
        ->select($db->quoteName('s.id'))
        ->from($db->quoteName('#__workflow_stages', 's'))
        ->join('INNER', $db->quoteName('#__workflows', 'w'), $db->quoteName('w.id') . ' = ' . $db->quoteName('s.workflow_id'))
        ->where($db->quoteName('w.extension') . ' = ' . $db->quote('com_content.article'))
        ->where($db->quoteName('w.default') . ' = 1')
        ->where($db->quoteName('s.default') . ' = 1');

    return (int) $db->setQuery($query)->loadResult();
}

/**
 * The article list of the administrator only shows articles that are in a workflow stage.
 */
function associateStage(DatabaseInterface $db, int $articleId, int $stage): void
{
    if (!$stage) {
        return;
    }

    $query = $db->getQuery(true)
        ->select('COUNT(*)')
        ->from($db->quoteName('#__workflow_associations'))
        ->where($db->quoteName('item_id') . ' = ' . $articleId)
        ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content.article'));

    if ($db->setQuery($query)->loadResult()) {
        return;
    }

    $association = (object) [
        'item_id'   => $articleId,
        'stage_id'  => $stage,
        'extension' => 'com_content.article',
    ];
    $db->insertObject('#__workflow_associations', $association);
}

/**
 * Writes the "Site domains" option of the component.
 */
function rememberDomains(DatabaseInterface $db, array $domains, array $copyFrom): void
{
    $query = $db->getQuery(true)
        ->select($db->quoteName(['extension_id', 'params']))
        ->from($db->quoteName('#__extensions'))
        ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        ->where($db->quoteName('element') . ' = ' . $db->quote('com_mediaaudit'));

    $extension = $db->setQuery($query)->loadObject();

    if (!$extension) {
        out('com_mediaaudit is not installed: the "Site domains" option was not set.');

        return;
    }

    $params  = json_decode($extension->params ?: '{}', true) ?: [];
    $current = preg_split('/[\s,]+/', $params['site_domains'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
    $merged  = array_values(array_unique($domains));

    $hosts   = trim($params['localize_hosts'] ?? '') === '' ? implode("\n", $copyFrom) : $params['localize_hosts'];

    if ($merged === $current && $hosts === ($params['localize_hosts'] ?? '')) {
        return;
    }

    $params['site_domains']   = implode("\n", $merged);
    $params['localize_hosts'] = $hosts;
    $extension->params      = json_encode($params);

    $db->updateObject('#__extensions', $extension, 'extension_id');
}

/**
 * A small picture with the file name on it. The colour depends on the name, so every file differs.
 */
function drawImage(string $name): string
{
    $hash  = md5($name);
    $image = imagecreatetruecolor(320, 200);

    imagefill($image, 0, 0, imagecolorallocate($image, hexdec(substr($hash, 0, 2)), hexdec(substr($hash, 2, 2)), hexdec(substr($hash, 4, 2))));
    imagefilledrectangle($image, 0, 80, 320, 120, imagecolorallocate($image, 255, 255, 255));
    // The built-in font has no CJK glyphs: write the hash for names it cannot draw
    imagestring($image, 5, 10, 92, preg_match('/^[\x20-\x7e]+$/', $name) ? $name : substr($hash, 0, 12), imagecolorallocate($image, 0, 0, 0));

    ob_start();
    str_ends_with(strtolower($name), '.png') ? imagepng($image) : imagejpeg($image, null, 85);

    return ob_get_clean();
}

function cleanup(DatabaseInterface $db): void
{
    $like = $db->quote(FIXTURE_ALIAS . '%');

    // Articles, with their assets and workflow stages
    $query = $db->getQuery(true)
        ->select($db->quoteName('id'))
        ->from($db->quoteName('#__content'))
        ->where($db->quoteName('alias') . ' LIKE ' . $like);

    $ids = array_map('intval', $db->setQuery($query)->loadColumn());

    foreach ($ids as $id) {
        $table = new Content($db);

        if ($table->load($id) && !$table->delete()) {
            fail('Article ' . $id . ': ' . $table->getError());
        }
    }

    if ($ids) {
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__workflow_associations'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content.article'))
            ->whereIn($db->quoteName('item_id'), $ids, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    out(\count($ids) . ' articles removed');

    $query = $db->getQuery(true)
        ->select($db->quoteName('id'))
        ->from($db->quoteName('#__fields'))
        ->where($db->quoteName('name') . ' LIKE ' . $like);

    if ($fieldIds = array_map('intval', $db->setQuery($query)->loadColumn())) {
        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__fields_values'))->where($db->quoteName('field_id') . ' IN (' . implode(',', $fieldIds) . ')'))->execute();
        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__fields'))->where($db->quoteName('id') . ' IN (' . implode(',', $fieldIds) . ')'))->execute();
        out(\count($fieldIds) . ' custom fields removed');
    }

    $module = new Module($db);

    if ($module->load(['title' => FIXTURE_TITLE, 'module' => 'mod_custom'])) {
        $id = (int) $module->id;
        $module->delete();
        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__modules_menu'))->where($db->quoteName('moduleid') . ' = ' . $id))->execute();
        out('module removed');
    }

    $menu = new Menu($db);

    if ($menu->load(['alias' => FIXTURE_ALIAS, 'menutype' => FIXTURE_ALIAS])) {
        $menu->delete();
        out('menu item removed');
    }

    // Not through MenuType::delete(), which also looks for modules and items of the menu
    $query = $db->getQuery(true)
        ->select($db->quoteName(['id', 'asset_id']))
        ->from($db->quoteName('#__menu_types'))
        ->where($db->quoteName('menutype') . ' = ' . $db->quote(FIXTURE_ALIAS));

    if ($menuType = $db->setQuery($query)->loadObject()) {
        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__menu_types'))->where($db->quoteName('id') . ' = ' . (int) $menuType->id))->execute();

        if ((int) $menuType->asset_id) {
            $asset = Table::getInstance('Asset');

            if ($asset->load((int) $menuType->asset_id)) {
                $asset->delete();
            }
        }

        out('menu removed');
    }

    $category = new Category($db);

    if ($category->load(['alias' => FIXTURE_ALIAS, 'extension' => 'com_content'])) {
        $category->delete();
        out('category removed');
    }

    $query = $db->getQuery(true)
        ->delete($db->quoteName('#__template_styles'))
        ->where($db->quoteName('title') . ' = ' . $db->quote(FIXTURE_TITLE))
        ->where($db->quoteName('home') . ' = ' . $db->quote('0'));
    $db->setQuery($query)->execute();
    out('template style removed');

    if (is_file(JPATH_ROOT . '/' . FIXTURE_CSS)) {
        unlink(JPATH_ROOT . '/' . FIXTURE_CSS);
        out('style sheet removed');
    }

    out('Images are left in ' . FIXTURE_DIR . ': remove the folder by hand.');
}
