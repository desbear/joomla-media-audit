<?php

/**
 * @package     Media Audit
 * @subpackage  plg_console_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Console\Mediaaudit\Command;

use Gss\Component\Mediaaudit\Administrator\Report\ReportRepository;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
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
 * php cli/joomla.php mediaaudit:scan
 */
final class ScanCommand extends AbstractCommand
{
    protected static $defaultName = 'mediaaudit:scan';

    private const FORMATS = ['table', 'json', 'csv'];

    public function __construct(private DatabaseInterface $db, private ?DispatcherInterface $dispatcher = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_DESC'));
        $this->setHelp(Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_HELP'));

        $this->addOption('no-hash', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_OPT_NO_HASH'));
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_OPT_FORMAT'), 'table');
        $this->addOption('prefix', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_OPT_PREFIX'), 'images/');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_OPT_LIMIT'), '30');
        $this->addOption('report-only', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_OPT_REPORT_ONLY'));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $format = strtolower((string) $input->getOption('format'));

        if (!\in_array($format, self::FORMATS, true)) {
            $io->error(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_SCAN_ERR_FORMAT', implode(', ', self::FORMATS)));

            return Command::INVALID;
        }

        $params = ComponentHelper::getParams('com_mediaaudit');
        $hash   = !$input->getOption('no-hash') && (bool) $params->get('hash_files', 1);
        $prefix = ltrim((string) $input->getOption('prefix'), '/');
        $runner = ScanRunner::create($this->db, $params, JPATH_ROOT, $this->dispatcher);
        $scan   = null;

        if (!$input->getOption('report-only')) {
            // Progress goes to the table only: json and csv have to stay clean for the next program
            $scan = $runner->run($hash, static function (string $step) use ($io, $format): void {
                if ($format === 'table') {
                    $io->writeln(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_SCAN_STEP', $step));
                }
            });
        }

        $report = $runner->getReport();

        if ($scan !== null) {
            // Of the whole of images/, whatever the prefix of this report is
            $runner->getHistory()->record('cli', (float) $scan['seconds'], $report->summary());
        }

        $data = [
            'prefix'     => $prefix,
            'scan'       => $scan,
            'summary'    => $report->summary($prefix),
            'files'      => $report->files($prefix),
            'broken'     => $report->broken($prefix),
            'duplicates' => $report->duplicates($prefix),
        ];

        if ($format === 'json') {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($format === 'csv') {
            $this->writeCsv($output, $data);
        } else {
            $this->writeTables($io, $data, max(0, (int) $input->getOption('limit')));
        }

        return Command::SUCCESS;
    }

    private function writeTables(SymfonyStyle $io, array $data, int $limit): void
    {
        $summary = $data['summary'];

        $io->title(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_SCAN_TITLE', $data['prefix'] === '' ? '/' : $data['prefix']));

        if ($data['scan'] !== null) {
            $io->writeln(Text::sprintf(
                'PLG_CONSOLE_MEDIAAUDIT_SCAN_DONE',
                $data['scan']['seconds'],
                $data['scan']['files']['found'],
                array_sum(array_column($data['scan']['sources'], 'references'))
            ));
            $io->newLine();
        }

        $io->table(
            [Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_CLASS'), Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_COUNT')],
            [
                [Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_FILES'), $summary['files']],
                [Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_IN_USE'), $summary['in_use']],
                [Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_SEMI_ORPHAN'), $summary['semi_orphan']],
                [Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_ORPHAN'), $summary['orphan'] . ' (' . self::bytes($summary['orphan_bytes']) . ')'],
                [Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_BROKEN'), $summary['broken']],
                [Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_DUPLICATE'), $summary['duplicate_groups'] . ' (' . self::bytes($summary['wasted_bytes']) . ')'],
            ]
        );

        $sections = [
            ReportRepository::ORPHAN      => 'PLG_CONSOLE_MEDIAAUDIT_CLASS_ORPHAN',
            ReportRepository::SEMI_ORPHAN => 'PLG_CONSOLE_MEDIAAUDIT_CLASS_SEMI_ORPHAN',
        ];

        foreach ($sections as $class => $title) {
            $rows = [];

            foreach ($data['files'] as $file) {
                if ($file['class'] === $class) {
                    $rows[] = [$file['path'], self::bytes($file['size']), $file['refs'], $file['case_suspect_of'] ?? ''];
                }
            }

            $this->section($io, Text::_($title), [
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_PATH'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_SIZE'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_REFS'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_CASE_REFERENCE'),
            ], $rows, $limit);
        }

        $rows = [];

        foreach ($data['broken'] as $broken) {
            $first  = $broken['sources'][0];
            $rows[] = [
                $broken['path'],
                $broken['refs'],
                $first['type'] . ' ' . $first['id'] . ' ' . $first['field'] . ($broken['refs'] > 1 ? ' …' : ''),
                $broken['case_suspect'] ?? '',
            ];
        }

        $this->section($io, Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_BROKEN'), [
            Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_PATH'),
            Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_REFS'),
            Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_SOURCE'),
            Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_CASE_FILE'),
        ], $rows, $limit);

        $rows = [];

        foreach ($data['duplicates'] as $group) {
            foreach ($group['paths'] as $i => $path) {
                $rows[] = [$i === 0 ? substr($group['sha1'], 0, 12) : '', $i === 0 ? self::bytes($group['size']) : '', $path];
            }
        }

        $this->section($io, Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_DUPLICATE'), [
            'SHA-1',
            Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_SIZE'),
            Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_PATH'),
        ], $rows, $limit, \count($data['duplicates']));

        $io->note(Text::_('PLG_CONSOLE_MEDIAAUDIT_SCAN_NOTE'));
    }

    private function section(SymfonyStyle $io, string $title, array $headers, array $rows, int $limit, ?int $count = null): void
    {
        $io->section($title . ' (' . ($count ?? \count($rows)) . ')');

        if (!$rows) {
            return;
        }

        $io->table($headers, $limit > 0 ? \array_slice($rows, 0, $limit) : $rows);

        if ($limit > 0 && \count($rows) > $limit) {
            $io->writeln(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_SCAN_MORE', \count($rows) - $limit));
            $io->newLine();
        }
    }

    private function writeCsv(OutputInterface $output, array $data): void
    {
        $stream = fopen('php://memory', 'w+');

        fputcsv($stream, ['section', 'path', 'class', 'size', 'refs', 'live_refs', 'sha1', 'case_suspect', 'sources'], ',', '"', '');

        foreach ($data['files'] as $file) {
            fputcsv($stream, [
                'file', $file['path'], $file['class'], $file['size'], $file['refs'], $file['live_refs'], $file['sha1'], $file['case_suspect_of'], '',
            ], ',', '"', '');
        }

        foreach ($data['broken'] as $broken) {
            $sources = array_map(static fn (array $source): string => $source['type'] . ':' . $source['id'] . ':' . $source['field'], $broken['sources']);

            fputcsv($stream, [
                'broken', $broken['path'], 'broken', '', $broken['refs'], $broken['live_refs'], '', $broken['case_suspect'], implode(' ', $sources),
            ], ',', '"', '');
        }

        foreach ($data['duplicates'] as $group) {
            foreach ($group['paths'] as $path) {
                fputcsv($stream, ['duplicate', $path, 'duplicate', $group['size'], '', '', $group['sha1'], '', ''], ',', '"', '');
            }
        }

        rewind($stream);
        $output->write(stream_get_contents($stream), false, OutputInterface::OUTPUT_RAW);
        fclose($stream);
    }

    private static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit  = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : number_format($value, 1)) . ' ' . $units[$unit];
    }
}
