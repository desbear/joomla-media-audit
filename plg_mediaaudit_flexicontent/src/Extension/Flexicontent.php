<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mediaaudit\Flexicontent\Extension;

use Gss\Component\Mediaaudit\Administrator\Event\CollectExtractorsEvent;
use Gss\Plugin\Mediaaudit\Flexicontent\Extractor\FlexicontentExtractor;
use Gss\Plugin\Mediaaudit\Flexicontent\Extractor\FlexicontentFieldExtractor;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Tells Media Audit of the images in the fields of FLEXIcontent, with the thumbnails that FLEXIcontent makes of them.
 */
final class Flexicontent extends CMSPlugin implements SubscriberInterface
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
        if ($db === null || !\in_array($db->getPrefix() . 'flexicontent_fields_item_relations', $db->getTableList(), true)) {
            return;
        }

        $event->addExtractor(new FlexicontentExtractor($db, $event->getReferenceBuilder(), $event->getRoot()));
        $event->addExtractor(new FlexicontentFieldExtractor($db, $event->getReferenceBuilder(), $event->getRoot()));
    }
}
