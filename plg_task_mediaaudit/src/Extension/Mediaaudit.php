<?php

/**
 * @package     Media Audit
 * @subpackage  plg_task_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Task\Mediaaudit\Extension;

use Gss\Component\Mediaaudit\Administrator\Report\SummaryFormatter;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * The scheduled scan of Media Audit.
 */
final class Mediaaudit extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use TaskPluginTrait;

    protected const TASKS_MAP = [
        'mediaaudit.scan' => [
            'langConstPrefix' => 'PLG_TASK_MEDIAAUDIT_SCAN',
            'form'            => 'scan',
            'method'          => 'scan',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    protected function scan(ExecuteTaskEvent $event): int
    {
        $this->loadLanguage();

        $task    = $event->getArgument('params');
        $options = ComponentHelper::getParams('com_mediaaudit');
        $runner  = ScanRunner::create(
            $this->getDatabase(),
            $options,
            JPATH_ROOT,
            $this->getApplication()->getDispatcher(),
            (string) $this->getApplication()->get('tmp_path', sys_get_temp_dir())
        );

        $result = $this->run($runner, $task, $options);
        $sent   = 0;

        try {
            $sent = $this->mail($result, (string) ($task->email ?? 'always'), (string) ($task->recipients ?? ''));
        } catch (\Throwable $e) {
            // The scan is done: a summary that cannot be sent is no reason to call it failed
            $this->logTask(Text::sprintf('PLG_TASK_MEDIAAUDIT_LOG_MAIL_FAILED', $e->getMessage()), 'warning');
        }

        $this->logTask(Text::sprintf(
            'PLG_TASK_MEDIAAUDIT_LOG_DONE',
            $result['summary']['files'],
            $result['summary']['orphan'],
            $result['summary']['broken'],
            $sent
        ));

        return TaskStatus::OK;
    }

    /**
     * Scan, copies of external images, check of external images: what the task is there for.
     *
     * @return  array{seconds: float, summary: array<string, int>, previous: ?array, localized: ?array, converted: ?array, external: ?array, hosts: array}
     */
    public function run(ScanRunner $runner, object $task, \Joomla\Registry\Registry $options): array
    {
        $start = microtime(true);
        $hash  = (bool) $options->get('hash_files', 1);

        $runner->run($hash);

        $localized = null;
        $external  = null;
        $hosts     = preg_split('/[\s,]+/', (string) $options->get('localize_hosts', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (($task->localize ?? 1) && $hosts && HttpFetcher::isAvailable()) {
            $named = \in_array('*', $hosts, true) ? [] : $hosts;

            $localized = $runner->getLocalizer()->download(
                $named,
                (string) $options->get('localize_folder', 'images/localized'),
                max(1, (int) ($task->localize_limit ?? 50)),
                false,
                max(0, (int) ($task->localize_delay ?? 1000))
            );

            $runner->refresh(true, $hash);

            $localized += $runner->getRewriter()->rewrite($named);

            $runner->refresh(false);
        }

        $converted = null;
        $format    = (string) $options->get('convert_format', '');

        // After the copies: they are images of this site now. Off unless the task asks for it
        if (($task->convert ?? 0) && $format !== '' && $runner->getConverter()->getEncoder()->can($format)) {
            $converted = $runner->getBatchConverter()->run(
                $format,
                max(1, min(100, (int) $options->get('convert_quality_' . $format, $format === 'webp' ? 82 : 60))),
                max(0, min(90, (int) $options->get('convert_min_saving', 10))),
                max(1, (int) ($task->convert_limit ?? 50)),
                0.0,
                [],
                0,
                max(1, (int) $options->get('convert_max_megapixels', 40)) * 1000000
            );

            // Of this run and of the ones before: what saving content had no time for
            $converted += $runner->getRewriter()->rewriteConverted($runner->getConverter()->getTargets($format));

            unset($converted['targets']);

            $converted['format'] = strtoupper($format);

            $runner->refresh(false);
        }

        if (($task->check_external ?? 0) && HttpFetcher::isAvailable()) {
            $external = $runner->getExternalChecker()->check(
                [],
                max(1, (int) ($task->check_limit ?? 200)),
                max(0, (int) ($task->recheck_days ?? 7))
            );
        }

        $report   = $runner->getReport();
        $summary  = $report->summary();
        $previous = $runner->getHistory()->last();
        $seconds  = round(microtime(true) - $start, 2);

        $runner->getHistory()->record('task', $seconds, $summary);

        return [
            'seconds'   => $seconds,
            'summary'   => $summary,
            'previous'  => $previous,
            'localized' => $localized,
            'converted' => $converted,
            'external'  => $external,
            'hosts'     => $report->externalHosts(),
        ];
    }

    /**
     * The text of the summary.
     *
     * @return  array{subject: string, body: string}
     */
    public function compose(array $result): array
    {
        $this->loadLanguage();

        $site    = (string) $this->getApplication()->get('sitename');
        $summary = $result['summary'];
        $lines   = [Text::sprintf('PLG_TASK_MEDIAAUDIT_MAIL_INTRO', $site, $result['seconds']), ''];

        $lines[] = SummaryFormatter::format($summary, $result['previous']['summary'] ?? null, [
            'files'               => Text::_('PLG_TASK_MEDIAAUDIT_LABEL_FILES'),
            'in_use'              => Text::_('PLG_TASK_MEDIAAUDIT_LABEL_IN_USE'),
            'semi_orphan'         => Text::_('PLG_TASK_MEDIAAUDIT_LABEL_SEMI_ORPHAN'),
            'orphan'              => Text::_('PLG_TASK_MEDIAAUDIT_LABEL_ORPHAN'),
            'broken'              => Text::_('PLG_TASK_MEDIAAUDIT_LABEL_BROKEN'),
            'duplicate_groups'    => Text::_('PLG_TASK_MEDIAAUDIT_LABEL_DUPLICATE'),
            'external_references' => Text::_('PLG_TASK_MEDIAAUDIT_LABEL_EXTERNAL'),
        ]);

        $lines[] = '';
        $lines[] = $result['previous'] === null
            ? Text::_('PLG_TASK_MEDIAAUDIT_MAIL_FIRST')
            : Text::sprintf('PLG_TASK_MEDIAAUDIT_MAIL_COMPARED', $result['previous']['scanned_at']);

        if ($result['localized'] !== null && ($result['localized']['urls'] > 0 || $result['localized']['rewritten'] > 0)) {
            $lines[] = '';
            $lines[] = Text::sprintf(
                'PLG_TASK_MEDIAAUDIT_MAIL_LOCALIZED',
                $result['localized']['downloaded'] + $result['localized']['reused'],
                $result['localized']['failed'],
                $result['localized']['rewritten']
            );
        }

        if (($result['converted'] ?? null) !== null && ($result['converted']['images'] > 0 || $result['converted']['rewritten'] > 0)) {
            $lines[] = '';
            $lines[] = Text::sprintf(
                'PLG_TASK_MEDIAAUDIT_MAIL_CONVERTED',
                $result['converted']['converted'],
                $result['converted']['format'],
                number_format($result['converted']['bytes_before'] / 1048576, 1),
                number_format($result['converted']['bytes_after'] / 1048576, 1),
                $result['converted']['not_smaller'],
                $result['converted']['failed'],
                $result['converted']['rewritten'],
                $result['converted']['left']
            );
        }

        if ($result['external'] !== null) {
            $lines[] = '';
            $lines[] = Text::sprintf(
                'PLG_TASK_MEDIAAUDIT_MAIL_EXTERNAL',
                $result['external']['checked'],
                $result['external']['broken'],
                $result['external']['unreachable']
            );
        }

        if ($result['hosts']) {
            $lines[] = '';
            $lines[] = Text::_('PLG_TASK_MEDIAAUDIT_MAIL_HOSTS');

            foreach (\array_slice($result['hosts'], 0, 15) as $host) {
                $lines[] = '  ' . $host['host'] . '  ' . number_format($host['urls']);
            }
        }

        $lines[] = '';
        $lines[] = Text::_('PLG_TASK_MEDIAAUDIT_MAIL_NOTE');

        $root = rtrim(Uri::root(), '/');

        // No address on the command line unless the site has one in its configuration
        if ($root !== '' && !str_contains($root, 'joomla.invalid')) {
            $lines[] = Text::sprintf('PLG_TASK_MEDIAAUDIT_MAIL_LINK', $root . '/administrator/index.php?option=com_mediaaudit');
        }

        return [
            'subject' => Text::sprintf('PLG_TASK_MEDIAAUDIT_MAIL_SUBJECT', $site, $summary['orphan'], $summary['broken']),
            'body'    => implode("\n", $lines) . "\n",
        ];
    }

    /**
     * @return  int  To how many addresses the summary was sent.
     */
    private function mail(array $result, string $when, string $recipients): int
    {
        if ($when === 'never') {
            return 0;
        }

        if ($when === 'changes' && !SummaryFormatter::hasChanged($result['summary'], $result['previous']['summary'] ?? null)) {
            return 0;
        }

        $addresses = $this->recipients($recipients);

        if (!$addresses || !$this->getApplication()->get('mailonline', 1)) {
            return 0;
        }

        $mail   = $this->compose($result);
        $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();

        $mailer->setSubject($mail['subject']);
        $mailer->setBody($mail['body']);
        $mailer->isHtml(false);

        foreach ($addresses as $address) {
            $mailer->addRecipient($address);
        }

        $mailer->send();

        return \count($addresses);
    }

    /**
     * @return  string[]
     */
    private function recipients(string $recipients): array
    {
        $addresses = array_filter(
            preg_split('/[\s,;]+/', $recipients, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        );

        if ($addresses) {
            return array_values(array_unique($addresses));
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('u.email'))
            ->from($db->quoteName('#__users', 'u'))
            ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm'), $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id'))
            ->where($db->quoteName('m.group_id') . ' = 8')
            ->where($db->quoteName('u.block') . ' = 0')
            ->where($db->quoteName('u.sendEmail') . ' = 1');

        return $db->setQuery($query)->loadColumn();
    }
}
