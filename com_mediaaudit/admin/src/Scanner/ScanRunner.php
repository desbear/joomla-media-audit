<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Scanner;

use Gss\Component\Mediaaudit\Administrator\Converter\BatchConverter;
use Gss\Component\Mediaaudit\Administrator\Converter\Encoder;
use Gss\Component\Mediaaudit\Administrator\Converter\ImageConverter;
use Gss\Component\Mediaaudit\Administrator\Converter\SaveConverter;
use Gss\Component\Mediaaudit\Administrator\Extractor\ExtractorRegistry;
use Gss\Component\Mediaaudit\Administrator\Localizer\ContentRewriter;
use Gss\Component\Mediaaudit\Administrator\Localizer\ImageLocalizer;
use Gss\Component\Mediaaudit\Administrator\Localizer\SaveLocalizer;
use Gss\Component\Mediaaudit\Administrator\Localizer\SourceRewriter;
use Gss\Component\Mediaaudit\Administrator\Report\ReportRepository;
use Gss\Component\Mediaaudit\Administrator\Report\ScanHistory;
use Gss\Component\Mediaaudit\Administrator\Service\HtmlReferenceParser;
use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Gss\Component\Mediaaudit\Administrator\Service\JsonReferenceWalker;
use Gss\Component\Mediaaudit\Administrator\Service\ActionLogger;
use Gss\Component\Mediaaudit\Administrator\Service\PathNormalizer;
use Gss\Component\Mediaaudit\Administrator\Service\QuarantineService;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * The full scan: the files, then the references of every extractor, then which of them are broken.
 */
final class ScanRunner
{
    public function __construct(
        private FileScanner $files,
        private ReferenceIndexer $indexer,
        private ExtractorRegistry $extractors,
        private ReportRepository $report,
        private ExternalChecker $external,
        private ImageLocalizer $localizer,
        private SourceRewriter $rewriter,
        private int $batchSize = 200,
        private ?SaveLocalizer $saveLocalizer = null,
        private ?ScanHistory $history = null,
        private ?QuarantineService $quarantine = null,
        private ?ImageConverter $converter = null,
        private ?SaveConverter $saveConverter = null,
        private ?BatchConverter $batchConverter = null
    ) {
    }

    /**
     * @param  Registry  $params  Options of the component.
     * @param  string    $root    Root of the site.
     * @param  string    $tmp     Folder for downloads that are not checked yet.
     */
    public static function create(
        DatabaseInterface $db,
        Registry $params,
        string $root,
        ?DispatcherInterface $dispatcher = null,
        ?string $tmp = null
    ): self {
        $parser     = new HtmlReferenceParser();
        $normalizer = PathNormalizer::fromOptions((string) $params->get('site_domains', ''), (string) $params->get('base_path', ''));
        $builder    = new ReferenceBuilder($normalizer, $parser, new JsonReferenceWalker($parser));
        $http       = new HttpFetcher();
        $files      = new FileScanner($db, $root);
        $indexer    = new ReferenceIndexer($db, $root, $files);
        $extractors = new ExtractorRegistry($db, $builder, $dispatcher, $root);
        $localizer  = new ImageLocalizer($db, $http, $root, $tmp ?? sys_get_temp_dir());
        $history    = new ScanHistory($db);
        $converter  = new ImageConverter($db, $root, $tmp ?? sys_get_temp_dir(), new Encoder((string) $params->get('convert_program', '')), $files);
        $rewriter   = new SourceRewriter($db, $extractors, $indexer, $localizer, $normalizer, new ContentRewriter());

        return new self(
            $files,
            $indexer,
            $extractors,
            new ReportRepository($db),
            new ExternalChecker(
                $db,
                $http,
                array_values(array_diff(preg_split('/[\s,]+/', (string) $params->get('localize_hosts', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [], ['*']))
            ),
            $localizer,
            $rewriter,
            max(20, (int) $params->get('batch_size', 200)),
            new SaveLocalizer($builder, new ContentRewriter()),
            $history,
            new QuarantineService(
                $db,
                $root,
                $history,
                max(0, (int) $params->get('quarantine_scan_age', 24)),
                new ActionLogger($db),
                max(1, (int) $params->get('quarantine_delete_days', 30))
            ),
            $converter,
            new SaveConverter($builder, new ContentRewriter()),
            new BatchConverter($db, $converter, static fn (): array => $rewriter->getConvertible())
        );
    }

    /**
     * @param   bool           $hash      Compute the SHA-1 of the files.
     * @param   callable|null  $progress  Called with a line of text at every step.
     *
     * @return  array{seconds: float, files: array<string, int>, sources: array<string, array{references: int, skipped: int}>, existence: array{checked: int, missing: int}}
     */
    public function run(bool $hash = true, ?callable $progress = null): array
    {
        $progress ??= static function (string $message): void {
        };

        $start = microtime(true);

        $progress('files');
        $result = ['seconds' => 0.0, 'files' => $this->files->scan($hash), 'sources' => [], 'existence' => []];

        foreach ($this->extractors->all() as $type => $extractor) {
            $progress($type);
            $result['sources'][$type] = $this->indexer->indexAll($extractor, $this->batchSize);
        }

        $progress('existence');
        $result['existence'] = $this->refresh(false);
        $result['seconds']   = round(microtime(true) - $start, 2);

        return $result;
    }

    /**
     * Brings the index in line with the disk after files were added, and tells which references are
     * broken.
     *
     * @return  array{checked: int, missing: int}
     */
    public function refresh(bool $files = true, bool $hash = true): array
    {
        if ($files) {
            $this->files->scan($hash);
        }

        $existence = $this->indexer->checkExistence();

        // A scan writes the references anew: give them back what the other sites answered
        $this->external->copyToReferences();

        return $existence;
    }

    public function getConverter(): ImageConverter
    {
        return $this->converter;
    }

    public function getSaveConverter(): SaveConverter
    {
        return $this->saveConverter;
    }

    public function getBatchConverter(): BatchConverter
    {
        return $this->batchConverter;
    }

    public function getExternalChecker(): ExternalChecker
    {
        return $this->external;
    }

    public function getLocalizer(): ImageLocalizer
    {
        return $this->localizer;
    }

    public function getQuarantine(): QuarantineService
    {
        return $this->quarantine;
    }

    public function getHistory(): ScanHistory
    {
        return $this->history;
    }

    public function getSaveLocalizer(): SaveLocalizer
    {
        return $this->saveLocalizer;
    }

    public function getFileScanner(): FileScanner
    {
        return $this->files;
    }

    public function getRewriter(): SourceRewriter
    {
        return $this->rewriter;
    }

    public function getReport(): ReportRepository
    {
        return $this->report;
    }

    public function getIndexer(): ReferenceIndexer
    {
        return $this->indexer;
    }

    public function getExtractors(): ExtractorRegistry
    {
        return $this->extractors;
    }
}
