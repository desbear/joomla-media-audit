<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mcp_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Mediaaudit\Extension;

use Gss\Plugin\Mcp\Mediaaudit\Tool\MediaAuditTools;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Provides the tools of Media Audit to the MCP server.
 */
final class Mediaaudit extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onMcpRegisterTools' => 'onMcpRegisterTools'];
    }

    public function onMcpRegisterTools(EventInterface $event): void
    {
        $registry = $event->getArgument('registry');

        foreach (MediaAuditTools::tools() as $tool) {
            $registry->add($tool);
        }
    }
}
