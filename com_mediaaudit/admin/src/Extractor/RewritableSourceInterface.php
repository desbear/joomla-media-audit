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
 * For extractors whose sources can be rewritten: rows of a table with a numeric id, with the
 * references in columns of HTML or of JSON.
 */
interface RewritableSourceInterface
{
    /**
     * Like "#__content".
     */
    public function getRewritableTable(): string;

    /**
     * @return  string[]
     */
    public function getRewritableHtmlColumns(): array;

    /**
     * @return  string[]
     */
    public function getRewritableJsonColumns(): array;
}
