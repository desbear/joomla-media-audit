<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Localizer;

use Gss\Component\Mediaaudit\Administrator\Converter\UrlSwapper;
use Gss\Component\Mediaaudit\Administrator\Extractor\ExtractorRegistry;
use Gss\Component\Mediaaudit\Administrator\Extractor\ReferenceExtractorInterface;
use Gss\Component\Mediaaudit\Administrator\Extractor\RewritableSourceInterface;
use Gss\Component\Mediaaudit\Administrator\Extractor\RewritableValuesInterface;
use Gss\Component\Mediaaudit\Administrator\Extractor\RewriteListenerInterface;
use Gss\Component\Mediaaudit\Administrator\Scanner\ReferenceIndexer;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Makes the content load the local copies of the external images, and the converted files of the
 * images of the site.
 *
 * The text that a column had is kept in #__mediaaudit_rewrites before it is changed, and revert()
 * puts it back. The rows are written straight to the tables: no plugin runs, and nothing but the
 * URLs of the images changes.
 */
final class SourceRewriter
{
    /** A copy took the place of an address */
    public const KIND_LOCALIZE = 'localize';

    /** A note took the place of an image that is lost */
    public const KIND_REMOVE_LOST = 'remove_lost';

    /** A converted file took the place of an image of the site */
    public const KIND_CONVERT = 'convert';

    /**
     * Kinds of sources that go on loading the image after it was converted. What a template
     * loads is its logo and the like: few files, that everything else of the site is built around,
     * and that other things than browsers read.
     */
    public const NOT_CONVERTED = ['template_style'];

    public function __construct(
        private DatabaseInterface $db,
        private ExtractorRegistry $extractors,
        private ReferenceIndexer $indexer,
        private ImageLocalizer $localizer,
        private PathNormalizer $normalizer,
        private ContentRewriter $rewriter
    ) {
    }

    /**
     * @param   string[]       $hosts     Only URLs of these hosts. Empty for all.
     * @param   bool           $dryRun    Only count.
     * @param   int            $userId    Who asked for it.
     * @param   callable|null  $progress  function (string $sourceType, int $sourceId, int $replaced): void
     *
     * @return  array{sources: int, rewritten: int, replaced: int, unsupported: int}
     */
    public function rewrite(array $hosts = [], bool $dryRun = false, int $userId = 0, ?callable $progress = null): array
    {
        $stats  = ['sources' => 0, 'rewritten' => 0, 'replaced' => 0, 'unsupported' => 0];
        $copies = $this->localizer->getCopies();

        if (!$copies) {
            return $stats;
        }

        $lookup = $this->lookupCopies($hosts, $copies);

        foreach ($this->sources(array_keys($copies)) as $source) {
            $stats['sources']++;

            $extractor = $this->extractors->get($source->source_type);

            if (!$this->canRewrite($extractor, self::KIND_LOCALIZE)) {
                $stats['unsupported']++;

                continue;
            }

            $replaced = $this->rewriteSource($extractor, $source->source_type, (int) $source->source_id, $lookup, $dryRun, $userId);

            if ($replaced > 0) {
                $stats['rewritten']++;
                $stats['replaced'] += $replaced;

                if (!$dryRun) {
                    $this->indexer->indexOne($source->source_type, (int) $source->source_id, $extractor->extractOne((int) $source->source_id), false);
                }
            }

            if ($progress !== null) {
                $progress($source->source_type, (int) $source->source_id, $replaced);
            }
        }

        return $stats;
    }

    /**
     * What gives the path of the copy for the URL of an external image, or null for every other URL.
     *
     * @param   string[]                    $hosts   Only URLs of these hosts. Empty for all.
     * @param   array<string, string>|null  $copies  Hash of the URL => path. Null for the copies that are on disk.
     */
    public function lookupCopies(array $hosts = [], ?array $copies = null): callable
    {
        $copies ??= $this->localizer->getCopies();
        $hosts    = array_filter(array_map('strtolower', array_map('trim', $hosts)));

        return function (string $url) use ($copies, $hosts): ?string {
            $target = $this->normalizer->normalize($url);

            if ($target === null || !$target->isExternal || !isset($copies[$target->hash()])) {
                return null;
            }

            if ($hosts && !\in_array((string) parse_url($target->path, PHP_URL_HOST), $hosts, true)) {
                return null;
            }

            return $copies[$target->hash()];
        };
    }

