<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Controller;

use Gss\Component\Mediaaudit\Administrator\Service\CsvWriter;

\defined('_JEXEC') or die;

/**
 * The lists as CSV, with the filters that the list has on screen.
 */
class ExportController extends ActionController
{
    private const LISTS = ['images', 'inuse', 'orphans', 'semiorphans', 'broken', 'duplicates', 'quarantine', 'localized', 'rewrites'];

    public function csv(): void
    {
        $this->checkToken('get');

        $type = $this->input->getCmd('type', '');

        if (!\in_array($type, self::LISTS, true) || !$this->allowed('core.manage')) {
            $this->back();

            return;
        }

        $csv = self::toCsv($this->rows($type));

        $this->app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="mediaaudit-' . $type . '-' . gmdate('Ymd-His') . '.csv"', true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->sendHeaders();

        // The mark that tells Excel that the file is UTF-8
        echo "\xEF\xBB\xBF" . $csv;

        $this->app->close();
    }

    /**
     * @return  list<list<string|int|null>>  The first row holds the names of the columns.
     */
    public function rows(string $type = ''): array
    {
        // Every public method of a controller can be asked for as a task: this one has nothing to give then
        if (!\in_array($type, self::LISTS, true)) {
            return [];
        }

        $model = $this->getModel(ucfirst($type), 'Administrator');

        // Reads the filters of the list from the session, then asks for all the rows
        $model->getState('list.limit');
        $model->setState('list.limit', 0);
        $model->setState('list.start', 0);

        $items = $model->getItems() ?: [];
        $rows  = [];

        $sources = static fn (array $references): string => implode(' ', array_map(
            static fn (array $reference): string => $reference['type'] . ':' . $reference['id'] . ':' . $reference['field'],
            $references
        ));

        switch ($type) {
            case 'inuse':
            case 'orphans':
            case 'semiorphans':
                $rows[] = ['path', 'size', 'modified', 'sha1', 'references', 'published_references', 'referenced_in_another_case_as', 'sources'];

                foreach ($items as $item) {
                    $rows[] = [$item->path, (int) $item->size, $item->mtime, $item->sha1, (int) $item->refs, (int) $item->live_refs, $item->case_suspect, $sources($item->references)];
                }

                break;

            case 'images':
                $rows[] = ['path', 'type', 'size', 'modified', 'references', 'made_by_a_component', 'conversion', 'format', 'converted_file', 'converted_size', 'error', 'made_of'];

                foreach ($items as $item) {
                    $rows[] = [
                        $item->path, $item->type, (int) $item->size, $item->mtime, (int) $item->refs, $item->derived ? 1 : 0,
                        $item->stale ? null : $item->conversion, $item->format, $item->target_path,
                        $item->target_size === null ? null : (int) $item->target_size, $item->error, $item->made_of,
                    ];
                }

                break;

            case 'broken':
                $rows[] = ['target', 'http_status', 'error', 'references', 'published_references', 'file_in_another_case', 'in_quarantine', 'sources'];

                foreach ($items as $item) {
                    $rows[] = [
                        $item->target, $item->http_status, $item->error, (int) $item->refs, (int) $item->live_refs,
                        $item->case_suspect, $item->quarantined ? 1 : 0, $sources($item->references),
                    ];
                }

                break;

            case 'duplicates':
                $rows[] = ['sha1', 'size', 'copies', 'path', 'modified', 'references', 'published_references', 'made_by_a_component'];

                foreach ($items as $group) {
                    foreach ($group->files as $file) {
                        $rows[] = [
                            $group->sha1, (int) $group->size, (int) $group->copies, $file->path, $file->mtime, (int) $file->refs, (int) $file->live_refs,
                            empty($file->derived) ? 0 : 1,
                        ];
                    }
                }

                break;

            case 'localized':
                $rows[] = ['url', 'host', 'local_path', 'size', 'type', 'copied_at', 'http_status', 'error', 'file', 'used_by', 'still_external_in', 'sources'];

                foreach ($items as $item) {
                    $rows[] = [
                        $item->url, $item->host, $item->local_path, $item->size === null ? null : (int) $item->size, $item->mime, $item->fetched_at,
                        $item->http_status, $item->error, $item->status, \count($item->references), \count($item->external), $sources($item->references),
                    ];
                }

                break;

            case 'rewrites':
                $rows[] = ['id', 'source_type', 'source_id', 'title', 'field', 'kind', 'replaced', 'changed_at', 'changed_by', 'put_back_at'];

                foreach ($items as $item) {
                    $rows[] = [
                        (int) $item->id, $item->source_type, (int) $item->source_id, $item->title, $item->field, $item->kind, (int) $item->replaced,
                        $item->rewritten_at, $item->rewritten_by_name, $item->reverted_at,
                    ];
                }

                break;

            case 'quarantine':
                $rows[] = ['id', 'original_path', 'quarantine_path', 'sha1', 'moved_at', 'moved_by', 'restored_at', 'restored_by', 'deleted_at', 'deleted_by', 'note'];

                foreach ($items as $item) {
                    $rows[] = [
                        (int) $item->id, $item->original_path, $item->quarantine_path, $item->sha1, $item->moved_at,
                        $item->moved_by_name, $item->restored_at, $item->restored_by_name, $item->deleted_at, $item->deleted_by_name, $item->note,
                    ];
                }

                break;
        }

        return $rows;
    }

    /**
     * @param  list<list<string|int|null>>  $rows
     */
    public static function toCsv(array $rows = []): string
    {
        return CsvWriter::write($rows);
    }
}
