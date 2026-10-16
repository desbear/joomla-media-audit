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
 * For extractors of components that keep a copy of the text of a row somewhere else, like the
 * versions of FLEXIcontent: they are told when the row was rewritten, to do the same to the copy.
 */
interface RewriteListenerInterface
{
    /**
     * @param  string    $table    Table of the row that was rewritten, like "#__content".
     * @param  int       $id       Of the row.
     * @param  callable  $rewrite  function (string $html): string. Replaces in a text what was
     *                             replaced in the row.
     */
    public function afterRewrite(string $table, int $id, callable $rewrite): void;
}