    /**
     * Rewrites one source as it is stored, and brings its references up to date.
     *
     * For the components that store another text than the one that the event before the save was
     * given: what was changed there is lost, and is done again here, after the save.
     *
     * @return  int  How many URLs were replaced.
     */
    public function rewriteOne(string $sourceType, int $sourceId, callable $lookup, string $kind, int $userId = 0): int
    {
        $extractor = $this->extractors->get($sourceType);

        if (!$this->canRewrite($extractor, $kind)) {
            return 0;
        }

        $replaced = $this->rewriteSource($extractor, $sourceType, $sourceId, $lookup, false, $userId, $kind);

        if ($replaced > 0) {
            $this->indexer->indexOne($sourceType, $sourceId, $extractor->extractOne($sourceId));
        }

        return $replaced;
    }

    /**
     * Whether the sources of the extractor are rewritten for changes of the kind.
     */
    public function canRewrite(?ReferenceExtractorInterface $extractor, string $kind): bool
    {
        if ($extractor === null || ($kind === self::KIND_CONVERT && \in_array($extractor->getSourceType(), self::NOT_CONVERTED, true))) {
            return false;
        }

        if ($extractor instanceof RewritableValuesInterface) {
            return true;
        }

        return $extractor instanceof RewritableSourceInterface
            && ($extractor->getRewritableHtmlColumns() || $extractor->getRewritableJsonColumns());
    }

    /**
     * What a reference has to be for its image to be worth converting: the kinds of sources that
     * are rewritten for it, and the fields of them that are not.
     *
     * @return  array{types: string[], fixed: array<string, string[]>}  The fixed fields by kind of source.
     */
    public function getConvertible(): array
    {
        $convertible = ['types' => [], 'fixed' => []];

        foreach ($this->extractors->all() as $type => $extractor) {
            if (!$this->canRewrite($extractor, self::KIND_CONVERT)) {
                continue;
            }

            $convertible['types'][] = $type;

            if ($extractor instanceof RewritableValuesInterface && $extractor->getFixedFields()) {
                $convertible['fixed'][$type] = $extractor->getFixedFields();
            }
        }

        return $convertible;
    }

    /**
     * The text that the columns of a source hold now.
     *
     * @param   string[]  $columns
     *
     * @return  array<string, string|null>
     */
    public function stored(RewritableSourceInterface $extractor, int $sourceId, array $columns): array
    {
        $stored = [];

        foreach (array_unique($columns) as $column) {
            $stored[$column] = $this->readColumn($extractor->getRewritableTable(), $column, $sourceId);
        }

        return $stored;
    }

    /**
     * Makes the content load the converted files in the place of the images they were made of.
     *
     * A URL stays the way it was written, but for the name of the file. Content that cannot be
     * rewritten goes on loading the image, which stays where it is.
     *
     * @param   array<string, array{source: string, target: string}>  $targets  By the hash of the path of the image.
     * @param   callable|null  $progress  function (string $sourceType, int $sourceId, int $replaced): void
     *
     * @return  array{sources: int, rewritten: int, replaced: int, unsupported: int}
     */
    public function rewriteConverted(array $targets, bool $dryRun = false, int $userId = 0, ?callable $progress = null): array
    {
        $stats = ['sources' => 0, 'rewritten' => 0, 'replaced' => 0, 'unsupported' => 0];

        if (!$targets) {
            return $stats;
        }

        $lookup = $this->lookupConverted($targets);

        foreach ($this->sources(array_keys($targets), false) as $source) {
            $stats['sources']++;

            $extractor = $this->extractors->get($source->source_type);

            if (!$this->canRewrite($extractor, self::KIND_CONVERT)) {
                $stats['unsupported']++;

                continue;
            }

            $id       = (int) $source->source_id;
            $replaced = $this->rewriteSource($extractor, $source->source_type, $id, $lookup, $dryRun, $userId, self::KIND_CONVERT);

            if ($replaced > 0) {
                $stats['rewritten']++;
                $stats['replaced'] += $replaced;

                if (!$dryRun) {
                    $this->indexer->indexOne($source->source_type, $id, $extractor->extractOne($id));
                }
            }

            if ($progress !== null) {
                $progress($source->source_type, $id, $replaced);
            }
        }

        return $stats;
    }

