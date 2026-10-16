<?php

/**
 * @package     Media Audit
 * @subpackage  plg_console_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Console\Mediaaudit\Command;

use Gss\Component\Mediaaudit\Administrator\Converter\ImageConverter;
use Gss\Component\Mediaaudit\Administrator\Converter\TargetNamer;
use Gss\Component\Mediaaudit\Administrator\Localizer\SourceRewriter;
use Gss\Component\Mediaaudit\Administrator\Scanner\ScanRunner;
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
 * php cli/joomla.php mediaaudit:convert
 */
final class ConvertCommand extends AbstractCommand
{
    protected static $defaultName = 'mediaaudit:convert';

    public function __construct(private DatabaseInterface $db, private ?DispatcherInterface $dispatcher = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_DESC'));
        $this->setHelp(Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_HELP'));

        $this->addOption('format', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_OPT_FORMAT'));
        $this->addOption('quality', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_OPT_QUALITY'));
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_OPT_LIMIT'), '0');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_OPT_DRY_RUN'));
        $this->addOption('convert-only', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_OPT_CONVERT_ONLY'));
        $this->addOption('rewrite-only', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_OPT_REWRITE_ONLY'));
        $this->addOption('revert', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_OPT_REVERT'));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $params = ComponentHelper::getParams('com_mediaaudit');
        $dryRun = (bool) $input->getOption('dry-run');
        $runner = ScanRunner::create($this->db, $params, JPATH_ROOT, $this->dispatcher, Factory::getApplication()->get('tmp_path'));

        $io->title(Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_TITLE') . ($dryRun ? ' — ' . Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_DRY_RUN') : ''));

        if ($input->getOption('revert')) {
            $stats = $runner->getRewriter()->revert($dryRun, [SourceRewriter::KIND_CONVERT]);

            if (!$dryRun) {
                $runner->refresh(false);
            }

            $io->success(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_REVERTED', $stats['reverted'], $stats['edited'], $stats['gone']));
            $io->note(Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_NOTE_FILES'));

            return Command::SUCCESS;
        }

        $format = strtolower((string) ($input->getOption('format') ?: $params->get('convert_format', '')));

        if (!\in_array($format, TargetNamer::FORMATS, true)) {
            $io->error(Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_ERR_FORMAT'));

            return Command::INVALID;
        }

        $encoders = $runner->getConverter()->getEncoder()->capabilities();

        foreach ($encoders as $name => $with) {
            $io->writeln(sprintf('%-5s %s', strtoupper($name), $with ? implode(', ', $with) : '-'));
        }

        $io->newLine();

        if (empty($encoders[$format])) {
            $io->error(Text::sprintf('PLG_CONSOLE_MEDIAAUDIT_CONVERT_ERR_ENCODER', strtoupper($format)));

            return Command::FAILURE;
        }

        $quality = max(1, min(100, (int) ($input->getOption('quality') ?: $params->get('convert_quality_' . $format, $format === 'webp' ? 82 : 60))));

        if (!$input->getOption('rewrite-only')) {
            $bar      = $io->createProgressBar();
            $failures = [];
            $stats    = $runner->getBatchConverter()->run(
                $format,
                $quality,
                max(0, min(90, (int) $params->get('convert_min_saving', 10))),
                max(0, (int) $input->getOption('limit')),
                0.0,
                [],
                0,
                max(1, (int) $params->get('convert_max_megapixels', 40)) * 1000000,
                $dryRun,
                static function (int $done, int $total, string $path, ?array $result) use ($bar, &$failures): void {
                    $bar->setMaxSteps($total);
                    $bar->setProgress($done);

                    if ($result !== null && $result['status'] === ImageConverter::FAILED) {
                        $failures[] = [$result['error'], mb_strlen($path) > 100 ? '…' . mb_substr($path, -99) : $path];
                    }
                }
            );

            $bar->finish();
            $io->newLine(2);

            $io->writeln(Text::sprintf(
                $dryRun ? 'PLG_CONSOLE_MEDIAAUDIT_CONVERT_WOULD_CONVERT' : 'PLG_CONSOLE_MEDIAAUDIT_CONVERT_CONVERTED',
                $stats['images'],
                $stats['converted'],
                $stats['not_smaller'],
                $stats['failed'],
                number_format($stats['bytes_before'] / 1048576, 1),
                number_format($stats['bytes_after'] / 1048576, 1),
                $stats['seconds'],
                strtoupper($format),
                $quality
            ));

            if ($failures) {
                $io->table([Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_ERROR'), Text::_('PLG_CONSOLE_MEDIAAUDIT_COL_PATH')], \array_slice($failures, 0, 30));
            }
        }

        // A dry run converts nothing: what would be rewritten after it cannot be told
        if (!$input->getOption('convert-only') && (!$dryRun || $input->getOption('rewrite-only'))) {
            // Every image that was converted, of this run and of the ones before
            $stats = $runner->getRewriter()->rewriteConverted($runner->getConverter()->getTargets($format), $dryRun);

            $io->writeln(Text::sprintf(
                $dryRun ? 'PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_WOULD_REWRITE' : 'PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_REWRITTEN',
                $stats['rewritten'],
                $stats['replaced'],
                $stats['unsupported']
            ));
        }

        if (!$dryRun) {
            $io->note(Text::_('PLG_CONSOLE_MEDIAAUDIT_CONVERT_NOTE_REVERT'));
        }

        return Command::SUCCESS;
    }
}
