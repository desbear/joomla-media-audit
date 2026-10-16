<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Model;

use Gss\Component\Mediaaudit\Administrator\Report\ReportRepository;

\defined('_JEXEC') or die;

class SemiorphansModel extends FilesModel
{
    protected string $class = ReportRepository::SEMI_ORPHAN;
}
