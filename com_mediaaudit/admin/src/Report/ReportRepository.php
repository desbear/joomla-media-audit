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
 * Reads the classes of the report from the two index tables. Nothing of it is stored.
 *
 * The report only knows what the content of the site refers to. A file that is linked from another
 * site, or from an e-mail, looks like an orphan.
 */
final class ReportRepository
{
    public const IN_USE      = 'in_use';
    public const SEMI_ORPHAN = 'semi_orphan';
    public const ORPHAN      = 'orphan';

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * The files on disk, each with its class.
     *
     * @param   string       $prefix  Only paths that start with it, like "images/".
     * @param   string|null  $class   One of the class constants. Null for all.
     *
     * @return  list<array{path: string, class: string, size: int, sha1: ?string, refs: int, live_refs: int, case_suspect_of: ?string}>
     */
    public function files(string $prefix = 'images/', ?string $class = null): array
    {
        $db   = $this->db;
        $live = '(' . $db->quoteName('r.source_state') . ' = 1 OR ' . $db->quoteName('r.source_state') . ' IS NULL)';

        $query = $db->getQuery(true)
            ->select($db->quoteName(['f.path', 'f.path_ci_hash', 'f.size', 'f.sha1']))
            ->select('COUNT(' . $db->quoteName('r.id') . ') AS ' . $db->quoteName('refs'))
            ->select('COALESCE(SUM(' . $db->quoteName('r.id') . ' IS NOT NULL AND ' . $live . '), 0) AS ' . $db->quoteName('live_refs'))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join(
                'LEFT',
                $db->quoteName('#__mediaaudit_refs', 'r'),
                $db->quoteName('r.path_hash') . ' = ' . $db->quoteName('f.path_hash') . ' AND ' . $db->quoteName('r.is_external') . ' = 0'
            )
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->where($this->startsWith('f.path', $prefix))
            ->group($db->quoteName(['f.id', 'f.path', 'f.path_ci_hash', 'f.size', 'f.sha1']))
            ->order($db->quoteName('f.path') . ' ASC');

        if ($class === self::ORPHAN) {
            $query->having($db->quoteName('refs') . ' = 0');
        } elseif ($class === self::SEMI_ORPHAN) {
            $query->having($db->quoteName('refs') . ' > 0 AND ' . $db->quoteName('live_refs') . ' = 0');
        } elseif ($class === self::IN_USE) {
            $query->having($db->quoteName('live_refs') . ' > 0');
        }

        $suspects = $this->caseSuspects($prefix);
        $files    = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $refs = (int) $row->refs;
            $live = (int) $row->live_refs;

            $files[] = [
                'path'            => $row->path,
                'class'           => $refs === 0 ? self::ORPHAN : ($live === 0 ? self::SEMI_ORPHAN : self::IN_USE),
                'size'            => (int) $row->size,
                'sha1'            => $row->sha1,
                'refs'            => $refs,
                'live_refs'       => $live,
                'case_suspect_of' => $suspects['by_file'][$row->path] ?? null,
            ];
        }

