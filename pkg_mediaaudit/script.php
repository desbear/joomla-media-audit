<?php

/**
 * @package     Media Audit
 * @subpackage  pkg_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Checks the host before installing, and switches the plugins on after a first install.
 */
class Pkg_MediaauditInstallerScript
{
    private const MIN_PHP    = '8.1.0';
    private const MIN_JOOMLA = '4.4.0';

    private const PLUGIN_GROUPS = ['console', 'task', 'system', 'webservices', 'mcp'];

    /** The newest extension of the site before the package was installed */
    private int $lastExtension = 0;

    public function preflight($type, $parent): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $this->lastExtension = (int) $db->setQuery(
                $db->getQuery(true)->select('MAX(' . $db->quoteName('extension_id') . ')')->from($db->quoteName('#__extensions'))
            )->loadResult();
        } catch (\Throwable $e) {
            $this->lastExtension = PHP_INT_MAX;
        }

        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_MEDIAAUDIT_ERR_PHP', self::MIN_PHP, PHP_VERSION), 'error');

            return false;
        }

        if (version_compare((new Version())->getShortVersion(), self::MIN_JOOMLA, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_MEDIAAUDIT_ERR_JOOMLA', self::MIN_JOOMLA), 'error');

            return false;
        }

        return true;
    }

    public function postflight($type, $parent): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if ($type === 'uninstall') {
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__action_logs_extensions'))
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_mediaaudit'));

            try {
                $db->setQuery($query)->execute();
            } catch (\Throwable $e) {
            }

            return;
        }

        $this->replaceContentPlugin($db, $type === 'install');
        $this->registerForActionLog($db);

        $this->enableNewPlugins($db);
        $this->nameKindsOfRewrites($db);

        // Leave the choice alone on updates: an administrator may have switched a plugin off
        if ($type !== 'install') {
            $this->forgetPluginList();

            return;
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('mediaaudit'))
            ->whereIn($db->quoteName('folder'), self::PLUGIN_GROUPS, ParameterType::STRING);

        $db->setQuery($query)->execute();

        // The plugins for other components: they do nothing on a site without their component
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('mediaaudit'));

        $db->setQuery($query)->execute();

        $this->forgetPluginList();
    }

    /**
     * A site with the cache on keeps the list of its plugins in the cache. The plugins were switched
     * on in the table, which the cache does not know of.
     */
    private function forgetPluginList(): void
    {
        try {
            Factory::getContainer()
                ->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => 'com_plugins'])
                ->clean();
        } catch (\Throwable $e) {
            // The list is read anew when the cache expires
        }
    }

    /**
     * Plugins that came with this version are off, like every plugin that Joomla installs. A plugin
     * that was there before keeps the state that the administrator gave it.
     */
    /**
     * 0.12.0 and 0.12.1 kept the changes of a batch under the kind "html" or "json", which is what
     * the column holds and not what was done to it. A change was made for a conversion when its
     * text refers to an image that was converted; every other one was made for a copy.
     */
    private function nameKindsOfRewrites(DatabaseInterface $db): void
    {
        try {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'original']))
                ->from($db->quoteName('#__mediaaudit_rewrites'))
                ->whereIn($db->quoteName('kind'), ['html', 'json'], ParameterType::STRING);

            $rows = $db->setQuery($query)->loadObjectList();

            if (!$rows) {
                return;
            }

            $query = $db->getQuery(true)
                ->select($db->quoteName('source_path'))
                ->from($db->quoteName('#__mediaaudit_conversions'))
                ->where($db->quoteName('status') . ' = ' . $db->quote('converted'));

            $images = [];

            foreach ($db->setQuery($query)->loadColumn() as $path) {
                $images[$path] = true;
                $images[implode('/', array_map('rawurlencode', explode('/', $path)))] = true;
            }

            foreach ($rows as $row) {
                $kind = 'localize';

                foreach (array_keys($images) as $path) {
                    if (str_contains((string) $row->original, (string) $path) || str_contains((string) $row->original, str_replace('/', '\\/', (string) $path))) {
                        $kind = 'convert';

                        break;
                    }
                }

                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__mediaaudit_rewrites'))
                    ->set($db->quoteName('kind') . ' = ' . $db->quote($kind))
                    ->where($db->quoteName('id') . ' = ' . (int) $row->id);

                $db->setQuery($query)->execute();
            }
        } catch (\Throwable $e) {
            // The tables are not there on a first installation
        }
    }

    private function enableNewPlugins(DatabaseInterface $db): void
    {
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where(
                '(' . $db->quoteName('folder') . ' = ' . $db->quote('mediaaudit')
                . ' OR (' . $db->quoteName('element') . ' = ' . $db->quote('mediaaudit')
                . ' AND ' . $db->quoteName('folder') . ' IN (' . implode(',', array_map([$db, 'quote'], self::PLUGIN_GROUPS)) . ')))'
            )
            ->where($db->quoteName('enabled') . ' = 0')
            // Never used: the state is the one of the installation
            ->where($db->quoteName('extension_id') . ' > ' . (int) $this->lastExtension);

        $db->setQuery($query)->execute();
    }

    /**
     * Makes Media Audit one of the extensions that the User Actions Log can be filtered by.
     */
    private function registerForActionLog(DatabaseInterface $db): void
    {
        try {
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__action_logs_extensions'))
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_mediaaudit'));

            if (!$db->setQuery($query)->loadResult()) {
                $row = (object) ['extension' => 'com_mediaaudit'];

                $db->insertObject('#__action_logs_extensions', $row);
            }
        } catch (\Throwable $e) {
            // A site without the log of the actions
        }
    }

    /**
     * Up to 0.3.0 the index was kept by a content plugin. Modules and template styles are saved
     * without the content plugins, so a system plugin took its place in 0.4.0.
     */
    private function replaceContentPlugin(DatabaseInterface $db, bool $isInstall): void
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'enabled']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('content'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('mediaaudit'));

        $old = $db->setQuery($query)->loadObject();

        if (!$old) {
            return;
        }

        // The new plugin is on or off as the old one was
        if (!$isInstall) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = ' . (int) $old->enabled)
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('mediaaudit'));
            $db->setQuery($query)->execute();
        }

        // Out of the package first, or the installer refuses to remove it
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('package_id') . ' = 0')
            ->where($db->quoteName('extension_id') . ' = ' . (int) $old->extension_id);
        $db->setQuery($query)->execute();

        (new \Joomla\CMS\Installer\Installer())->uninstall('plugin', (int) $old->extension_id);
    }
}
