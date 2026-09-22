<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Search;

/**
 * The shared contract every provider returns and the widget renders uniformly.
 */
class SearchResult
{
    public function __construct(
        public readonly string $entityType,
        public readonly string $iconKey,
        public readonly string $label,
        public readonly string $context,
        public readonly string $url,
        public readonly int $matchTier,
        public readonly bool $matchedFromPastedLink = false,
        /** Count of assigned products, when applicable. */
        public readonly ?int $productCount = null,
        /**
         * An optional second action alongside the primary `url`, e.g. "View products" next to
         * "Edit category". Generic rather than category-specific so other entity types can offer
         * one without changing this contract.
         */
        public readonly ?string $secondaryActionUrl = null,
        public readonly ?string $secondaryActionLabel = null,
        /**
         * 'default'|'website'|'store', or null when scope awareness does not apply to this
         * entity type. Never 'default' alongside a non-empty `scopeChoices`: the picker renders
         * only for 'website' and 'store'.
         */
        public readonly ?string $scopable = null,
        /**
         * Additive data for the scope picker, never a replacement for `url`. Built with the same
         * URL-building call as `url`, so the unscoped default stays byte-for-byte identical.
         *
         * @var array<int, array{type: string, id: ?int, websiteId: ?int, label: string, url: string}>|null
         */
        public readonly ?array $scopeChoices = null,
        /**
         * The original uploaded media file, not a resized variant: resizing would mean loading a
         * full product model and running the image pipeline on every keystroke. Display size is
         * constrained with CSS instead.
         */
        public readonly ?string $imageUrl = null,
        /**
         * One entry per store view the entity is actually live in — an availability check, which
         * is stricter than `scopable`. Distinct from `scopeChoices`: that picker is for editing
         * in a scope, this is for viewing the public page.
         *
         * @var array<int, array{storeId: int, label: string, url: string}>|null
         */
        public readonly ?array $storefrontChoices = null,
    ) {}

    /**
     * @return array{id: string, type: string, icon: string, label: string, context: string, url: string, matchedFromPastedLink: bool, productCount: int|null, secondaryActionUrl: string|null, secondaryActionLabel: string|null, scopable: string|null, scopeChoices: array[]|null, imageUrl: string|null, storefrontChoices: array[]|null}
     */
    public function toArray(): array
    {
        return [
            // label and context must stay in this hash. Every config_field in one settings group
            // shares its parent group's URL, since system_config/edit cannot deep link a single
            // field, so entityType+url alone yields the same id for different fields. Duplicate
            // ids break the widget's `x-for :key`, which crashes Alpine and renders nothing.
            'id' => hash('sha256', $this->entityType.'|'.$this->url.'|'.$this->label.'|'.$this->context),
            'type' => $this->entityType,
            'icon' => $this->iconKey,
            'label' => $this->label,
            'context' => $this->context,
            'url' => $this->url,
            'matchedFromPastedLink' => $this->matchedFromPastedLink,
            'productCount' => $this->productCount,
            'secondaryActionUrl' => $this->secondaryActionUrl,
            'secondaryActionLabel' => $this->secondaryActionLabel,
            'scopable' => $this->scopable,
            'scopeChoices' => $this->scopeChoices,
            'imageUrl' => $this->imageUrl,
            'storefrontChoices' => $this->storefrontChoices,
        ];
    }
}