        return $files;
    }

    /**
     * References to local files that are not there, one row for every path.
     *
     * @return  list<array{path: string, refs: int, live_refs: int, case_suspect: ?string, sources: list<array{type: string, id: int, field: string, state: ?int}>}>
     */
    public function broken(string $prefix = 'images/'): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['path', 'source_type', 'source_id', 'field', 'source_state']))
            ->from($db->quoteName('#__mediaaudit_refs'))
            ->where($db->quoteName('is_external') . ' = 0')
            ->where($db->quoteName('exists_local') . ' = 0')
            ->where($this->startsWith('path', $prefix))
            ->order($db->quoteName('path') . ' ASC, ' . $db->quoteName('source_type') . ' ASC, ' . $db->quoteName('source_id') . ' ASC');

        $suspects = $this->caseSuspects($prefix);
        $broken   = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $state = $row->source_state === null ? null : (int) $row->source_state;

            $broken[$row->path] ??= [
                'path'         => $row->path,
                'refs'         => 0,
                'live_refs'    => 0,
                'case_suspect' => $suspects['by_reference'][$row->path] ?? null,
                'sources'      => [],
            ];

            $broken[$row->path]['refs']++;
            $broken[$row->path]['live_refs'] += $state === null || $state === 1 ? 1 : 0;
            $broken[$row->path]['sources'][]  = [
                'type'  => $row->source_type,
                'id'    => (int) $row->source_id,
                'field' => $row->field,
                'state' => $state,
            ];
        }

        return array_values($broken);
    }

    /**
     * How many references every kind of source holds, the most first.
     *
     * @return  array<string, array{references: int, sources: int, published: int}>  By type
     */
    public function sources(): array
    {
        $db    = $this->db;
        $live  = '(' . $db->quoteName('source_state') . ' = 1 OR ' . $db->quoteName('source_state') . ' IS NULL)';
        $query = $db->getQuery(true)
            ->select($db->quoteName('source_type'))
            ->select('COUNT(*) AS ' . $db->quoteName('references'))
            ->select('COUNT(DISTINCT ' . $db->quoteName('source_id') . ') AS ' . $db->quoteName('sources'))
            ->select('COALESCE(SUM(' . $live . '), 0) AS ' . $db->quoteName('published'))
            ->from($db->quoteName('#__mediaaudit_refs'))
            // Derived files would count every image of FLEXIcontent five times
            ->where($db->quoteName('field') . ' NOT LIKE ' . $db->quote('%#thumbnail'))
            ->where($db->quoteName('field') . ' NOT LIKE ' . $db->quote('%#variation'))
            ->group($db->quoteName('source_type'))
            ->order($db->quoteName('references') . ' DESC, ' . $db->quoteName('source_type') . ' ASC');

        $sources = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $sources[$row->source_type] = ['references' => (int) $row->references, 'sources' => (int) $row->sources, 'published' => (int) $row->published];
        }

        return $sources;
    }

    /**
     * The images of other sites, by host: copied to this site, not copied because it failed, and
     * not tried yet.
     *
     * @return  list<array{host: string, copied: int, failed: int, waiting: int}>  The most images first
     */
    public function externalByHost(): array
    {
        $db    = $this->db;
        $hosts = [];

        $query = $db->getQuery(true)
            ->select($db->quoteName('host'))
            ->select('SUM(' . $db->quoteName('local_path') . ' IS NOT NULL) AS ' . $db->quoteName('copied'))
            ->select('SUM(' . $db->quoteName('local_path') . ' IS NULL AND ' . $db->quoteName('error') . ' <> ' . $db->quote('') . ') AS ' . $db->quoteName('failed'))
            ->from($db->quoteName('#__mediaaudit_urls'))
            ->group($db->quoteName('host'));

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $hosts[$row->host] = ['host' => $row->host, 'copied' => (int) $row->copied, 'failed' => (int) $row->failed, 'waiting' => 0];
        }

        // What the content still loads from there, and nobody has tried to copy
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('r.path'))
            ->from($db->quoteName('#__mediaaudit_refs', 'r'))
            ->join('LEFT', $db->quoteName('#__mediaaudit_urls', 'u'), $db->quoteName('u.url_hash') . ' = ' . $db->quoteName('r.path_hash'))
            ->where($db->quoteName('r.is_external') . ' = 1')
            ->where(self::isWaiting($db, 'u'));

        foreach ($db->setQuery($query)->loadColumn() as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            $hosts[$host] ??= ['host' => $host, 'copied' => 0, 'failed' => 0, 'waiting' => 0];
            $hosts[$host]['waiting']++;
        }

        $hosts = array_values(array_filter($hosts, static fn (array $host): bool => $host['copied'] + $host['failed'] + $host['waiting'] > 0));

        usort($hosts, static fn (array $a, array $b): int => array_sum(\array_slice($b, 1)) <=> array_sum(\array_slice($a, 1)) ?: strcmp($a['host'], $b['host']));

        return $hosts;
    }

    /**
     * The condition for an image of another site that nobody has tried to copy: nothing is known of
     * its address, or only that it answers.
     *
     * @param  string  $alias  Alias of the table of the addresses, joined to the references from the left.
     */
    public static function isWaiting(DatabaseInterface $db, string $alias): string
    {
        return '(' . $db->quoteName($alias . '.id') . ' IS NULL OR (' . $db->quoteName($alias . '.local_path') . ' IS NULL AND '
            . $db->quoteName($alias . '.error') . ' = ' . $db->quote('') . '))';
    }

    /**
     * The images of other sites, all hosts together.
     *
     * @return  array{copied: int, waiting: int, failed: int, unchanged: int}  Unchanged are the images
     *          that have a copy, and that the content loads from the other site all the same.
     */
    public function externalTotals(): array
    {
        $db     = $this->db;
        $totals = ['copied' => 0, 'waiting' => 0, 'failed' => 0, 'unchanged' => 0];

        foreach ($this->externalByHost() as $host) {
            $totals['copied']  += $host['copied'];
            $totals['waiting'] += $host['waiting'];
            $totals['failed']  += $host['failed'];
        }

        $query = $db->getQuery(true)
            ->select('COUNT(DISTINCT ' . $db->quoteName('u.id') . ')')
            ->from($db->quoteName('#__mediaaudit_urls', 'u'))
            ->join(
                'INNER',
                $db->quoteName('#__mediaaudit_refs', 'r'),
                $db->quoteName('r.path_hash') . ' = ' . $db->quoteName('u.url_hash') . ' AND ' . $db->quoteName('r.is_external') . ' = 1'
            )
            ->where($db->quoteName('u.local_path') . ' IS NOT NULL');

        $totals['unchanged'] = (int) $db->setQuery($query)->loadResult();

        return $totals;
    }

    /**
     * How the images of other sites came to this site: what was copied, what could not be copied and
     * what waited, at the end of every day on which images were copied. By the hour when it all
     * happened on one day.
     *
     * The first entry is the time before the first copy. The images that are known now are the whole
     * at every time: an image that the content got later counts as waiting from the start.
     *
     * @param   int  $columns  The most entries. More days are shown as fewer, each at its end.
     *
     * @return  list<array{at: string|null, by: string, copied: int, waiting: int, failed: int}>  The
     *          time is the start of the day or hour, in UTC. Null for the time before.
     */
    public function externalCourse(\DateTimeZone $zone, int $columns = 8): array
    {
        $db     = $this->db;
        $totals = $this->externalTotals();
        $whole  = $totals['copied'] + $totals['waiting'] + $totals['failed'];

        if ($whole === 0) {
            return [];
        }

        $hours = [];

        foreach (['copied' => 'fetched_at', 'failed' => 'checked_at'] as $what => $column) {
            $hour  = 'DATE_FORMAT(' . $db->quoteName($column) . ', ' . $db->quote('%Y-%m-%d %H:00:00') . ')';
            $query = $db->getQuery(true)
                ->select($hour . ' AS ' . $db->quoteName('hour') . ', COUNT(*) AS ' . $db->quoteName('images'))
                ->from($db->quoteName('#__mediaaudit_urls'))
                ->where($db->quoteName($column) . ' IS NOT NULL')
                ->group($hour);

            if ($what === 'copied') {
                $query->where($db->quoteName('local_path') . ' IS NOT NULL');
            } else {
                $query->where($db->quoteName('local_path') . ' IS NULL')->where($db->quoteName('error') . ' <> ' . $db->quote(''));
            }

            foreach ($db->setQuery($query)->loadObjectList() as $row) {
                $hours[$row->hour] ??= ['copied' => 0, 'failed' => 0];
                $hours[$row->hour][$what] += (int) $row->images;
            }
        }

        $course = [['at' => null, 'by' => 'day', 'copied' => 0, 'waiting' => $whole, 'failed' => 0]];

        if (!$hours) {
            return $course;
        }

        ksort($hours);

        // Days are the days of the site, not the ones of UTC
        $utc  = new \DateTimeZone('UTC');
        $days = [];

        foreach ($hours as $hour => $images) {
            $days[(new \DateTimeImmutable($hour, $utc))->setTimezone($zone)->format('Y-m-d')][$hour] = $images;
        }

        $by      = \count($days) > 1 ? 'day' : 'hour';
        $periods = [];

        foreach ($days as $day => $ofDay) {
            if ($by === 'day') {
                $start           = (new \DateTimeImmutable($day . ' 00:00:00', $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
                $periods[$start] = ['copied' => array_sum(array_column($ofDay, 'copied')), 'failed' => array_sum(array_column($ofDay, 'failed'))];
            } else {
                $periods = $ofDay;
            }
        }

        $copied = 0;
        $failed = 0;
        $states = [];

        foreach ($periods as $start => $images) {
            $copied  += $images['copied'];
            $failed  += $images['failed'];
            $states[] = ['at' => $start, 'by' => $by, 'copied' => $copied, 'waiting' => max(0, $whole - $copied - $failed), 'failed' => $failed];
        }

        // The last entry is now, whatever the times of the rows say
        $states[\count($states) - 1] = ['copied' => $totals['copied'], 'waiting' => $totals['waiting'], 'failed' => $totals['failed']] + $states[\count($states) - 1];

        $room = max(1, $columns - 1);

        if (\count($states) > $room) {
            $kept = [];

            for ($i = 1; $i <= $room; $i++) {
                $kept[(int) round($i * \count($states) / $room) - 1] = true;
            }

            $states = array_values(array_intersect_key($states, $kept));
        }

        return array_merge($course, $states);
    }

    /**
     * The condition for an image that content refers to in a way that is rewritten when the image
     * is converted. An image that only a template loads, or a field that is left as it is, or a
     * text that mentions its path, would be converted for nothing.
     *
     * @param  string  $alias        Alias of the table of the files in the query.
     * @param  array{types: string[], fixed: array<string, string[]>}  $convertible  Of SourceRewriter::getConvertible().
     */
    public static function isRewritten(DatabaseInterface $db, string $alias, array $convertible): string
    {
        if (!$convertible['types']) {
            return '1 = 0';
        }

        $where = [
            $db->quoteName('w.path_hash') . ' = ' . $db->quoteName($alias . '.path_hash'),
            $db->quoteName('w.is_external') . ' = 0',
            $db->quoteName('w.source_type') . ' IN (' . implode(',', array_map([$db, 'quote'], $convertible['types'])) . ')',
            $db->quoteName('w.field') . ' NOT LIKE ' . $db->quote('%#text'),
            $db->quoteName('w.field') . ' NOT LIKE ' . $db->quote('%#thumbnail'),
            $db->quoteName('w.field') . ' NOT LIKE ' . $db->quote('%#variation'),
        ];

        foreach ($convertible['fixed'] as $type => $fields) {
            $where[] = 'NOT (' . $db->quoteName('w.source_type') . ' = ' . $db->quote($type)
                . ' AND ' . $db->quoteName('w.field') . ' IN (' . implode(',', array_map([$db, 'quote'], $fields)) . '))';
        }

        return 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__mediaaudit_refs', 'w') . ' WHERE ' . implode(' AND ', $where) . ')';
    }

    /**
     * The condition for a file that a component made of another image: a thumbnail of FLEXIcontent,
     * a size of EasyBlog. Such files are told by the references that the extractors give them.
     *
     * Thumbnails of one image have the same content when the image is smaller than the sizes, and
     * so have the thumbnails of two fields that use the same image. That is how these components
     * work, and nothing that cleaning up can save.
     *
     * @param  string  $alias  Alias of the table of the files in the query.
     */
    public static function isDerived(DatabaseInterface $db, string $alias): string
    {
        return 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__mediaaudit_refs', 'd')
            . ' WHERE ' . $db->quoteName('d.path_hash') . ' = ' . $db->quoteName($alias . '.path_hash')
            . ' AND (' . $db->quoteName('d.field') . ' LIKE ' . $db->quote('%#thumbnail')
            . ' OR ' . $db->quoteName('d.field') . ' LIKE ' . $db->quote('%#variation') . '))';
    }

    /**
     * Files with the same content under different paths.
     *
     * @param   bool  $withDerived  Count the files that a component made of another image as well.
     *
     * @return  list<array{sha1: string, size: int, paths: string[]}>
     */
    public function duplicates(string $prefix = 'images/', bool $withDerived = false): array
    {
        $db = $this->db;

        $groups = $db->getQuery(true)
            ->select($db->quoteName('s.sha1'))
            ->from($db->quoteName('#__mediaaudit_files', 's'))
            ->where($db->quoteName('s.status') . ' = ' . $db->quote('active'))
            ->where($db->quoteName('s.sha1') . ' IS NOT NULL')
            ->where($this->startsWith('s.path', $prefix))
            ->group($db->quoteName('s.sha1'))
            ->having('COUNT(*) > 1');

        if (!$withDerived) {
            $groups->where('NOT ' . self::isDerived($db, 's'));
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName(['f.sha1', 'f.size', 'f.path']))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join('INNER', '(' . $groups . ') AS ' . $db->quoteName('g'), $db->quoteName('g.sha1') . ' = ' . $db->quoteName('f.sha1'))
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->where($this->startsWith('f.path', $prefix))
            ->order($db->quoteName('f.size') . ' DESC, ' . $db->quoteName('f.sha1') . ' ASC, ' . $db->quoteName('f.path') . ' ASC');

        if (!$withDerived) {
            $query->where('NOT ' . self::isDerived($db, 'f'));
        }

        $duplicates = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $duplicates[$row->sha1] ??= ['sha1' => $row->sha1, 'size' => (int) $row->size, 'paths' => []];
            $duplicates[$row->sha1]['paths'][] = $row->path;
        }

        return array_values($duplicates);
    }

    /**
     * Hosts of other sites whose addresses lead to files that this site has, at the same path: most
     * likely names of this site that the option "Domains of this site" does not list.
     *
     * @return  array<string, int>  Host => files of this site that it is used for, the most first
     */
    public function likelyOwnHosts(int $limit = 10): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('path'))
            ->from($db->quoteName('#__mediaaudit_refs'))
            ->where($db->quoteName('is_external') . ' = 1')
            ->where($db->quoteName('path') . ' LIKE ' . $db->quote('%://%/images/%', false));

        $byHash = [];

        foreach ($db->setQuery($query)->loadColumn() as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = ltrim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');

            // A site in a subfolder: from images/ on
            $at = strpos($path, 'images/');

            if ($host !== '' && $at !== false && mb_check_encoding($path, 'UTF-8')) {
                $byHash[sha1(substr($path, $at))][$host] = true;
            }
        }

        $hosts = [];

        foreach (array_chunk(array_keys($byHash), 500) as $chunk) {
            $query = $db->getQuery(true)
                ->select($db->quoteName('path_hash'))
                ->from($db->quoteName('#__mediaaudit_files'))
                ->where($db->quoteName('path_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], $chunk)) . ')');

            foreach ($db->setQuery($query)->loadColumn() as $hash) {
                foreach (array_keys($byHash[$hash]) as $host) {
                    $hosts[$host] = ($hosts[$host] ?? 0) + 1;
                }
            }
        }

        arsort($hosts);

        return \array_slice($hosts, 0, $limit, true);
    }

    /**
     * @return  array{files: int, in_use: int, semi_orphan: int, orphan: int, broken: int, duplicate_groups: int, duplicate_files: int, wasted_bytes: int, orphan_bytes: int, in_use_bytes: int, semi_orphan_bytes: int, references: int, external_references: int}
     */
    public function summary(string $prefix = 'images/'): array
    {
        $summary = [
            'files'               => 0,
            self::IN_USE          => 0,
            self::SEMI_ORPHAN     => 0,
            self::ORPHAN          => 0,
            'broken'              => \count($this->broken($prefix)),
            'duplicate_groups'    => 0,
            'duplicate_files'     => 0,
            'wasted_bytes'        => 0,
            'orphan_bytes'        => 0,
            'in_use_bytes'        => 0,
            'semi_orphan_bytes'   => 0,
            'references'          => 0,
            'external_references' => 0,
        ];

        foreach ($this->files($prefix) as $file) {
            $summary['files']++;
            $summary[$file['class']]++;
            $summary[$file['class'] . '_bytes'] += $file['size'];
        }

        foreach ($this->duplicates($prefix) as $group) {
            $summary['duplicate_groups']++;
            $summary['duplicate_files'] += \count($group['paths']);
            $summary['wasted_bytes']    += $group['size'] * (\count($group['paths']) - 1);
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('COUNT(*) AS ' . $db->quoteName('total'))
            ->select('COALESCE(SUM(' . $db->quoteName('is_external') . '), 0) AS ' . $db->quoteName('external'))
            ->from($db->quoteName('#__mediaaudit_refs'));

        $row = $db->setQuery($query)->loadObject();

        $summary['references']          = (int) $row->total;
        $summary['external_references'] = (int) $row->external;

        return $summary;
    }

    /**
     * External URLs that were checked and did not answer with a 2xx status, one row for every URL.
     *
     * @param   string[]  $hosts  Only URLs of these hosts. Empty for all.
     *
     * @return  list<array{url: string, host: string, status: int, error: string, checked_at: string, refs: int, live_refs: int, local_path: ?string}>
     */
    public function brokenExternal(array $hosts = []): array
    {
        $db    = $this->db;
        $live  = '(' . $db->quoteName('r.source_state') . ' = 1 OR ' . $db->quoteName('r.source_state') . ' IS NULL)';
        $query = $db->getQuery(true)
            ->select($db->quoteName(['u.url', 'u.host', 'u.http_status', 'u.error', 'u.checked_at', 'u.local_path']))
            ->select('COUNT(' . $db->quoteName('r.id') . ') AS ' . $db->quoteName('refs'))
            ->select('COALESCE(SUM(' . $live . '), 0) AS ' . $db->quoteName('live_refs'))
            ->from($db->quoteName('#__mediaaudit_urls', 'u'))
            ->join(
                'INNER',
                $db->quoteName('#__mediaaudit_refs', 'r'),
                $db->quoteName('r.path_hash') . ' = ' . $db->quoteName('u.url_hash') . ' AND ' . $db->quoteName('r.is_external') . ' = 1'
            )
            ->where($db->quoteName('u.checked_at') . ' IS NOT NULL')
            ->where(
                '(' . $db->quoteName('u.http_status') . ' < 200 OR ' . $db->quoteName('u.http_status') . ' >= 300 OR '
                . $db->quoteName('u.error') . ' <> ' . $db->quote('') . ')'
            )
            ->group($db->quoteName(['u.id', 'u.url', 'u.host', 'u.http_status', 'u.error', 'u.checked_at', 'u.local_path']))
            ->order($db->quoteName('u.host') . ' ASC, ' . $db->quoteName('u.id') . ' ASC');

        if ($hosts) {
            $query->where($db->quoteName('u.host') . ' IN (' . implode(',', array_map([$db, 'quote'], $hosts)) . ')');
        }

        $broken = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $broken[] = [
                'url'        => $row->url,
                'host'       => $row->host,
                'status'     => (int) $row->http_status,
                'error'      => $row->error,
                'checked_at' => $row->checked_at,
                'refs'       => (int) $row->refs,
                'live_refs'  => (int) $row->live_refs,
                'local_path' => $row->local_path,
            ];
        }

        return $broken;
    }

    /**
     * The external URLs that the content refers to, by host.
     *
     * @return  list<array{host: string, urls: int, references: int, checked: int, broken: int, copies: int}>
     */
    public function externalHosts(): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['r.path', 'r.path_hash', 'u.http_status', 'u.error', 'u.checked_at', 'u.local_path']))
            ->select('COUNT(*) AS ' . $db->quoteName('refs'))
            ->from($db->quoteName('#__mediaaudit_refs', 'r'))
            ->join('LEFT', $db->quoteName('#__mediaaudit_urls', 'u'), $db->quoteName('u.url_hash') . ' = ' . $db->quoteName('r.path_hash'))
            ->where($db->quoteName('r.is_external') . ' = 1')
            ->group($db->quoteName(['r.path', 'r.path_hash', 'u.http_status', 'u.error', 'u.checked_at', 'u.local_path']));

        $hosts = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $host = strtolower((string) parse_url($row->path, PHP_URL_HOST));

            $hosts[$host] ??= ['host' => $host, 'urls' => 0, 'references' => 0, 'checked' => 0, 'broken' => 0, 'copies' => 0];

            $hosts[$host]['urls']++;
            $hosts[$host]['references'] += (int) $row->refs;

            if ($row->checked_at !== null) {
                $hosts[$host]['checked']++;
                $hosts[$host]['broken'] += $row->http_status >= 200 && $row->http_status < 300 && $row->error === '' ? 0 : 1;
            }

            if ($row->local_path !== null) {
                $hosts[$host]['copies']++;
            }
        }

        usort($hosts, static fn (array $a, array $b): int => $b['urls'] <=> $a['urls'] ?: strcmp($a['host'], $b['host']));

        return $hosts;
    }

    /**
     * Files and references whose paths differ by case only: most likely one is meant to be the other.
     *
     * @return  array{by_file: array<string, string>, by_reference: array<string, string>}
     */
    private function caseSuspects(string $prefix): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('f.path', 'file_path') . ', ' . $db->quoteName('r.path', 'reference_path'))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join(
                'INNER',
                $db->quoteName('#__mediaaudit_refs', 'r'),
                $db->quoteName('r.path_ci_hash') . ' = ' . $db->quoteName('f.path_ci_hash')
                . ' AND ' . $db->quoteName('r.path_hash') . ' <> ' . $db->quoteName('f.path_hash')
            )
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->where($db->quoteName('r.is_external') . ' = 0')
            ->where($db->quoteName('r.exists_local') . ' = 0')
            ->where($this->startsWith('f.path', $prefix))
            ->order($db->quoteName('f.path') . ' ASC, ' . $db->quoteName('r.path') . ' ASC');

        $suspects = ['by_file' => [], 'by_reference' => []];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $suspects['by_file'][$row->file_path]           ??= $row->reference_path;
            $suspects['by_reference'][$row->reference_path] ??= $row->file_path;
        }

        return $suspects;
    }

    private function startsWith(string $column, string $prefix): string
    {
        if ($prefix === '') {
            return '1 = 1';
        }

        return $this->db->quoteName($column) . ' LIKE ' . $this->db->quote($this->db->escape($prefix, true) . '%', false);
    }
}
