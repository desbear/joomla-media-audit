<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Gss\Plugin\Mediaaudit\Flexicontent\Extension\Flexicontent;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('mediaaudit', 'flexicontent');

                // Joomla 5.4+ plugins take only the config; older versions expect the dispatcher first
                $first = (new \ReflectionMethod(CMSPlugin::class, '__construct'))->getParameters()[0]->getName();

                if ($first === 'config') {
                    $plugin = new Flexicontent($config);
                } else {
                    $dispatcher = $container->get(DispatcherInterface::class);
                    $plugin     = new Flexicontent($dispatcher, $config);
                }

                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
