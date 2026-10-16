<?php

/**
 * @package     Media Audit
 * @subpackage  plg_console_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Console\Mediaaudit\Extension;

use Gss\Plugin\Console\Mediaaudit\Command\CheckBrokenCommand;
use Gss\Plugin\Console\Mediaaudit\Command\ConvertCommand;
use Gss\Plugin\Console\Mediaaudit\Command\LocalizeCommand;
use Gss\Plugin\Console\Mediaaudit\Command\RemoveLostCommand;
use Gss\Plugin\Console\Mediaaudit\Command\ScanCommand;
use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Command line tools of Media Audit.
 */
final class Mediaaudit extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',
        ];
    }

    public function registerCommands(ApplicationEvent $event): void
    {
        $application = $event->getApplication();

        if (!method_exists($application, 'addCommand')) {
            return;
        }

        // The commands are described in the language of the plugin
        $this->loadLanguage();

        $db         = Factory::getContainer()->get(DatabaseInterface::class);
        $dispatcher = $application->getDispatcher();

        $application->addCommand(new ScanCommand($db, $dispatcher));
        $application->addCommand(new CheckBrokenCommand($db, $dispatcher));
        $application->addCommand(new LocalizeCommand($db, $dispatcher));
        $application->addCommand(new RemoveLostCommand($db, $dispatcher));
        $application->addCommand(new ConvertCommand($db, $dispatcher));
    }
}