    /**
     * What gives the URL of the converted file for the URL of an image, or null for every other URL.
     *
     * @param   array<string, array{source: string, target: string}>  $targets  By the hash of the path of the image.
     */
    public function lookupConverted(array $targets): callable
    {
        return function (string $url) use ($targets): ?string {
            $target = $this->normalizer->normalize($url);

            if ($target === null || $target->isExternal || !isset($targets[$target->hash()])) {
                return null;
            }

            return UrlSwapper::swap($url, $targets[$target->hash()]['source'], $targets[$target->hash()]['target']);
        };
    }

    /**
     * Takes the images that are lost out of the content, and leaves a note in their place.
     *
     * Lost are the external images that were checked, did not answer with an image, and have no
     * copy. Only the hosts that are named: what is lost is decided host by host, by a person.
     *
     * @param   string[]  $hosts   Hosts whose lost images are taken out. Not empty.
     * @param   string    $text    The note.
     *
     * @return  array{lost: int, sources: int, rewritten: int, replaced: int, unsupported: int}
     */
    public function removeLost(array $hosts, string $text, bool $dryRun = false, int $userId = 0, ?callable $progress = null): array
    {
        $db    = $this->db;
        $stats = ['lost' => 0, 'sources' => 0, 'rewritten' => 0, 'replaced' => 0, 'unsupported' => 0];
        $hosts = array_values(array_filter(array_map('strtolower', array_map('trim', $hosts))));

        if (!$hosts || trim($text) === '') {
            throw new \InvalidArgumentException('Hosts and a note are needed.');
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('url_hash'))
            ->from($db->quoteName('#__mediaaudit_urls'))
            ->where($db->quoteName('host') . ' IN (' . implode(',', array_map([$db, 'quote'], $hosts)) . ')')
            ->where($db->quoteName('checked_at') . ' IS NOT NULL')
            ->where($db->quoteName('local_path') . ' IS NULL')
            ->where(
                '(' . $db->quoteName('http_status') . ' < 200 OR ' . $db->quoteName('http_status') . ' >= 300 OR '
                . $db->quoteName('error') . ' <> ' . $db->quote('') . ')'
            );

        $lost          = array_fill_keys($db->setQuery($query)->loadColumn(), true);
        $stats['lost'] = \count($lost);

        if (!$lost) {
            return $stats;
        }

        $isLost = function (string $url) use ($lost): bool {
            $target = $this->normalizer->normalize($url);

            return $target !== null && $target->isExternal && isset($lost[$target->hash()]);
        };

        foreach ($this->sources(array_keys($lost)) as $source) {
            $stats['sources']++;

            $extractor = $this->extractors->get($source->source_type);

            if (!$extractor instanceof RewritableSourceInterface) {
                $stats['unsupported']++;

                continue;
            }

            $id       = (int) $source->source_id;
            $replaced = 0;

            foreach ($extractor->getRewritableHtmlColumns() as $column) {
                $original = $this->readColumn($extractor->getRewritableTable(), $column, $id);

                if ($original === null || $original === '') {
                    continue;
                }

                [$rewritten, $count] = $this->rewriter->removeImages($original, $isLost, $text);

                if ($count === 0 || $rewritten === $original) {
                    continue;
                }

                $replaced += $count;

                if ($dryRun) {
                    continue;
                }

                $db->transactionStart();

                try {
                    $this->remember($source->source_type, $id, $column, $original, $rewritten, $count, $userId, self::KIND_REMOVE_LOST);
                    $this->writeColumn($extractor->getRewritableTable(), $column, $id, $rewritten);
                    $db->transactionCommit();
                } catch (\Throwable $e) {
                    $db->transactionRollback();

                    throw $e;
                }
            }

            if ($replaced > 0) {
                $stats['rewritten']++;
                $stats['replaced'] += $replaced;

                if (!$dryRun) {
                    $this->indexer->indexOne($source->source_type, $id, $extractor->extractOne($id), false);
                }
            }

            if ($progress !== null) {
                $progress($source->source_type, $id, $replaced);
            }
        }

        return $stats;
    }

    /**
     * Keeps the text that a column had before it was rewritten while it was saved.
     */
    public function remember(
        string $sourceType,
        int $sourceId,
        string $column,
        string $original,
        string $rewritten,
        int $replaced,
        int $userId,
        string $kind = self::KIND_LOCALIZE
    ): void {
        $backup = (object) [
            'source_type'    => $sourceType,
            'source_id'      => $sourceId,
            'field'          => $column,
            'kind'           => $kind,
            'original'       => $original,
            'rewritten_hash' => sha1($rewritten),
            'replaced'       => $replaced,
            'rewritten_by'   => $userId,
            'rewritten_at'   => gmdate('Y-m-d H:i:s'),
        ];

        $this->db->insertObject('#__mediaaudit_rewrites', $backup);
    }

