<?php

/**
 * @package     Media Audit
 * @subpackage  plg_console_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Console\Mediaaudit\Command;

use Gss\Component\Mediaaudit\Administrator\Localizer\ImageLocalizer;
use Gss\Component\Mediaaudit\Administrator\Localizer\SourceRewriter;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
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
 * php cli/joomla.php mediaaudit:localize
 */
final class LocalizeCommand extends AbstractCommand
{
    protected static $defaultName = 'mediaaudit:localize';

    public function __construct(private DatabaseInterface $db, private ?DispatcherInterface $dispatcher = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_DESC'));
        $this->setHelp(Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_HELP'));

        $this->addOption('host', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_HOST'));
        $this->addOption('all-hosts', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_ALL_HOSTS'));
        $this->addOption('folder', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_FOLDER'));
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_LIMIT'), '0');
        $this->addOption('delay', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_DELAY'), '100');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_DRY_RUN'));
        $this->addOption('download-only', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_DOWNLOAD_ONLY'));
        $this->addOption('rewrite-only', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_REWRITE_ONLY'));
        $this->addOption('retry-failed', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_RETRY'));
        $this->addOption('revert', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_REVERT'));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $params = ComponentHelper::getParams('com_mediaaudit');
        $dryRun = (bool) $input->getOption('dry-run');
        $runner = ScanRunner::create($this->db, $params, JPATH_ROOT, $this->dispatcher, Factory::getApplication()->get('tmp_path'));

        $io->title(Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_TITLE') . ($dryRun ? ' — ' . Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_DRY_RUN') : ''));

        if ($input->getOption('revert')) {
            // Not what was converted: that is for mediaaudit:convert to put back
            $stats = $runner->getRewriter()->revert($dryRun, [SourceRewriter::KIND_LOCALIZE, SourceRewriter::KIND_REMOVE_LOST]);

            if (!$dryRun) {
                $runner->refresh(false);
            }

            $io->success(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_REVERTED', $stats['reverted'], $stats['edited'], $stats['gone']));

            return Command::SUCCESS;
        }

        $hosts = $this->hosts($input, (string) $params->get('localize_hosts', ''));

        if ($hosts === null) {
            $io->error(Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_ERR_HOSTS'));

            return Command::INVALID;
        }

        try {
            $folder = ImageLocalizer::checkFolder((string) ($input->getOption('folder') ?: $params->get('localize_folder', ImageLocalizer::DEFAULT_FOLDER)));
        } catch (\InvalidArgumentException $e) {
            $io->error(Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_ERR_FOLDER'));

            return Command::INVALID;
        }

        $io->writeln(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_HOSTS', $hosts ? implode(', ', $hosts) : '*', $folder));
        $io->newLine();

        if (!$input->getOption('rewrite-only')) {
            if (!HttpFetcher::isAvailable()) {
                $io->error(Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_ERR_CURL'));

                return Command::FAILURE;
            }

            $bar      = $io->createProgressBar();
            $failures = [];
            $stats    = $runner->getLocalizer()->download(
                $hosts,
                $folder,
                max(0, (int) $input->getOption('limit')),
                $dryRun,
                max(0, (int) $input->getOption('delay')),
                static function (int $done, int $total, string $url, string $result) use ($bar, &$failures): void {
                    $bar->setMaxSteps($total);
                    $bar->setProgress($done);

                    if (!str_starts_with($result, 'images/')) {
                        $failures[] = [$result, mb_strlen($url) > 100 ? mb_substr($url, 0, 99) . '…' : $url];
                    }
                },
                $input->getOption('retry-failed') ? 0 : 7
            );

            $bar->finish();
            $io->newLine(2);

            $io->writeln(Text::sprintf(
                $dryRun ? 'PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_WOULD_DOWNLOAD' : 'PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_DOWNLOADED',
                $stats['urls'],
                $stats['downloaded'],
                $stats['reused'],
                $stats['failed'],
                number_format($stats['bytes'] / 1048576, 1),
                $stats['seconds']
            ));

            if ($failures) {
                $io->table([Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_ERROR'), 'URL'], \array_slice($failures, 0, 30));
            }

            if (!$dryRun) {
                // The copies are files of the site from now on
                $runner->refresh(true, (bool) $params->get('hash_files', 1));
            }
        }

        if (!$input->getOption('download-only')) {
            $stats = $runner->getRewriter()->rewrite($hosts, $dryRun);

            if (!$dryRun) {
                $runner->refresh(false);
            }

            $io->writeln(Text::sprintf(
                $dryRun ? 'PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_WOULD_REWRITE' : 'PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_REWRITTEN',
                $stats['rewritten'],
                $stats['replaced'],
                $stats['unsupported']
            ));
        }

        $io->newLine();
        $io->table(
            [
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_HOST'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_URLS'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_REFS'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_CHECKED'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_CLASS_BROKEN'),
                Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_COPIES'),
            ],
            array_map('array_values', $runner->getReport()->externalHosts())
        );

        if (!$dryRun && !$input->getOption('download-only')) {
            $io->note(Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_NOTE_REVERT'));
        }

        return Command::SUCCESS;
    }

    /**
     * @return  string[]|null  Empty for every host. Null when no host was named at all.
     */
    private function hosts(InputInterface $input, string $option): ?array
    {
        if ($input->getOption('all-hosts')) {
            return [];
        }

        $hosts = array_filter(array_map('trim', (array) $input->getOption('host')));

        if (!$hosts) {
            $hosts = preg_split('/[\s,]+/', $option, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (!$hosts) {
            return null;
        }

        if (\in_array('*', $hosts, true)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $host): string => strtolower(preg_replace('#^[a-z]+://|/.*$#i', '', $host)),
            $hosts
        )));
    }
}
