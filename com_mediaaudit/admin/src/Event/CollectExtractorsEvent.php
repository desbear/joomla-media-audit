<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Event;

use Gss\Component\Mediaaudit\Administrator\Extractor\ReferenceExtractorInterface;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\Event\Event;

\defined('_JEXEC') or die;

/**
 * The onMediaAuditCollectExtractors event. Plugins answer with extractors for their own content.
 */
final class CollectExtractorsEvent extends Event
{
    public const NAME = 'onMediaAuditCollectExtractors';

    /** @var array<string, ReferenceExtractorInterface> */
    private array $extractors = [];

    public function __construct(private ReferenceBuilder $builder, private ?\Joomla\Database\DatabaseInterface $db = null, private string $root = '')
    {
        parent::__construct(self::NAME);
    }

    public function getDatabase(): ?\Joomla\Database\DatabaseInterface
    {
        return $this->db;
    }

    /**
     * Root of the site, for extractors that look for files.
     */
    public function getRoot(): string
    {
        return $this->root;
    }

    /**
     * An extractor with the type of one that is there already replaces it.
     */
    public function addExtractor(ReferenceExtractorInterface $extractor): void
    {
        $this->extractors[$extractor->getSourceType()] = $extractor;
    }

    /**
     * What extractors need to read HTML, JSON and style sheets the way the built-in ones do.
     */
    public function getReferenceBuilder(): ReferenceBuilder
    {
        return $this->builder;
    }

    /**
     * @return  array<string, ReferenceExtractorInterface>
     */
    public function getExtractors(): array
    {
        return $this->extractors;
    }
}