    /**
     * Puts back the text that the columns had. A column that was edited after it was rewritten is
     * left alone.
     *
     * @param   string[]  $kinds  Only the changes of these kinds. A column that was changed again
     *                            after them, by a change of another kind, counts as edited. Empty
     *                            for the changes of every kind.
     *
     * @param   array{0: string, 1: int}|null  $source  Only the changes of this source: its kind and its id.
     *
     * @return  array{reverted: int, edited: int, gone: int}
     */
    public function revert(bool $dryRun = false, array $kinds = [], ?array $source = null): array
    {
        $db    = $this->db;
        $stats = ['reverted' => 0, 'edited' => 0, 'gone' => 0];
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__mediaaudit_rewrites'))
            ->where($db->quoteName('reverted_at') . ' IS NULL')
            // The last change of a column first, so that the first text is the one that stays
            ->order($db->quoteName('id') . ' DESC');

        if ($kinds) {
            $query->where($db->quoteName('kind') . ' IN (' . implode(',', array_map([$db, 'quote'], $kinds)) . ')');
        }

        if ($source !== null) {
            $query->where($db->quoteName('source_type') . ' = ' . $db->quote((string) $source[0]))
                ->where($db->quoteName('source_id') . ' = ' . (int) $source[1]);
        }

        $touched = [];

        foreach ($db->setQuery($query)->loadObjectList() as $backup) {
            $extractor = $this->extractors->get($backup->source_type);

            if ($extractor instanceof RewritableValuesInterface) {
                // A field can have more values than one
                $current = null;

                foreach ($extractor->getRewritableValues((int) $backup->source_id) as $value) {
                    if ($value['key'] === $backup->field && ($current === null || sha1($value['value']) === $backup->rewritten_hash)) {
                        $current = $value['value'];
                    }
                }
            } elseif ($extractor instanceof RewritableSourceInterface) {
                $current = $this->readColumn($extractor->getRewritableTable(), $backup->field, (int) $backup->source_id);
            } else {
                $current = null;
            }

            if ($current === null) {
                $stats['gone']++;

                continue;
            }

            if (sha1($current) !== $backup->rewritten_hash) {
                $stats['edited']++;

                continue;
            }

            $stats['reverted']++;

            if ($dryRun) {
                continue;
            }

            if ($extractor instanceof RewritableValuesInterface) {
                $extractor->setRewritableValue((int) $backup->source_id, $backup->field, $current, $backup->original);
            } else {
                $this->writeColumn($extractor->getRewritableTable(), $backup->field, (int) $backup->source_id, $backup->original);
            }

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__mediaaudit_rewrites'))
                ->set($db->quoteName('reverted_at') . ' = ' . $db->quote(gmdate('Y-m-d H:i:s')))
                ->where($db->quoteName('id') . ' = ' . (int) $backup->id);
            $db->setQuery($query)->execute();

            $touched[$backup->source_type . ':' . $backup->source_id] = [$backup->source_type, (int) $backup->source_id];
        }

        foreach ($touched as [$type, $id]) {
            $this->indexer->indexOne($type, $id, $this->extractors->get($type)->extractOne($id), false);
        }

        return $stats;
    }

    /**
     * @param   string[]  $hashes    Hashes of what the sources refer to.
     * @param   bool      $external  Whether these are URLs of other sites, or paths of this one.
     *
     * @return  object[]  source_type, source_id
     */
    private function sources(array $hashes, bool $external = true): array
    {
        $db      = $this->db;
        $sources = [];

        foreach (array_chunk($hashes, 500) as $chunk) {
            $query = $db->getQuery(true)
                ->select('DISTINCT ' . $db->quoteName('source_type') . ', ' . $db->quoteName('source_id'))
                ->from($db->quoteName('#__mediaaudit_refs'))
                ->where($db->quoteName('is_external') . ' = ' . ($external ? 1 : 0))
                ->where($db->quoteName('path_hash') . ' IN (' . implode(',', array_map([$db, 'quote'], $chunk)) . ')');

            foreach ($db->setQuery($query)->loadObjectList() as $row) {
                $sources[$row->source_type . ':' . $row->source_id] = $row;
            }
        }

        ksort($sources, SORT_NATURAL);

        return array_values($sources);
    }

