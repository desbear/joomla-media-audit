<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Report;

use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * The summaries of the scans, to tell what changed since the last one.
 */
final class ScanHistory
{
    private const KEEP = 200;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * @param   string              $source   "cli", "task" or "admin".
     * @param   array<string, int>  $summary  Of ReportRepository::summary().
     */
    public function record(string $source, float $seconds, array $summary): void
    {
        $row = (object) [
            'scanned_at' => gmdate('Y-m-d H:i:s'),
            'seconds'    => round($seconds, 2),
            'source'     => substr($source, 0, 20),
            'summary'    => json_encode($summary),
        ];

        $this->db->insertObject('#__mediaaudit_scans', $row);

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__mediaaudit_scans'))
            ->order($this->db->quoteName('id') . ' DESC');

        $oldest = $this->db->setQuery($query, self::KEEP, 1)->loadResult();

        if ($oldest) {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__mediaaudit_scans'))
                ->where($this->db->quoteName('id') . ' <= ' . (int) $oldest);
            $this->db->setQuery($query)->execute();
        }
    }

    /**
     * The last scans, the oldest first.
     *
     * @return  list<array{scanned_at: string, seconds: float, source: string, summary: array<string, int>}>
     */
    public function recent(int $count = 12): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['scanned_at', 'seconds', 'source', 'summary']))
            ->from($this->db->quoteName('#__mediaaudit_scans'))
            ->order($this->db->quoteName('id') . ' DESC');

        $scans = [];

        foreach ($this->db->setQuery($query, 0, max(1, $count))->loadObjectList() as $row) {
            $scans[] = [
                'scanned_at' => $row->scanned_at,
                'seconds'    => (float) $row->seconds,
                'source'     => $row->source,
                'summary'    => array_map('intval', json_decode($row->summary, true) ?: []),
            ];
        }

        return array_reverse($scans);
    }

    /**
     * @param   int  $skip  0 for the last scan, 1 for the one before it.
     *
     * @return  array{scanned_at: string, seconds: float, source: string, summary: array<string, int>}|null
     */
    public function last(int $skip = 0): ?array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['scanned_at', 'seconds', 'source', 'summary']))
            ->from($this->db->quoteName('#__mediaaudit_scans'))
            ->order($this->db->quoteName('id') . ' DESC');

        $row = $this->db->setQuery($query, max(0, $skip), 1)->loadObject();

        if (!$row) {
            return null;
        }

        return [
            'scanned_at' => $row->scanned_at,
            'seconds'    => (float) $row->seconds,
            'source'     => $row->source,
            'summary'    => array_map('intval', json_decode($row->summary, true) ?: []),
        ];
    }
}
