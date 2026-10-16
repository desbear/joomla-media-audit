<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Model;

use Gss\Component\Mediaaudit\Administrator\Converter\Encoder;
use Gss\Component\Mediaaudit\Administrator\Converter\ImageConverter;
use Gss\Component\Mediaaudit\Administrator\Converter\TargetNamer;
use Gss\Component\Mediaaudit\Administrator\Helper\SourceHelper;
use Gss\Component\Mediaaudit\Administrator\Report\ReportRepository;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

\defined('_JEXEC') or die;

/**
 * Every image of the index, with its type and with what became of converting it.
 */
class ImagesModel extends ListModel
{
    public const TYPES = ['jpg', 'png', 'gif', 'webp', 'avif', 'svg'];

    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] ??= ['f.path', 'f.size', 'f.mtime', 'refs', 'search', 'type', 'used', 'state'];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'f.size', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        foreach (['search', 'type', 'used', 'state'] as $filter) {
            $id .= ':' . $this->getState('filter.' . $filter);
        }

        return parent::getStoreId($id);
    }

    /**
     * The format that the options ask for. Empty when converting is off.
     */
    public function getFormat(): string
    {
        $format = (string) ComponentHelper::getParams('com_mediaaudit')->get('convert_format', '');

        return \in_array($format, TargetNamer::FORMATS, true) ? $format : '';
    }

    /**
     * @return  array<string, string[]>  Format => what can write it on this server
     */
    public function getCapabilities(): array
    {
        return (new Encoder((string) ComponentHelper::getParams('com_mediaaudit')->get('convert_program', '')))->capabilities();
    }

    /** @var array{types: string[], fixed: array<string, string[]>}|null */
    private ?array $convertible = null;

    /**
     * The condition for an image that content refers to in a way that is rewritten.
     */
    private function rewritten(string $alias = 'f'): string
    {
        if ($this->convertible === null) {
            $application = Factory::getApplication();

            $this->convertible = ScanRunner::create(
                $this->getDatabase(),
                ComponentHelper::getParams('com_mediaaudit'),
                JPATH_ROOT,
                $application->getDispatcher(),
                (string) $application->get('tmp_path', sys_get_temp_dir())
            )->getRewriter()->getConvertible();
        }

        return ReportRepository::isRewritten($this->getDatabase(), $alias, $this->convertible);
    }

    /**
     * The type of a file as SQL: the end of its name, "jpeg" as "jpg".
     */
    private function type(string $alias = 'f'): string
    {
        $db   = $this->getDatabase();
        $type = 'LOWER(SUBSTRING_INDEX(' . $db->quoteName($alias . '.path') . ', ' . $db->quote('.') . ', -1))';

        return 'CASE WHEN ' . $type . ' = ' . $db->quote('jpeg') . ' THEN ' . $db->quote('jpg') . ' ELSE ' . $type . ' END';
    }

    private function used(string $alias = 'f'): string
    {
        $db = $this->getDatabase();

        return 'EXISTS (SELECT 1 FROM ' . $db->quoteName('#__mediaaudit_refs', 'u')
            . ' WHERE ' . $db->quoteName('u.path_hash') . ' = ' . $db->quoteName($alias . '.path_hash')
            . ' AND ' . $db->quoteName('u.is_external') . ' = 0)';
    }

    /**
     * The conversion of an image that counts: the one to the format of the options, or the last.
     */
    private function conversionOf(string $alias = 'f'): string
    {
        $db     = $this->getDatabase();
        $format = $this->getFormat();

        return '(SELECT MAX(' . $db->quoteName('c2.id') . ') FROM ' . $db->quoteName('#__mediaaudit_conversions', 'c2')
            . ' WHERE ' . $db->quoteName('c2.source_hash') . ' = ' . $db->quoteName($alias . '.path_hash')
            . ($format === '' ? '' : ' AND ' . $db->quoteName('c2.format') . ' = ' . $db->quote($format)) . ')';
    }

    /**
     * The condition for an image that a batch would take: of a type that can be converted, not made
     * by a component, and not tried as it is now.
     */
    private function waiting(): string
    {
        $db = $this->getDatabase();

        return $this->type() . ' IN (' . $db->quote('jpg') . ', ' . $db->quote('png') . ')'
            . ' AND NOT ' . ReportRepository::isDerived($db, 'f')
            . ' AND (' . $db->quoteName('c.id') . ' IS NULL OR (' . $db->quoteName('f.sha1') . ' IS NOT NULL AND '
            . $db->quoteName('c.source_sha1') . ' <> ' . $db->quoteName('f.sha1') . '))';
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['f.id', 'f.path', 'f.path_hash', 'f.size', 'f.sha1', 'f.mtime']))
            ->select($this->type() . ' AS ' . $db->quoteName('type'))
            ->select(
                '(SELECT COUNT(*) FROM ' . $db->quoteName('#__mediaaudit_refs', 'r') . ' WHERE ' . $db->quoteName('r.path_hash') . ' = ' . $db->quoteName('f.path_hash')
                . ' AND ' . $db->quoteName('r.is_external') . ' = 0) AS ' . $db->quoteName('refs')
            )
            ->select(ReportRepository::isDerived($db, 'f') . ' AS ' . $db->quoteName('derived'))
            ->select($this->rewritten() . ' AS ' . $db->quoteName('rewritten'))
            ->select($db->quoteName(
                ['c.status', 'c.target_path', 'c.target_size', 'c.source_size', 'c.source_sha1', 'c.format', 'c.error', 'c.converted_at'],
                ['conversion', 'target_path', 'target_size', 'converted_size', 'converted_sha1', 'format', 'error', 'converted_at']
            ))
            ->select($db->quoteName('m.source_path', 'made_of'))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join('LEFT', $db->quoteName('#__mediaaudit_conversions', 'c'), $db->quoteName('c.id') . ' = ' . $this->conversionOf())
            ->join(
                'LEFT',
                $db->quoteName('#__mediaaudit_conversions', 'm'),
                $db->quoteName('m.target_hash') . ' = ' . $db->quoteName('f.path_hash') . ' AND ' . $db->quoteName('m.status') . ' = ' . $db->quote(ImageConverter::CONVERTED)
            )
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'));

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $query->where($db->quoteName('f.path') . ' LIKE ' . $db->quote('%' . $db->escape($search, true) . '%', false));
        }

        $type = (string) $this->getState('filter.type');

        if ($type !== '') {
            $query->where($this->type() . ' = ' . $db->quote($type));
        }

        $used = (string) $this->getState('filter.used');

        if ($used !== '') {
            $query->where(($used === '1' ? '' : 'NOT ') . $this->used());
        }

        $state = (string) $this->getState('filter.state');

        if ($state === 'waiting') {
            $query->where($this->waiting())->where($this->rewritten());
        } elseif (\in_array($state, [ImageConverter::CONVERTED, ImageConverter::NOT_SMALLER, ImageConverter::FAILED], true)) {
            $query->where($db->quoteName('c.status') . ' = ' . $db->quote($state));
        }

        $ordering  = $this->getState('list.ordering', 'f.size');
        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $query->order($db->quoteName(\in_array($ordering, ['f.path', 'f.size', 'f.mtime', 'refs'], true) ? $ordering : 'f.size') . ' ' . $direction)
            ->order($db->quoteName('f.id') . ' ASC');

        return $query;
    }

    public function getItems()
    {
        $items = parent::getItems();

        if (!$items) {
            return $items;
        }

        $references = SourceHelper::referencesTo($this->getDatabase(), array_column($items, 'path_hash'));

        foreach ($items as $item) {
            $item->references = $references[$item->path_hash] ?? [];
            $item->derived    = (bool) $item->derived;
            $item->rewritten  = (bool) $item->rewritten;

            // What was converted was another image when the file has changed since
            $item->stale = $item->conversion !== null && $item->sha1 !== null && $item->converted_sha1 !== $item->sha1;

            $item->convertible = \in_array($item->type, ['jpg', 'png'], true)
                && TargetNamer::isConvertible($item->path)
                && !$item->derived
                && ($item->conversion === null || $item->stale || $item->conversion === ImageConverter::FAILED);
        }

        return $items;
    }

    /**
     * The files by type: how many, how large, and how many of them content refers to.
     *
     * @return  list<array{type: string, files: int, bytes: int, used: int, used_bytes: int}>  The most files first
     */
    public function getTypes(): array
    {
        $db    = $this->getDatabase();
        $inner = $db->getQuery(true)
            ->select($this->type() . ' AS ' . $db->quoteName('type'))
            ->select($db->quoteName('f.size'))
            ->select($this->used() . ' AS ' . $db->quoteName('used'))
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'));

        $query = $db->getQuery(true)
            ->select($db->quoteName('t.type'))
            ->select('COUNT(*) AS ' . $db->quoteName('files'))
            ->select('COALESCE(SUM(' . $db->quoteName('t.size') . '), 0) AS ' . $db->quoteName('bytes'))
            ->select('COALESCE(SUM(' . $db->quoteName('t.used') . '), 0) AS ' . $db->quoteName('used'))
            ->select('COALESCE(SUM(CASE WHEN ' . $db->quoteName('t.used') . ' THEN ' . $db->quoteName('t.size') . ' ELSE 0 END), 0) AS ' . $db->quoteName('used_bytes'))
            ->from('(' . $inner . ') AS ' . $db->quoteName('t'))
            ->group($db->quoteName('t.type'))
            ->order($db->quoteName('files') . ' DESC, ' . $db->quoteName('t.type') . ' ASC');

        $types = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $types[] = [
                'type'       => (string) $row->type,
                'files'      => (int) $row->files,
                'bytes'      => (int) $row->bytes,
                'used'       => (int) $row->used,
                'used_bytes' => (int) $row->used_bytes,
            ];
        }

        return $types;
    }

    /**
     * @return  array{waiting: int, converted: int, not_smaller: int, failed: int, bytes_before: int, bytes_after: int}
     *          Waiting are the images that content refers to and that the next batches take.
     */
    public function getTotals(): array
    {
        $db     = $this->getDatabase();
        $totals = ['waiting' => 0, 'converted' => 0, 'not_smaller' => 0, 'failed' => 0, 'bytes_before' => 0, 'bytes_after' => 0];
        $format = $this->getFormat();

        $query = $db->getQuery(true)
            ->select($db->quoteName('status'))
            ->select('COUNT(*) AS ' . $db->quoteName('images'))
            ->select('COALESCE(SUM(' . $db->quoteName('source_size') . '), 0) AS ' . $db->quoteName('before'))
            ->select('COALESCE(SUM(' . $db->quoteName('target_size') . '), 0) AS ' . $db->quoteName('after'))
            ->from($db->quoteName('#__mediaaudit_conversions'))
            ->group($db->quoteName('status'));

        if ($format !== '') {
            $query->where($db->quoteName('format') . ' = ' . $db->quote($format));
        }

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            if (isset($totals[$row->status])) {
                $totals[$row->status] = (int) $row->images;
            }

            if ($row->status === ImageConverter::CONVERTED) {
                $totals['bytes_before'] = (int) $row->before;
                $totals['bytes_after']  = (int) $row->after;
            }
        }

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__mediaaudit_files', 'f'))
            ->join('LEFT', $db->quoteName('#__mediaaudit_conversions', 'c'), $db->quoteName('c.id') . ' = ' . $this->conversionOf())
            ->where($db->quoteName('f.status') . ' = ' . $db->quote('active'))
            ->where($this->rewritten())
            ->where($this->waiting());

        $totals['waiting'] = (int) $db->setQuery($query)->loadResult();

        return $totals;
    }
}
