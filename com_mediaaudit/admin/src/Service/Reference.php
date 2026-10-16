<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Service;

\defined('_JEXEC') or die;

/**
 * One reference to a file, and where it was found.
 */
final class Reference
{
    /**
     * @param  NormalizedPath  $target       What the reference points to.
     * @param  string          $raw          The reference as it is written, for debugging.
     * @param  string          $sourceType   Like "article" or "module".
     * @param  int             $sourceId     Id of the record. 0 for sources that have none.
     * @param  string          $field        Like "introtext" or "images.image_intro".
     * @param  int|null        $sourceState  1, 0, 2 or -2. Null for sources without a state, which
     *                                        count as in use.
     */
    public function __construct(
        public readonly NormalizedPath $target,
        public readonly string $raw,
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly string $field,
        public readonly ?int $sourceState
    ) {
    }
}
