<?php

/**
 * @package     Media Audit
 * @subpackage  plg_mediaaudit_flexicontent
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mediaaudit\Flexicontent\Extractor;

\defined('_JEXEC') or die;

/**
 * Where FLEXIcontent keeps the files of a value of an image field.
 *
 * Follows plgFlexicontent_fieldsImage::getThumbPaths(). The value only holds the name of the file;
 * the folders come from the settings of the field and of the component.
 */
final class ImagePaths
{
    public const SIZES = ['l', 'm', 's', 'b'];

    private const DEFAULT_FOLDER  = 'images/stories/flexicontent';
    private const DEFAULT_UPLOADS = 'components/com_flexicontent/uploads';
    private const DEFAULT_MEDIAS  = 'components/com_flexicontent/medias';

    /**
     * @param   array<string, mixed>  $field      Settings of the field.
     * @param   array<string, mixed>  $component  Settings of com_flexicontent.
     * @param   int                   $itemId     Of the item, or of the item that it translates when
     *                                            the field shares its files among the translations.
     * @param   string                $name       "originalname" of the value. Can hold folders.
     *
     * @return  array{original: string|null, thumbnails: string[]}  Relative to the root of the site.
     *          The original is null in the modes that have none of their own.
     */
    public static function of(array $field, int $fieldId, array $component, int $itemId, string $name): array
    {
        $name = trim(str_replace('\\', '/', $name), '/ ');

        if ($name === '' || preg_match('#(^|/)\.\.(/|$)#', $name)) {
            return ['original' => null, 'thumbnails' => []];
        }

        $source = (int) ($field['image_source'] ?? 0);
        $folder = trim(str_replace('\\', '/', (string) ($field['dir'] ?? '')), '/ ') ?: self::DEFAULT_FOLDER;
        $file   = basename($name);
        $sub    = \dirname($name) === '.' ? '' : \dirname($name) . '/';
        $prefix = '';

        if ($source === -2) {
            // Media manager: the value is the path of the file
            $original = $name;
            $thumbs   = $folder . '/mediaman/';
        } elseif ($source === -1) {
            // The intro and full images of the article, which the article extractor reads
            $original = null;
            $thumbs   = $folder . '/intro_full/';
        } elseif ($source >= 1) {
            // A folder for every item and field
            $thumbs   = $folder . '/item_' . $itemId . '_field_' . $fieldId . '/';
            $original = $thumbs . 'original/' . $name;
        } else {
            // All the files in the folder of the component
            $uploads  = (int) ($field['target_dir'] ?? 1)
                ? ($component['file_path'] ?? self::DEFAULT_UPLOADS)
                : ($component['media_path'] ?? self::DEFAULT_MEDIAS);
            $original = trim(str_replace('\\', '/', (string) $uploads), '/ ') . '/' . $name;
            $thumbs   = $folder . '/';

            // Fields that share their files tell their thumbnails apart by the id of the field
            if (($field['list_all_media_files'] ?? 0) && (int) ($field['unique_thumb_method'] ?? 0) === 0) {
                $prefix = 'fld' . $fieldId . '_';
            }
        }

        $thumbnails = [];

        foreach (self::SIZES as $size) {
            $thumbnails[] = $thumbs . $sub . $size . '_' . $prefix . $file;
        }

        return ['original' => $original, 'thumbnails' => $thumbnails];
    }

    /**
     * The files of the image that a field shows for items that have none of their own.
     *
     * The thumbnails are in the folder of the field, whatever the way to keep files is, and carry
     * the id of the field, whether the field shares its files or not.
     *
     * @param   array<string, mixed>  $field  Settings of the field.
     * @param   string                $image  "default_image" of the settings: a path of the site.
     *
     * @return  array{original: string|null, thumbnails: string[]}
     */
    public static function ofDefault(array $field, int $fieldId, string $image): array
    {
        $image = trim(str_replace('\\', '/', $image), '/ ');

        if ($image === '' || preg_match('#(^|/)\.\.(/|$)#', $image)) {
            return ['original' => null, 'thumbnails' => []];
        }

        $folder     = trim(str_replace('\\', '/', (string) ($field['dir'] ?? '')), '/ ') ?: self::DEFAULT_FOLDER;
        $thumbnails = [];

        foreach (self::SIZES as $size) {
            $thumbnails[] = $folder . '/' . $size . '_fld' . $fieldId . '_' . basename($image);
        }

        return ['original' => $image, 'thumbnails' => $thumbnails];
    }
}
