<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_easyblog
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mediaaudit\Easyblog\Extension;

use Gss\Component\Mediaaudit\Administrator\Event\CollectExtractorsEvent;
use Gss\Plugin\Mediaaudit\Easyblog\Extractor\EasyblogExtractor;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Tells Media Audit of the images in the posts of EasyBlog: covers, their variations, and what the text holds.
 */
final class Easyblog extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CollectExtractorsEvent::NAME => 'onCollectExtractors',
        ];
    }

    public function onCollectExtractors(CollectExtractorsEvent $event): void
    {
        $db = $event->getDatabase();

        // Installed with the package, whether the site has the component or not
        if ($db === null || !\in_array($db->getPrefix() . 'easyblog_post', $db->getTableList(), true)) {
            return;
        }

        $event->addExtractor(new EasyblogExtractor($db, $event->getReferenceBuilder(), $event->getRoot()));
    }
}
