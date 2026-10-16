<?php

/**
 * @package     Media Audit
 * @subpackage  plg_webservices_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\WebServices\Mediaaudit\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

\defined('_JEXEC') or die;

/**
 * API routes of Media Audit.
 *
 * The routes are public for the router only: the controller asks for the token itself. A route that
 * the application guards answers a request without token with status 500 when the answer is plain
 * JSON, where 401 is what a client understands.
 */
final class Mediaaudit extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
    }

    public function onBeforeApiRoute(EventInterface $event): void
    {
        // Joomla 5 passes a BeforeApiRouteEvent, Joomla 4 an event with the router as its first argument
        $router   = method_exists($event, 'getRouter') ? $event->getRouter() : ($event->getArgument('router') ?? $event->getArgument(0));
        $defaults = ['component' => 'com_mediaaudit', 'format' => ['application/json'], 'public' => true];

        $router->addRoutes([
            new Route(['GET'], 'v1/mediaaudit/report', 'audit.report', [], $defaults),
            new Route(['GET'], 'v1/mediaaudit/summary', 'audit.summary', [], $defaults),
            new Route(['GET'], 'v1/mediaaudit/refs', 'audit.refs', [], $defaults),
            new Route(['POST'], 'v1/mediaaudit/quarantine', 'audit.quarantine', [], $defaults),
            new Route(['POST'], 'v1/mediaaudit/restore', 'audit.restore', [], $defaults),
            new Route(['POST'], 'v1/mediaaudit/scan', 'audit.scan', [], $defaults),
            new Route(['GET'], 'v1/mediaaudit/scan', 'audit.status', [], $defaults),
        ]);
    }
}
