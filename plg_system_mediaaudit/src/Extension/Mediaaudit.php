<?php

/**
 * @package     Media Audit
 * @subpackage  plg_system_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\Mediaaudit\Extension;

use Gss\Component\Mediaaudit\Administrator\Converter\ImageConverter;
use Gss\Component\Mediaaudit\Administrator\Converter\TargetNamer;
use Gss\Component\Mediaaudit\Administrator\Extractor\RewritableSourceInterface;
use Gss\Component\Mediaaudit\Administrator\Extractor\RewritableValuesInterface;
use Gss\Component\Mediaaudit\Administrator\Helper\WaitDialog;
use Gss\Component\Mediaaudit\Administrator\Localizer\SourceRewriter;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Gss\Component\Mediaaudit\Administrator\Service\MarkdownCarrier;
use Gss\Component\Mediaaudit\Administrator\Service\NormalizedPath;
use Gss\Component\Mediaaudit\Administrator\Service\ProgressStore;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Follows what happens to the content:
 *
 * - before a source is saved, copies its external images to this site, and converts its images
 *   to the format of the options;
 * - while that goes on, tells the page that waits how far it is;
 * - after a source is saved, deleted or changes state, brings its references in the index up to date.
 *
 * Which sources an event is about is asked of the extractors, by the context of the event.
 *
 * A system plugin, because modules and template styles are saved with the extension plugins only,
 * and articles with the content plugins only.
 *
 * Nothing here may stop content from being saved: every failure is logged and swallowed.
 */
