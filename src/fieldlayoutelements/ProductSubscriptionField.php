<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\fieldlayoutelements;

use coyshdigital\smallchange\elements\Product;
use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use InvalidArgumentException;

/**
 * How often a fixed-price product is billed and how many payments it takes, so it can be sold as a subscription.
 * Offered in the layout designer for product types with no delivery; a layout without it sells its products once.
 */
class ProductSubscriptionField extends BaseNativeField
{
    /**
     * Not mandatory in Craft's sense: that would put it on every product layout, donation types included.
     */
    public bool $mandatory = false;
    public string $attribute = 'subscription';

    public function attribute(): string
    {
        return 'subscription';
    }

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('small-change', 'Subscription');
    }

    protected function selectorLabel(): string
    {
        return Craft::t('small-change', 'Subscription');
    }

    protected function showLabel(): bool
    {
        return false;
    }

    protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        if (!$element instanceof Product) {
            throw new InvalidArgumentException(sprintf('%s can only be used in product field layouts.', self::class));
        }

        return Craft::$app->getView()->renderTemplate('small-change/products/_subscription-settings', [
            'product' => $element,
            'static' => $static,
        ]);
    }
}
