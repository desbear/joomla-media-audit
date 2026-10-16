<?php

/**
 * @package     Media Audit
 * @subpackage  plg_console_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Console\Mediaaudit\Command;

use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

\defined('_JEXEC') or die;

/**
 * php cli/joomla.php mediaaudit:check-broken
 */
final class CheckBrokenCommand extends AbstractCommand
{
    protected static $defaultName = 'mediaaudit:check-broken';

    private const FORMATS = ['table', 'json', 'csv'];

    public function __construct(private DatabaseInterface $db, private ?DispatcherInterface $dispatcher = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_DESC'));
        $this->setHelp(Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_HELP'));

        $this->addOption('external', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_OPT_EXTERNAL'));
        $this->addOption('host', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_OPT_HOST'));
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_OPT_LIMIT'), '0');
        $this->addOption('recheck-days', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_OPT_RECHECK'), '0');
        $this->addOption('concurrency', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_OPT_CONCURRENCY'), '5');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_OPT_TIMEOUT'), '5');
        $this->addOption('delay', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_OPT_DELAY'), '200');
        $this->addOption('prefix', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_OPT_PREFIX'), 'images/');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_OPT_FORMAT'), 'table');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $format = strtolower((string) $input->getOption('format'));

        if (!\in_array($format, self::FORMATS, true)) {
            $io->error(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_SCAN_ERR_FORMAT', implode(', ', self::FORMATS)));

            return Command::INVALID;
        }

        $params   = ComponentHelper::getParams('com_mediaaudit');
        $runner   = ScanRunner::create($this->db, $params, JPATH_ROOT, $this->dispatcher);
        $hosts    = array_values(array_filter(array_map('strtolower', (array) $input->getOption('host'))));
        $external = $input->getOption('external') || (bool) $params->get('check_external', 0);
        $prefix   = ltrim((string) $input->getOption('prefix'), '/');
        $checked  = null;

        // The files may have changed since the scan
        $local = $runner->refresh(true, false);

        if ($external && !HttpFetcher::isAvailable()) {
            $io->error(Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_ERR_CURL'));

            return Command::FAILURE;
        }

        if ($external) {
            $bar = $format === 'table' ? $io->createProgressBar() : null;

            $checked = $runner->getExternalChecker()->check(
                $hosts,
                max(0, (int) $input->getOption('limit')),
                max(0, (int) $input->getOption('recheck-days')),
                max(1, min(20, (int) $input->getOption('concurrency'))),
                max(1, (int) $input->getOption('timeout')),
                max(0, (int) $input->getOption('delay')),
                static function (int $done, int $total) use ($bar): void {
                    if ($bar !== null) {
                        $bar->setMaxSteps($total);
                        $bar->setProgress($done);
                    }
                }
            );

            if ($bar !== null) {
                $bar->finish();
                $io->newLine(2);
            }
        }

        $report = $runner->getReport();
        $data   = [
            'prefix'           => $prefix,
            'local'            => $local,
            'external_checked' => $checked,
            'broken'           => $report->broken($prefix),
            'hosts'            => $report->externalHosts(),
            'broken_external'  => $report->brokenExternal($hosts),
        ];

        if ($format === 'json') {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($format === 'csv') {
            $this->writeCsv($output, $data);
        } else {
            $this->writeTables($io, $data, $external);
        }

        return Command::SUCCESS;
    }

    private function writeTables(SymfonyStyle $io, array $data, bool $external): void
    {
        $io->title(Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_TITLE'));

        $io->section(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_CHECK_LOCAL', $data['prefix'], \count($data['broken'])));

        $rows = [];

        foreach (\array_slice($data['broken'], 0, 30) as $broken) {
            $first  = $broken['sources'][0];
            $rows[] = [$broken['path'], $broken['refs'], $first['type'] . ' ' . $first['id'] . ' ' . $first['field'], $broken['case_suspect'] ?? ''];
        }

        if ($rows) {
            $io->table([
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_PATH'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_REFS'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_SOURCE'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_CASE_FILE'),
            ], $rows);
        }

        if (\count($data['broken']) > 30) {
            $io->writeln(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_SCAN_MORE', \count($data['broken']) - 30));
        }

        $io->section(Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_HOSTS'));

        if ($data['external_checked'] !== null) {
            $io->writeln(Text::sprintf(
                'PLG_CONSOLE_MEDIAAUDIT_CHECK_DONE',
                $data['external_checked']['checked'],
                $data['external_checked']['seconds'],
                $data['external_checked']['ok'],
                $data['external_checked']['broken'],
                $data['external_checked']['unreachable']
            ));
            $io->newLine();
        }

        $io->table(
            [
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_HOST'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_URLS'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_REFS'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_CHECKED'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_BROKEN'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_COPIES'),
            ],
            array_map('array_values', $data['hosts'])
        );

        if (!$external) {
            $io->note(Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_NOTE_OFF'));
        }

        if (!$data['broken_external']) {
            return;
        }

        $io->section(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_CHECK_EXTERNAL', \count($data['broken_external'])));

        $rows = [];

        foreach (\array_slice($data['broken_external'], 0, 30) as $broken) {
            $url    = mb_strlen($broken['url']) > 90 ? mb_substr($broken['url'], 0, 89) . '…' : $broken['url'];
            $rows[] = [$broken['status'] ?: $broken['error'], $broken['refs'], $url];
        }

        $io->table([
            Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_STATUS'),
            Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_REFS'),
            'URL',
        ], $rows);

        if (\count($data['broken_external']) > 30) {
            $io->writeln(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_SCAN_MORE', \count($data['broken_external']) - 30));
        }
    }

    private function writeCsv(OutputInterface $output, array $data): void
    {
        $stream = fopen('php://memory', 'w+');

        fputcsv($stream, ['section', 'target', 'status', 'refs', 'live_refs', 'case_suspect', 'error', 'checked_at', 'local_copy'], ',', '"', '');

        foreach ($data['broken'] as $broken) {
            fputcsv($stream, ['local', $broken['path'], 'missing', $broken['refs'], $broken['live_refs'], $broken['case_suspect'], '', '', ''], ',', '"', '');
        }

        foreach ($data['broken_external'] as $broken) {
            fputcsv($stream, [
                'external', $broken['url'], $broken['status'], $broken['refs'], $broken['live_refs'], '', $broken['error'], $broken['checked_at'], $broken['local_path'],
            ], ',', '"', '');
        }

        rewind($stream);
        $output->write(stream_get_contents($stream), false, OutputInterface::OUTPUT_RAW);
        fclose($stream);
    }
}
