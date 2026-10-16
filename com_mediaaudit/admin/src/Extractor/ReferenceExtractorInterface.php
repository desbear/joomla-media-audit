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
 * Finds the references to files in one kind of source.
 *
 * Plugins add extractors of their own in the onMediaAuditCollectExtractors event.
 */
interface ReferenceExtractorInterface
{
    /**
     * Like "article". At most 50 characters.
     */
    public function getSourceType(): string;

    /**
     * Goes through all the sources in batches, and calls $emit with every Reference.
     */
    public function extractAll(callable $emit, int $batchSize = 200): void;

    /**
     * The references of one source, for the update after a save.
     *
     * @return  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]
     */
    public function extractOne(int $sourceId): array;
}
