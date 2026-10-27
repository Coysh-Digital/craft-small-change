<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TextField;

/**
 * An optional stock-keeping code. Required when the product type says so.
 */
class ProductSkuField extends TextField
{
    public string $attribute = 'sku';
    public ?int $maxlength = 100;
    public bool $mandatory = false;

    public function attribute(): string
    {
        return 'sku';
    }

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('small-change', 'SKU');
    }
}
