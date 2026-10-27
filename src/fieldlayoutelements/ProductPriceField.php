<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\fieldlayoutelements;

use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;
use InvalidArgumentException;

/**
 * The price of a physical or non-physical product, entered in the store currency and kept in minor units.
 */
class ProductPriceField extends BaseNativeField
{
    /**
     * Not mandatory in Craft's sense: that would put a price on every product layout, donation types included.
     * ProductTypes::ensureFieldLayout() adds it to the types that sell at a fixed price.
     */
    public bool $mandatory = false;
    public string $attribute = 'price';

    public function attribute(): string
    {
        return 'price';
    }

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('small-change', 'Price');
    }

    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('small-change', 'In {currency}, including any tax you charge.', ['currency' => Plugin::getInstance()->getSettings()->currency]);
    }

    protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        if (!$element instanceof Product) {
            throw new InvalidArgumentException(sprintf('%s can only be used in product field layouts.', self::class));
        }

        $currency = Plugin::getInstance()->getSettings()->currency;

        return Cp::textHtml([
            'id' => $this->id(),
            // The element holds minor units; the form speaks decimals.
            'name' => 'priceInput',
            'value' => $element->price !== null ? Money::toDecimal($element->price, $currency) : '',
            'size' => 10,
            'inputmode' => 'decimal',
            'autocomplete' => false,
            'disabled' => $static,
        ]);
    }
}
