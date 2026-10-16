<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mcp_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Mediaaudit\Tool;

use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Gss\Component\Mediaaudit\Administrator\Service\AuditFacade;

\defined('_JEXEC') or die;

/**
 * The tools of Media Audit. They answer what the web services of the component answer.
 */
final class MediaAuditTools
{
    private const GROUP = 'mediaaudit';

    /**
     * @return Tool[]
     */
    public static function tools(): array
    {
        $read       = static fn (ToolContext $c): bool => (bool) $c->user->authorise('core.manage', 'com_mediaaudit');
        $quarantine = static fn (ToolContext $c): bool => $read($c) && (bool) $c->user->authorise('mediaaudit.quarantine', 'com_mediaaudit');

        return [
            new Tool(
                'media_audit_report',
                'Audit of the images below images/ against the content of the site. Without "type": the numbers of every class, when the last scan was, '
                . 'and the other sites that images are loaded from; a good first call. With "type": the list of that class. '
                . 'orphan = no content refers to the file; semi = only unpublished, archived or trashed content refers to it; '
                . 'broken = content refers to a file that is not there (kind=external: to an image of another site that does not answer); '
                . 'duplicate = files with the same content; quarantine = files that were moved out of the way; '
                . 'localized = images of other sites that were copied to this site, with what uses them (state=failed: the ones that could not be copied); '
                . 'rewrites = the content that was changed to load the copies, or to say that an image is lost. '
                . 'Only references in the content of this site are known: a file that is linked from elsewhere looks like an orphan.',
                [
                    'properties' => [
                        'type'        => ['type' => 'string', 'enum' => ['orphan', 'semi', 'broken', 'duplicate', 'quarantine', 'localized', 'rewrites'], 'description' => 'Leave out for the summary.'],
                        'search'      => ['type' => 'string', 'description' => 'Part of the path, e.g. a folder: "images/banners/".'],
                        'source_type' => ['type' => 'string', 'description' => 'Only what is referred to by this kind of source: article, module, category, menu, flexicontent, easyblog, ...'],
                        'kind'        => ['type' => 'string', 'enum' => ['local', 'external'], 'description' => 'For type=broken. Default local.'],
                        'state'       => ['type' => 'string', 'enum' => ['held', 'restored', 'all', 'copied', 'failed', 'kept', 'reverted'], 'description' => 'For type=quarantine: held (default), restored, all. For type=localized: copied (default), failed. For type=rewrites: kept (default), reverted, all.'],
                        'host'        => ['type' => 'string', 'description' => 'For type=localized: only images of this host.'],
                        'include_derived' => ['type' => 'boolean', 'description' => 'For type=duplicate: also count the thumbnails and sizes that FLEXIcontent or EasyBlog made of an image. They often have the same content by design; default false.'],
                        'limit'       => ['type' => 'integer', 'description' => 'Default 50.'],
                        'offset'      => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'report'],
                'Media audit report',
                Tool::SCOPE_READ,
                self::GROUP,
                false,
                $read
            ),
            new Tool(
                'media_audit_refs',
                'What refers to an image: the articles, modules and other sources, with their titles, fields, states and edit links. '
                . 'Takes the path of a file ("images/banners/a.jpg") or the address of an external image. Also tells the class of the file, '
                . 'and paths that differ by capitals only, which is a common reason for a broken image on a Linux server.',
                [
                    'properties'           => ['path' => ['type' => 'string', 'description' => 'As it is written in the content.']],
                    'required'             => ['path'],
                    'additionalProperties' => false,
                ],
                [self::class, 'refs'],
                'References to an image',
                Tool::SCOPE_READ,
                self::GROUP,
                false,
                $read
            ),
            new Tool(
                'media_quarantine',
                'Move images out of the way, to images/_mediaaudit_quarantine/, where visitors do not get them. Nothing is deleted, and '
                . 'media_restore puts a file back. A file that content refers to is refused, and so is every file when the last scan is older '
                . 'than the site allows: the answer names the reason for every file that was not moved. Check with media_audit_report first.',
                [
                    'properties' => [
                        'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Paths relative to the root of the site, e.g. "images/old/a.jpg".'],
                        'note'  => ['type' => 'string', 'description' => 'Why, for the record of the quarantine.'],
                    ],
                    'required'             => ['paths'],
                    'additionalProperties' => false,
                ],
                [self::class, 'quarantine'],
                'Move images to the quarantine',
                Tool::SCOPE_DELETE,
                self::GROUP,
                false,
                $quarantine
            ),
            new Tool(
                'media_restore',
                'Put images back from the quarantine to where they were. Takes the ids of the records of the quarantine, which '
                . 'media_audit_report with type=quarantine lists. Refused when another file has taken the place.',
                [
                    'properties'           => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                    'required'             => ['ids'],
                    'additionalProperties' => false,
                ],
                [self::class, 'restore'],
                'Put images back from the quarantine',
                Tool::SCOPE_WRITE,
                self::GROUP,
                true,
                $quarantine
            ),
        ];
    }

    public static function report(array $args, ToolContext $ctx): array
    {
        return self::ask($ctx, static function (AuditFacade $audit) use ($args, $ctx): array {
            if (empty($args['type'])) {
                return $audit->summary();
            }

            return $audit->report((string) $args['type'], ['limit' => $ctx->limit($args['limit'] ?? null, 50)] + $args);
        });
    }

    public static function refs(array $args, ToolContext $ctx): array
    {
        return self::ask($ctx, static fn (AuditFacade $audit): array => $audit->references((string) ($args['path'] ?? '')));
    }

    public static function quarantine(array $args, ToolContext $ctx): array
    {
        return self::ask($ctx, static fn (AuditFacade $audit): array => $audit->quarantine((array) ($args['paths'] ?? []), (string) ($args['note'] ?? '')));
    }

    public static function restore(array $args, ToolContext $ctx): array
    {
        return self::ask($ctx, static fn (AuditFacade $audit): array => $audit->restore((array) ($args['ids'] ?? [])));
    }

    /**
     * @param  callable(AuditFacade): array  $work
     */
    private static function ask(ToolContext $ctx, callable $work): array
    {
        try {
            return $work(new AuditFacade($ctx->app, $ctx->db, $ctx->user));
        } catch (\InvalidArgumentException $e) {
            throw new ToolException($e->getMessage());
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 403) {
                throw new ToolException($e->getMessage());
            }

            throw $e;
        }
    }
}
