<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OpenSearchConnectionSource implements OptionSourceInterface
{
    public const CUSTOM = 'custom';

    public const PLATFORM = 'platform';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::CUSTOM, 'label' => __('Custom')],
            ['value' => self::PLATFORM, 'label' => __("Use Magento's configured OpenSearch/Elasticsearch")],
        ];
    }
}
