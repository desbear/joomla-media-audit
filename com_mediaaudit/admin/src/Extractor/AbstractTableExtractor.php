<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Extractor;

use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * For sources that are the rows of one table with a numeric id.
 */
abstract class AbstractTableExtractor implements ReferenceExtractorInterface, RewritableSourceInterface, ContextAwareInterface
{
    public function __construct(protected DatabaseInterface $db, protected ReferenceBuilder $builder)
    {
    }

    /**
     * Like "#__content".
     */
    abstract protected function getTable(): string;

    /**
     * The columns to read, without the id and without the state.
     *
     * @return  string[]
     */
    abstract protected function getColumns(): array;

    /**
     * The column that holds 1, 0, 2 or -2. Null for sources without a state.
     */
    abstract protected function getStateColumn(): ?string;

    /**
     * @param   object    $row    The columns, plus "id".
     * @param   int|null  $state  State of the source.
     *
     * @return  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]
     */
    abstract protected function extractRow(object $row, ?int $state): array;

    public function getContexts(): array
    {
        return [];
    }

    public function getSourceIds(string $context, object $row): array
    {
        return empty($row->id) ? [] : [(int) $row->id];
    }

    public function isWrittenLater(): bool
    {
        return false;
    }

    /**
     * The state of published, unpublished, archived or trashed that the value of the column stands
     * for. For tables that count in another way.
     */
    protected function toState($value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public function getRewritableTable(): string
    {
        return $this->getTable();
    }

    public function getRewritableHtmlColumns(): array
    {
        return [];
    }

    public function getRewritableJsonColumns(): array
    {
        return [];
    }

    public function extractAll(callable $emit, int $batchSize = 200): void
    {
        $last = 0;

        do {
            $query = $this->getQuery()
                ->where($this->db->quoteName('id') . ' > ' . $last)
                ->order($this->db->quoteName('id') . ' ASC')
                ->setLimit(max(1, $batchSize));

            $rows = $this->db->setQuery($query)->loadObjectList();

            foreach ($rows as $row) {
                $last = (int) $row->id;

                foreach ($this->extract($row) as $reference) {
                    $emit($reference);
                }
            }
        } while (\count($rows) === max(1, $batchSize));
    }

    public function extractOne(int $sourceId): array
    {
        $query = $this->getQuery()->where($this->db->quoteName('id') . ' = ' . $sourceId);
        $row   = $this->db->setQuery($query)->loadObject();

        return $row ? $this->extract($row) : [];
    }

    protected function getQuery()
    {
        $columns = array_merge(['id'], $this->getColumns());

        if ($this->getStateColumn() !== null) {
            $columns[] = $this->getStateColumn();
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(array_unique($columns)))
            ->from($this->db->quoteName($this->getTable()));

        foreach ($this->getConditions() as $condition) {
            $query->where($condition);
        }

        return $query;
    }

    /**
     * Conditions that the rows have to meet, for tables that hold more than the sources.
     *
     * @return  string[]
     */
    protected function getConditions(): array
    {
        return [];
    }

    /**
     * @return  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]
     */
    private function extract(object $row): array
    {
        $column = $this->getStateColumn();
        $state  = $column === null ? null : $this->toState($row->$column);

        return ReferenceBuilder::unique($this->extractRow($row, $state));
    }
}
