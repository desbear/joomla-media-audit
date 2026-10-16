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
 * php cli/joomla.php mediaaudit:remove-lost
 */
final class RemoveLostCommand extends AbstractCommand
{
    protected static $defaultName = 'mediaaudit:remove-lost';

    public function __construct(private DatabaseInterface $db, private ?DispatcherInterface $dispatcher = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(Text::_('PLG_CONSOLE_MEDIAAUDIT_REMOVE_DESC'));
        $this->setHelp(Text::_('PLG_CONSOLE_MEDIAAUDIT_REMOVE_HELP'));

        $this->addOption('host', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, Text::_('PLG_CONSOLE_MEDIAAUDIT_REMOVE_OPT_HOST'));
        $this->addOption('text', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_REMOVE_OPT_TEXT'));
        $this->addOption('no-recheck', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_REMOVE_OPT_NO_RECHECK'));
        $this->addOption('delay', null, InputOption::VALUE_REQUIRED, Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_OPT_DELAY'), '200');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_OPT_DRY_RUN'));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $hosts  = array_values(array_unique(array_filter(array_map(
            static fn (string $host): string => strtolower(preg_replace('#^[a-z]+://|/.*$#i', '', trim($host))),
            (array) $input->getOption('host')
        ))));

        $io->title(Text::_('PLG_CONSOLE_MEDIAAUDIT_REMOVE_TITLE') . ($dryRun ? ' — ' . Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_DRY_RUN') : ''));

        // Never for every host at once: "*" is not a host
        if (!$hosts || \in_array('*', $hosts, true)) {
            $io->error(Text::_('PLG_CONSOLE_MEDIAAUDIT_REMOVE_ERR_HOST'));

            return Command::INVALID;
        }

        $params = ComponentHelper::getParams('com_mediaaudit');
        $text   = trim((string) ($input->getOption('text') ?: $params->get('missing_image_text', ''))) ?: Text::_('PLG_CONSOLE_MEDIAAUDIT_REMOVE_TEXT');
        $runner = ScanRunner::create($this->db, $params, JPATH_ROOT, $this->dispatcher, Factory::getApplication()->get('tmp_path'));

        if (!$input->getOption('no-recheck')) {
            if (!HttpFetcher::isAvailable()) {
                $io->error(Text::_('PLG_CONSOLE_MEDIAAUDIT_CHECK_ERR_CURL'));

                return Command::FAILURE;
            }

            // An image that did not answer last week may answer today
            $checked = $runner->getExternalChecker()->check($hosts, 0, 0, 5, 10, max(0, (int) $input->getOption('delay')));

            $io->writeln(Text::sprintf(
                'PLG_CONSOLE_MEDIAAUDIT_REMOVE_RECHECKED',
                implode(', ', $hosts),
                $checked['checked'],
                $checked['ok'],
                $checked['broken'],
                $checked['unreachable']
            ));
            $io->newLine();
        }

        $stats = $runner->getRewriter()->removeLost($hosts, $text, $dryRun);

        if (!$dryRun) {
            $runner->refresh(false);
        }

        $io->success(Text::sprintf(
            $dryRun ? 'PLG_CONSOLE_MEDIAAUDIT_REMOVE_WOULD' : 'PLG_CONSOLE_MEDIAAUDIT_REMOVE_DONE',
            $stats['lost'],
            $stats['replaced'],
            $stats['rewritten'],
            $text
        ));

        if (!$dryRun && $stats['replaced'] > 0) {
            $io->note(Text::_('PLG_CONSOLE_MEDIAAUDIT_LOCALIZE_NOTE_REVERT'));
        }

        return Command::SUCCESS;
    }
}