    private function rewriteSource(
        ReferenceExtractorInterface $extractor,
        string $sourceType,
        int $sourceId,
        callable $lookup,
        bool $dryRun,
        int $userId,
        string $kind = self::KIND_LOCALIZE
    ): int {
        if ($extractor instanceof RewritableValuesInterface) {
            return $this->rewriteValues($extractor, $sourceType, $sourceId, $lookup, $dryRun, $userId, $kind);
        }

        if (!$extractor instanceof RewritableSourceInterface) {
            return 0;
        }

        $table   = $extractor->getRewritableTable();
        $columns = array_merge(
            array_fill_keys($extractor->getRewritableHtmlColumns(), 'html'),
            array_fill_keys($extractor->getRewritableJsonColumns(), 'json')
        );
        $total   = 0;
        $written = false;

        // Not $kind: that is the kind of the change
        foreach ($columns as $column => $holds) {
            $original = $this->readColumn($table, $column, $sourceId);

            if ($original === null || $original === '') {
                continue;
            }

            [$rewritten, $replaced] = $holds === 'html'
                ? $this->rewriter->rewrite($original, $lookup)
                : $this->rewriter->rewriteJson($original, $lookup);

            if ($replaced === 0 || $rewritten === $original) {
                continue;
            }

            $total += $replaced;

            if ($dryRun) {
                continue;
            }

            $written = true;

            $this->db->transactionStart();

            try {
                $backup = (object) [
                    'source_type'    => $sourceType,
                    'source_id'      => $sourceId,
                    'field'          => $column,
                    'kind'           => $kind,
                    'original'       => $original,
                    'rewritten_hash' => sha1($rewritten),
                    'replaced'       => $replaced,
                    'rewritten_by'   => $userId,
                    'rewritten_at'   => gmdate('Y-m-d H:i:s'),
                ];

                $this->db->insertObject('#__mediaaudit_rewrites', $backup);
                $this->writeColumn($table, $column, $sourceId, $rewritten);
                $this->db->transactionCommit();
            } catch (\Throwable $e) {
                $this->db->transactionRollback();

                throw $e;
            }
        }

        if ($written) {
            $this->tell($table, $sourceId, $lookup);
        }

        return $total;
    }

    /**
     * Rewrites the values of the fields of a source.
     */
    private function rewriteValues(
        RewritableValuesInterface $extractor,
        string $sourceType,
        int $sourceId,
        callable $lookup,
        bool $dryRun,
        int $userId,
        string $kind
    ): int {
        $total = 0;

        foreach ($extractor->getRewritableValues($sourceId) as $value) {
            [$rewritten, $replaced] = $this->rewriter->rewriteValue($value['value'], $lookup);

            if ($replaced === 0 || $rewritten === $value['value']) {
                continue;
            }

            if ($dryRun) {
                $total += $replaced;

                continue;
            }

            $this->db->transactionStart();

            try {
                if ($extractor->setRewritableValue($sourceId, $value['key'], $value['value'], $rewritten)) {
                    $this->remember($sourceType, $sourceId, $value['key'], $value['value'], $rewritten, $replaced, $userId, $kind);

                    $total += $replaced;
                }

                $this->db->transactionCommit();
            } catch (\Throwable $e) {
                $this->db->transactionRollback();

                throw $e;
            }
        }

        return $total;
    }

    /**
     * Tells the extractors that keep a copy of the text of the row. The row is rewritten whatever
     * becomes of the copies.
     */
    private function tell(string $table, int $id, callable $lookup): void
    {
        $rewrite = fn (string $html): string => $this->rewriter->rewrite($html, $lookup)[0];

        foreach ($this->extractors->all() as $extractor) {
            if ($extractor instanceof RewriteListenerInterface) {
                try {
                    $extractor->afterRewrite($table, $id, $rewrite);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    private function readColumn(string $table, string $column, int $id): ?string
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName($column))
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = ' . $id);

        return $this->db->setQuery($query)->loadResult();
    }

    private function writeColumn(string $table, string $column, int $id, string $value): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName($table))
            ->set($this->db->quoteName($column) . ' = ' . $this->db->quote($value))
            ->where($this->db->quoteName('id') . ' = ' . $id);

        $this->db->setQuery($query)->execute();
    }
}
