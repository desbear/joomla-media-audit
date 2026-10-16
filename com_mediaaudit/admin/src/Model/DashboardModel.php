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
use Gss\Component\Mediaaudit\Administrator\Report\ScanHistory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

\defined('_JEXEC') or die;

/**
 * The numbers of the first page.
 */
class DashboardModel extends BaseDatabaseModel
{
    /**
     * @return  array<string, int>
     */
    public function getSummary(): array
    {
        return (new ReportRepository($this->getDatabase()))->summary();
    }

    /**
     * @return  array{scanned_at: string, seconds: float, source: string, summary: array<string, int>}|null
     */
    public function getLastScan(): ?array
    {
        return (new ScanHistory($this->getDatabase()))->last();
    }

    /**
     * The scan before the last, to tell what changed.
     */
    public function getPreviousScan(): ?array
    {
        return (new ScanHistory($this->getDatabase()))->last(1);
    }

    /**
     * @return  list<array{host: string, urls: int, references: int, checked: int, broken: int, copies: int}>
     */
    public function getExternalHosts(): array
    {
        return (new ReportRepository($this->getDatabase()))->externalHosts();
    }

    /**
     * @return  array{copies: int, files: int, bytes: int, failed: int, rewritten: int}
     */
    public function getLocalized(): array
    {
        return (new LocalizedModel(['ignore_request' => true, 'dbo' => $this->getDatabase()]))->getTotals();
    }

    /**
     * @return  list<array{scanned_at: string, seconds: float, source: string, summary: array<string, int>}>
     */
    public function getHistory(): array
    {
        return (new ScanHistory($this->getDatabase()))->recent(12);
    }

    /**
     * @return  array<string, array{references: int, sources: int, published: int}>
     */
    public function getSources(): array
    {
        return (new ReportRepository($this->getDatabase()))->sources();
    }

    /**
     * @return  list<array{host: string, copied: int, failed: int, waiting: int}>
     */
    public function getExternalByHost(): array
    {
        return (new ReportRepository($this->getDatabase()))->externalByHost();
    }

    /**
     * @return  array<string, int>
     */
    public function getLikelyOwnHosts(): array
    {
        return (new ReportRepository($this->getDatabase()))->likelyOwnHosts();
    }

    public function getQuarantined(): int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mediaaudit_quarantine'))
            ->where($db->quoteName('restored_at') . ' IS NULL');

        return (int) $db->setQuery($query)->loadResult();
    }
}
