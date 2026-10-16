<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Administrator\Extractor;

use Gss\Component\Mediaaudit\Administrator\Event\CollectExtractorsEvent;
use Gss\Component\Mediaaudit\Administrator\Service\ReferenceBuilder;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;

\defined('_JEXEC') or die;

/**
 * The extractors of the component, and the ones that plugins add.
 */
final class ExtractorRegistry
{
    /** @var array<string, ReferenceExtractorInterface>|null */
    private ?array $extractors = null;

    /**
     * @param  string  $root  Root of the site, for the extractors that read files.
     */
    public function __construct(
        private DatabaseInterface $db,
        private ReferenceBuilder $builder,
        private ?DispatcherInterface $dispatcher = null,
        private string $root = ''
    ) {
    }

    /**
     * @return  array<string, ReferenceExtractorInterface>  By type.
     */
    public function all(): array
    {
        if ($this->extractors !== null) {
            return $this->extractors;
        }

        $event = new CollectExtractorsEvent($this->builder, $this->db, $this->root);

        $tables = [
            ArticleExtractor::class,
            ModuleExtractor::class,
            CategoryExtractor::class,
            MenuExtractor::class,
            TagExtractor::class,
            ContactExtractor::class,
            BannerExtractor::class,
            TemplateStyleExtractor::class,
        ];

        foreach ($tables as $class) {
            $event->addExtractor(new $class($this->db, $this->builder));
        }

        foreach (array_keys(FieldExtractor::KINDS) as $context) {
            $event->addExtractor(new FieldExtractor($this->db, $this->builder, $context));
        }

        if ($this->root !== '') {
            $event->addExtractor(new TemplateCssExtractor($this->builder, $this->root));
        }

        if ($this->dispatcher !== null) {
            // A group of our own, next to the plugins that the application has loaded by itself
            PluginHelper::importPlugin('mediaaudit', null, true, $this->dispatcher);
            $this->dispatcher->dispatch(CollectExtractorsEvent::NAME, $event);
        }

        return $this->extractors = $event->getExtractors();
    }

    public function get(string $sourceType): ?ReferenceExtractorInterface
    {
        return $this->all()[$sourceType] ?? null;
    }

    /**
     * The extractors whose sources an event with this context is about.
     *
     * @return  array<string, ReferenceExtractorInterface&ContextAwareInterface>  By type
     */
    public function forContext(string $context): array
    {
        return array_filter(
            $this->all(),
            static fn (ReferenceExtractorInterface $extractor): bool => $extractor instanceof ContextAwareInterface
                && \in_array($context, $extractor->getContexts(), true)
        );
    }

    public function getRoot(): string
    {
        return $this->root;
    }
}
