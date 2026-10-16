<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Extractor;

\defined('_JEXEC') or die;

/**
 * For extractors that can tell which of their sources an event of Joomla is about, so that the
 * index follows what is saved, deleted and published without a scan.
 */
interface ContextAwareInterface
{
    /**
     * The contexts of the events that concern the sources of the extractor, like
     * "com_content.article".
     *
     * @return  string[]
     */
    public function getContexts(): array;

    /**
     * The sources that the row of the event stands for.
     *
     * @param   object  $row  The table object of the event.
     *
     * @return  int[]
     */
    public function getSourceIds(string $context, object $row): array;

    /**
     * Whether the sources are written after the event, by another plugin. Their references are read
     * at the end of the request then, not in the event.
     */
    public function isWrittenLater(): bool;
}
