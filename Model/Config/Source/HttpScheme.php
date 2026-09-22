<?php

declare(strict_types=1);

namespace BigBridge\QuickNav\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class HttpScheme implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'http', 'label' => __('HTTP')],
            ['value' => 'https', 'label' => __('HTTPS')],
        ];
    }
}