final class Mediaaudit extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    private ?ScanRunner $runner = null;

    /** @var array<int, array{type: string, changes: list<array{column: string, original: string, rewritten: string, replaced: int, kind: string}>, files: string[], hosts: string[], targets: array<string, array{source: string, target: string}>}> */
    private array $pending = [];

    private ?ProgressStore $progress = null;

    /** URLs that were replaced in what this request saved */
    private int $replaced = 0;

    /** @var string[]  What this request told about the images */
    private array $messages = [];

    /** @var array<string, array{0: string, 1: int}>  Sources to read at the end of the request */
    private array $later = [];

    private bool $shutdown = false;

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentBeforeSave'    => 'onBeforeSave',
            'onContentAfterSave'     => 'onAfterSave',
            'onContentAfterDelete'   => 'onAfterDelete',
            'onContentChangeState'   => 'onChangeState',
            'onExtensionBeforeSave'  => 'onBeforeSave',
            'onExtensionAfterSave'   => 'onAfterSave',
            'onExtensionAfterDelete' => 'onAfterDelete',
            'onBeforeCompileHead'    => 'onBeforeCompileHead',
            'onAjaxMediaaudit'       => 'onAjax',
        ];
    }

    /**
     * Adds the window that tells that the images are worked on to the forms that content is edited
     * in.
     */
    public function onBeforeCompileHead(): void
    {
        try {
            $application = $this->getApplication();
            $user        = $application->getIdentity();

            if (!$user || $user->guest || !($application->isClient('administrator') || $application->isClient('site'))) {
                return;
            }

            if (!$this->isEditForm()) {
                return;
            }

            $detect = ['external' => $this->localizes(), 'local' => $this->converts() !== ''];

            if (!$detect['external'] && !$detect['local']) {
                return;
            }

            // "apply_ajax" is how FLEXIcontent applies: without loading the page again
            WaitDialog::add($application->getDocument(), ['.apply', '.apply_ajax', '.save', '.save2new', '.save2copy'], 'PLG_SYSTEM_MEDIAAUDIT', $detect);
        } catch (\Throwable $e) {
            $this->log($e);
        }
    }

    /**
     * Answers the page that waits: how far the request is that was sent with its token.
     */
    public function onAjax(Event $event): void
    {
        $state = ['stage' => ''];

        try {
            $application = $this->getApplication();
            $user        = $application->getIdentity();

            if ($user && !$user->guest) {
                $token = (string) $application->getInput()->getCmd('token', '');
                $state = $this->progress()->read($token) ?? $state;

                // The last word of a request is told once
                if ($state['stage'] === 'done') {
                    $this->progress()->clear($token);
                }
            }
        } catch (\Throwable $e) {
            $this->log($e);
        }

        if ($event instanceof ResultAwareInterface) {
            $event->addResult($state);

            return;
        }

        $event->setArgument('result', array_merge((array) $event->getArgument('result', []), [$state]));
    }

    public function onBeforeSave(Event $event): void
    {
        [$context, $table] = $this->arguments($event);

        if (!\is_string($context) || !\is_object($table) || !$this->concerns($context)) {
            return;
        }

        try {
            foreach ($this->runner()->getExtractors()->forContext($context) as $type => $extractor) {
                if ($extractor instanceof RewritableSourceInterface && !$extractor->isWrittenLater()) {
                    $this->localize($type, $extractor, $table);

                    // After the copies: they are images of this site now
                    $this->convert($type, $extractor, $table);

                    // A row is the source of one extractor that can be rewritten
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->log($e);
        } finally {
            $this->tellProgress('save', 0, 0, '');
        }
    }

    public function onAfterSave(Event $event): void
    {
        [$context, $table] = $this->arguments($event);

        if (!\is_string($context) || !\is_object($table) || !$this->concerns($context)) {
            return;
        }

        try {
            $runner  = $this->runner();
            $pending = $this->pending[spl_object_id($table)] ?? null;

            unset($this->pending[spl_object_id($table)]);

            foreach ($runner->getExtractors()->forContext($context) as $type => $extractor) {
                foreach ($extractor->getSourceIds($context, $table) as $id) {
                    if ($pending !== null && $pending['type'] === $type) {
                        $runner->getFileScanner()->add($pending['files']);

                        $this->settle($runner, $type, $extractor, $id, $pending);
                    }

                    if ($extractor->isWrittenLater()) {
                        $this->later($type, $id);

                        continue;
                    }

                    $runner->getIndexer()->indexOne($type, $id, $extractor->extractOne($id));
                }
            }
        } catch (\Throwable $e) {
            $this->log($e);
        } finally {
            $this->told();
        }
    }

    public function onAfterDelete(Event $event): void
    {
        [$context, $table] = $this->arguments($event);

        if (!\is_string($context) || !\is_object($table) || !$this->concerns($context)) {
            return;
        }

        try {
            foreach ($this->runner()->getExtractors()->forContext($context) as $type => $extractor) {
                foreach ($extractor->getSourceIds($context, $table) as $id) {
                    $this->runner()->getIndexer()->indexOne($type, $id, [], false);
                }
            }
        } catch (\Throwable $e) {
            $this->log($e);
        }
    }

    public function onChangeState(Event $event): void
    {
        [$context, $ids, $state] = $this->arguments($event);

        if (!\is_string($context) || !\is_array($ids) || $state === null || !$this->concerns($context)) {
            return;
        }

        try {
            foreach (array_keys($this->runner()->getExtractors()->forContext($context)) as $type) {
                $this->runner()->getIndexer()->setState($type, $ids, (int) $state);
            }
        } catch (\Throwable $e) {
            $this->log($e);
        }
    }

    /**
     * Keeps what the columns held before they were changed, when what was changed is what was
     * stored. Some components store another text than the one of the row that the event before the
     * save was given: FLEXIcontent binds the text of the form again after it. What was changed is
     * lost then, and is done again, to the row as it is stored.
     *
     * @param  array{changes: list<array{column: string, original: string, rewritten: string, replaced: int, kind: string}>, hosts: string[], targets: array<string, array{source: string, target: string}>}  $pending
     */
    private function settle(ScanRunner $runner, string $type, object $extractor, int $id, array $pending): void
    {
        if (!$pending['changes'] || !$extractor instanceof RewritableSourceInterface) {
            return;
        }

        $rewriter = $runner->getRewriter();
        $expected = [];

        // The last change of a column is what the row was given
        foreach ($pending['changes'] as $change) {
            $expected[$change['column']] = $change['rewritten'];
        }

        if ($rewriter->stored($extractor, $id, array_keys($expected)) === $expected) {
            foreach ($pending['changes'] as $change) {
                $rewriter->remember($type, $id, $change['column'], $change['original'], $change['rewritten'], $change['replaced'], $this->userId(), $change['kind']);
            }

            return;
        }

        $kinds = array_unique(array_column($pending['changes'], 'kind'));

        if (\in_array(SourceRewriter::KIND_LOCALIZE, $kinds, true)) {
            $rewriter->rewriteOne($type, $id, $rewriter->lookupCopies($pending['hosts']), SourceRewriter::KIND_LOCALIZE, $this->userId());
        }

        if (\in_array(SourceRewriter::KIND_CONVERT, $kinds, true) && $pending['targets']) {
            $rewriter->rewriteOne($type, $id, $rewriter->lookupConverted($pending['targets']), SourceRewriter::KIND_CONVERT, $this->userId());
        }
    }

    /**
     * Reads the sources that were written after their event. Called at the end of the request.
     */
    public function flush(): void
    {
        $later       = $this->later;
        $this->later = [];

        foreach ($later as [$type, $id]) {
            try {
                $extractor = $this->runner()->getExtractors()->get($type);

                if ($extractor !== null) {
                    $references = $extractor->extractOne($id);

                    $this->runner()->getIndexer()->indexOne($type, $id, $references);

                    if ($extractor instanceof RewritableValuesInterface) {
                        $this->convertValues($type, $extractor, $id, $references);
                    }
                }
            } catch (\Throwable $e) {
                $this->log($e);
            }
        }
    }

    /**
     * Converts the images that the fields of an item refer to, and writes the converted files into
     * the values. At the end of the request: the values are written after the event of the save.
     *
     * @param  \Gss\Component\Mediaaudit\Administrator\Service\Reference[]  $references  Of the item.
     */
    private function convertValues(string $type, RewritableValuesInterface $extractor, int $id, array $references): void
    {
        $format   = $this->converts();
        $rewriter = $this->runner()->getRewriter();

        if ($format === '' || !$references || !$rewriter->canRewrite($extractor, SourceRewriter::KIND_CONVERT)) {
            return;
        }

        $params    = $this->options();
        $converter = $this->runner()->getConverter();
        $fixed     = $extractor->getFixedFields();
        $deadline  = microtime(true) + max(1, (int) $params->get('convert_budget', 20));
        $targets   = [];

        foreach ($references as $reference) {
            $field = preg_replace('/#.*$/', '', $reference->field);
            $hash  = $reference->target->hash();

            if ($reference->target->isExternal || isset($targets[$hash]) || $field !== $reference->field || \in_array($field, $fixed, true)
                || !TargetNamer::isConvertible($reference->target->path)) {
                continue;
            }

            if (microtime(true) >= $deadline) {
                $target = $converter->getTargets($format, [$hash])[$hash] ?? null;
            } else {
                $converter->getEncoder()->setTimeout((int) ceil($deadline - microtime(true)));

                $converted = $converter->convert(
                    $reference->target->path,
                    $format,
                    max(1, min(100, (int) $params->get('convert_quality_' . $format, $format === 'webp' ? 82 : 60))),
                    max(0, min(90, (int) $params->get('convert_min_saving', 10))),
                    $this->userId(),
                    max(1, (int) $params->get('convert_max_megapixels', 40)) * 1000000
                );

                $target = $converted['status'] === ImageConverter::CONVERTED ? ['source' => $reference->target->path, 'target' => $converted['target']] : null;
            }

            if ($target !== null) {
                $targets[$hash] = $target;
            }
        }

        if ($targets) {
            $rewriter->rewriteOne($type, $id, $rewriter->lookupConverted($targets), SourceRewriter::KIND_CONVERT, $this->userId());
        }
    }

    private function later(string $type, int $id): void
    {
        $this->later[$type . ':' . $id] = [$type, $id];

        if (!$this->shutdown) {
            $this->shutdown = true;

            // After the redirect that follows a save, and whatever kind of application this is
            register_shutdown_function([$this, 'flush']);
        }
    }

    /**
     * A first look that costs nothing: most events are about what no extractor knows.
     */
    private function concerns(string $context): bool
    {
        return str_contains($context, '.') && !str_starts_with($context, 'com_mediaaudit');
    }

    /**
     * Copies the external images of the row, and writes the paths of the copies into the row.
     */
    private function localize(string $type, RewritableSourceInterface $extractor, object $table): void
    {
        $params = $this->options();
        $hosts  = preg_split('/[\s,]+/', (string) $params->get('localize_hosts', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (!$params->get('localize_on_save', 1) || !$hosts || !HttpFetcher::isAvailable()) {
            return;
        }

        $html = $this->read($table, $extractor->getRewritableHtmlColumns());
        $json = $this->read($table, $extractor->getRewritableJsonColumns());

        $text = implode(' ', $html) . implode(' ', $json);

        // Most of what is saved has no external image at all. Markdown that is kept in it is
        // encoded: what it holds shows when it is read
        if (!preg_match('#(?:https?:)?//#i', $text) && !MarkdownCarrier::has($text)) {
            return;
        }

        $runner    = $this->runner();
        $all       = \in_array('*', $hosts, true);
        $folder    = (string) $params->get('localize_folder', 'images/localized');
        $localizer = $runner->getLocalizer();
        $files     = [];
        $done      = 0;

        $result = $runner->getSaveLocalizer()->localize(
            $html,
            $json,
            $all ? [] : $hosts,
            function (string $url, string $hash, float $left) use ($localizer, $folder, $all, &$files, &$done): ?string {
                $copy = $localizer->copyOf($hash);

                if ($copy !== null || $left < 1) {
                    return $copy;
                }

                $this->tellProgress('localize', $done++, 0, (string) parse_url($url, PHP_URL_HOST));

                // Hosts that the administrator named can be on the local network; "every host" cannot
                $fetched = $localizer->fetchOne($url, $hash, $folder, (int) max(1, min(10, $left)), !$all);

                if ($fetched['result'] === 'failed') {
                    return null;
                }

                $files[] = $fetched['path'];

                return $fetched['path'];
            },
            (float) max(1, (int) $params->get('localize_budget', 15))
        );

        $this->apply($type, $table, $result['columns'], $result['replaced'], SourceRewriter::KIND_LOCALIZE, $files);

        $this->pending[spl_object_id($table)]['hosts'] = $all ? [] : $hosts;

        $this->tell('PLG_SYSTEM_MEDIAAUDIT_LOCALIZED', $result['replaced'], 'message');
        $this->tell('PLG_SYSTEM_MEDIAAUDIT_LOCALIZE_FAILED', $result['failed'], 'warning');
        $this->tell('PLG_SYSTEM_MEDIAAUDIT_LOCALIZE_POSTPONED', $result['postponed'], 'notice');
    }

    /**
     * Converts the images of the row that are of this site, and writes the paths of the converted
     * files into the row. The images stay where they are.
     */
    private function convert(string $type, RewritableSourceInterface $extractor, object $table): void
    {
        $format = $this->converts();

        if ($format === '' || !$this->runner()->getRewriter()->canRewrite($extractor, SourceRewriter::KIND_CONVERT)) {
            return;
        }

        $html = $this->read($table, $extractor->getRewritableHtmlColumns());
        $json = $this->read($table, $extractor->getRewritableJsonColumns());

        $text = implode(' ', $html) . implode(' ', $json);

        // Most of what is saved has no image that can be converted
        if (!preg_match('/\.(?:jpe?g|png)\b/i', $text) && !MarkdownCarrier::has($text)) {
            return;
        }

        $params    = $this->options();
        $runner    = $this->runner();
        $converter = $runner->getConverter();
        $quality   = max(1, min(100, (int) $params->get('convert_quality_' . $format, $format === 'webp' ? 82 : 60)));
        $saving    = max(0, min(90, (int) $params->get('convert_min_saving', 10)));
        $pixels    = max(1, (int) $params->get('convert_max_megapixels', 40)) * 1000000;
        $userId    = $this->userId();
        $files     = [];
        $targets   = [];

        $result = $runner->getSaveConverter()->convert(
            $html,
            $json,
            static function (string $path, float $left) use ($converter, $format, $quality, $saving, $pixels, $userId, &$files, &$targets): ?string {
                $hash = NormalizedPath::hashOf($path);

                // No time left: only what was converted before
                if ($left < 1) {
                    $target = $converter->getTargets($format, [$hash])[$hash] ?? null;
                } else {
                    $converter->getEncoder()->setTimeout((int) ceil($left));

                    $converted = $converter->convert($path, $format, $quality, $saving, $userId, $pixels);
                    $target    = $converted['status'] === ImageConverter::CONVERTED ? ['source' => $path, 'target' => $converted['target']] : null;

                    if ($target !== null && !$converted['reused']) {
                        $files[] = $converted['target'];
                    }
                }

                if ($target === null) {
                    return null;
                }

                $targets[$hash] = $target;

                return $target['target'];
            },
            (float) max(1, (int) $params->get('convert_budget', 20)),
            function (int $done, int $total, string $path): void {
                $this->tellProgress('convert', $done, $total, basename($path));
            }
        );

        $this->apply($type, $table, $result['columns'], $result['replaced'], SourceRewriter::KIND_CONVERT, $files);

        $this->pending[spl_object_id($table)]['targets'] = $targets;

        if ($result['replaced'] > 0) {
            $this->messages[] = Text::sprintf('PLG_SYSTEM_MEDIAAUDIT_CONVERTED', $result['replaced'], strtoupper($format));

            if ($this->getApplication()->isClient('administrator') || $this->getApplication()->isClient('site')) {
                $this->getApplication()->enqueueMessage(end($this->messages), 'message');
            }
        }

        $this->tell('PLG_SYSTEM_MEDIAAUDIT_CONVERT_POSTPONED', $result['postponed'], 'notice');
    }

    /**
     * Writes the new text of the columns into the row, and keeps what they held for after the save.
     *
     * @param  array<string, string>  $columns   Column => new text
     * @param  int                    $replaced  URLs, of all the columns together.
     * @param  string[]               $files     Files that were written.
     */
    private function apply(string $type, object $table, array $columns, int $replaced, string $kind, array $files): void
    {
        $key = spl_object_id($table);

        $this->replaced += $replaced;

        $this->pending[$key] ??= ['type' => $type, 'changes' => [], 'files' => [], 'hosts' => [], 'targets' => []];

        foreach ($columns as $column => $rewritten) {
            $this->pending[$key]['changes'][] = [
                'column'    => $column,
                'original'  => $table->$column,
                'rewritten' => $rewritten,
                // The number is known for all the columns together: the first one carries it
                'replaced'  => $column === array_key_first($columns) ? $replaced : 0,
                'kind'      => $kind,
            ];

            $table->$column = $rewritten;
        }

        $this->pending[$key]['files'] = array_merge($this->pending[$key]['files'], $files);
    }

    /**
     * @param   string[]  $columns
     *
     * @return  array<string, string>  The columns of the row that hold text
     */
    private function read(object $table, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            if (isset($table->$column) && \is_string($table->$column) && $table->$column !== '') {
                $values[$column] = $table->$column;
            }
        }

        return $values;
    }

    /**
     * Whether saving copies external images.
     */
    private function localizes(): bool
    {
        $params = $this->options();

        return (bool) $params->get('localize_on_save', 1)
            && trim((string) $params->get('localize_hosts', '')) !== ''
            && HttpFetcher::isAvailable();
    }

    /**
     * The format that saving converts images to. Empty when it does not.
     */
    private function converts(): string
    {
        $params = $this->options();
        $format = (string) $params->get('convert_format', '');

        if (!\in_array($format, TargetNamer::FORMATS, true) || !$params->get('convert_on_save', 1)) {
            return '';
        }

        return $this->runner()->getConverter()->getEncoder()->can($format) ? $format : '';
    }

    private function progress(): ProgressStore
    {
        return $this->progress ??= new ProgressStore((string) $this->getApplication()->get('tmp_path', sys_get_temp_dir()));
    }

    /**
     * The token that the form was sent with. Empty for a form without the window.
     */
    private function token(): string
    {
        try {
            $token = (string) $this->getApplication()->getInput()->getCmd(WaitDialog::FIELD, '');
        } catch (\Throwable $e) {
            return '';
        }

        return ProgressStore::isToken($token) ? $token : '';
    }

    private function tellProgress(string $stage, int $done, int $total, string $current): void
    {
        if ($this->token() !== '') {
            $this->progress()->write($this->token(), ['stage' => $stage, 'done' => $done, 'total' => $total, 'current' => $current]);
        }
    }

    /**
     * The work on the images is over. A page that sent its form without being loaded again shows
     * what its author sent: it is told whether that is what was stored.
     */
    private function told(): void
    {
        if ($this->token() !== '') {
            $this->progress()->write($this->token(), [
                'stage'    => 'done',
                'replaced' => $this->replaced,
                'messages' => implode("\n", $this->messages),
            ]);
        }
    }

    /**
     * Whether the page is a form that content is edited in. Joomla names the layout; FLEXIcontent
     * names the task.
     */
    private function isEditForm(): bool
    {
        $input  = $this->getApplication()->getInput();
        $option = $input->getCmd('option');

        if ($option === 'com_mediaaudit') {
            return false;
        }

        return $input->getCmd('layout') === 'edit'
            || preg_match('/(?:^|\.)(?:edit|add)$/', (string) $input->getCmd('task'))
            || ($option === 'com_flexicontent' && \in_array($input->getCmd('view'), ['item', 'form'], true));
    }

    /**
     * The arguments by position, for the named arguments of Joomla 5 and the numbered ones before.
     *
     * @return  array{0: mixed, 1: mixed, 2: mixed}
     */
    private function arguments(Event $event): array
    {
        return array_pad(array_values($event->getArguments()), 3, null);
    }

    private function runner(): ScanRunner
    {
        if ($this->runner === null) {
            $application = $this->getApplication();

            $this->runner = ScanRunner::create(
                Factory::getContainer()->get(DatabaseInterface::class),
                $this->options(),
                JPATH_ROOT,
                $application->getDispatcher(),
                (string) $application->get('tmp_path', sys_get_temp_dir())
            );
        }

        return $this->runner;
    }

    private function options(): Registry
    {
        return ComponentHelper::getParams('com_mediaaudit');
    }

    private function userId(): int
    {
        $user = $this->getApplication()->getIdentity();

        return $user ? (int) $user->id : 0;
    }

    private function tell(string $key, int $count, string $type): void
    {
        $application = $this->getApplication();

        if ($count <= 0) {
            return;
        }

        // For a page that is loaded again for what was saved, after the messages were shown
        $this->messages[] = Text::sprintf($key, $count);

        // Only where there is someone to read it
        if ($application->isClient('administrator') || $application->isClient('site')) {
            $application->enqueueMessage(Text::sprintf($key, $count), $type);
        }
    }

    private function log(\Throwable $e): void
    {
        try {
            Log::addLogger(['text_file' => 'mediaaudit.php'], Log::ALL, ['mediaaudit']);
            Log::add($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), Log::ERROR, 'mediaaudit');
        } catch (\Throwable $ignored) {
        }
    }
}
