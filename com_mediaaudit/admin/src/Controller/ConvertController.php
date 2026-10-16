<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Controller;

use Gss\Component\Mediaaudit\Administrator\Converter\TargetNamer;
use Gss\Component\Mediaaudit\Administrator\Helper\HtmlHelper;
use Gss\Component\Mediaaudit\Administrator\Helper\WaitDialog;
use Gss\Component\Mediaaudit\Administrator\Service\ProgressStore;
use Gss\Component\Mediaaudit\Administrator\View\Images\HtmlView;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Converts images from the list of all images, a batch at a time, and makes the content load the
 * converted files.
 *
 * A request of a browser cannot go on for as long as thousands of images take. A batch is so many
 * images as the page asks for, and ends early when it takes too long; the answer tells how many
 * images are left.
 */
class ConvertController extends ActionController
{
    /** Seconds that the images of one batch may take */
    private const SECONDS = 25.0;

    /**
     * The next images that content refers to, the largest first.
     */
    public function run(): void
    {
        $this->convert([]);
    }

    /**
     * The images that are selected in the list.
     */
    public function selected(): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'int'))));

        $this->checkToken();
        $this->back('images');

        if (!$ids) {
            $this->app->enqueueMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');

            return;
        }

        $this->convert($ids);
    }

    /**
     * @param  int[]  $ids  Files of the index. Empty for the next ones that wait.
     */
    private function convert(array $ids): void
    {
        $this->checkToken();
        $this->back('images');

        if (!$this->allowed('mediaaudit.convert')) {
            return;
        }

        $params = ComponentHelper::getParams('com_mediaaudit');
        $format = (string) $params->get('convert_format', '');

        if (!\in_array($format, TargetNamer::FORMATS, true)) {
            $this->app->enqueueMessage(Text::_('COM_MEDIAAUDIT_CONVERT_NO_FORMAT'), 'warning');

            return;
        }

        $runner = $this->runner();

        if (!$runner->getConverter()->getEncoder()->can($format)) {
            $this->app->enqueueMessage(Text::sprintf('COM_MEDIAAUDIT_CONVERT_CANNOT', strtoupper($format)), 'error');

            return;
        }

        $limit    = max(1, min(HtmlView::MOST, $this->input->getInt('convert_limit', (int) $params->get('convert_batch', 20))));
        $token    = (string) $this->input->getCmd(WaitDialog::FIELD, '');
        $progress = new ProgressStore((string) $this->app->get('tmp_path', sys_get_temp_dir()));

        try {
            if (\function_exists('set_time_limit')) {
                @set_time_limit(180);
            }

            $userId = (int) $this->app->getIdentity()->id;
            $batch  = $runner->getBatchConverter()->run(
                $format,
                max(1, min(100, (int) $params->get('convert_quality_' . $format, $format === 'webp' ? 82 : 60))),
                max(0, min(90, (int) $params->get('convert_min_saving', 10))),
                $limit,
                self::SECONDS,
                $ids,
                $userId,
                max(1, (int) $params->get('convert_max_megapixels', 40)) * 1000000,
                false,
                static function (int $done, int $total, string $path) use ($progress, $token): void {
                    $progress->write($token, ['stage' => 'convert', 'done' => $done, 'total' => $total, 'current' => basename($path)]);
                }
            );

            $progress->write($token, ['stage' => 'rewrite', 'done' => 0, 'total' => 0, 'current' => '']);

            // Of this batch and of what was converted before: an image that was converted when an
            // article was saved can be in other articles, which nothing has rewritten yet
            $rewritten = $runner->getRewriter()->rewriteConverted($runner->getConverter()->getTargets($format) + $batch['targets'], false, $userId);

            if ($batch['converted'] === 0 && $rewritten['rewritten'] > 0) {
                $this->app->enqueueMessage(Text::sprintf(
                    'COM_MEDIAAUDIT_CONVERT_REWRITTEN',
                    number_format($rewritten['rewritten']),
                    number_format($rewritten['replaced'])
                ), 'message');
            }

            if ($batch['converted'] > 0) {
                $this->app->enqueueMessage(Text::sprintf(
                    'COM_MEDIAAUDIT_CONVERT_DONE',
                    number_format($batch['converted']),
                    strtoupper($format),
                    HtmlHelper::bytes($batch['bytes_before']),
                    HtmlHelper::bytes($batch['bytes_after']),
                    $batch['bytes_before'] > 0 ? (string) round(100 - $batch['bytes_after'] / $batch['bytes_before'] * 100) : '0'
                ), 'message');

                $this->app->enqueueMessage(Text::sprintf(
                    'COM_MEDIAAUDIT_CONVERT_REWRITTEN',
                    number_format($rewritten['rewritten']),
                    number_format($rewritten['replaced'])
                ), 'message');
            }

            if ($rewritten['unsupported'] > 0) {
                $this->app->enqueueMessage(Text::sprintf('COM_MEDIAAUDIT_CONVERT_UNSUPPORTED', number_format($rewritten['unsupported'])), 'notice');
            }

            if ($batch['not_smaller'] > 0) {
                $this->app->enqueueMessage(Text::sprintf('COM_MEDIAAUDIT_CONVERT_NOT_SMALLER', number_format($batch['not_smaller'])), 'notice');
            }

            if ($batch['failed'] > 0) {
                $this->app->enqueueMessage(Text::sprintf('COM_MEDIAAUDIT_CONVERT_SOME_FAILED', number_format($batch['failed'])), 'warning');
            }

            if ($batch['images'] === 0) {
                $this->app->enqueueMessage(Text::_($ids ? 'COM_MEDIAAUDIT_CONVERT_NONE_OF_SELECTED' : 'COM_MEDIAAUDIT_CONVERT_NOTHING_LEFT'), 'notice');
            } elseif ($batch['left'] > 0) {
                $this->app->enqueueMessage(Text::sprintf($ids ? 'COM_MEDIAAUDIT_CONVERT_LEFT_SELECTED' : 'COM_MEDIAAUDIT_CONVERT_LEFT', number_format($batch['left'])), 'notice');
            }
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::sprintf('COM_MEDIAAUDIT_CONVERT_FAILED', htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')), 'error');
        } finally {
            $progress->clear($token);
        }
    }
}
