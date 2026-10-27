<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\fieldlayoutelements;

use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use InvalidArgumentException;

/**
 * Suggested amounts, the minimum and whether donors may type their own amount. A donation without them has no
 * amount to take, so ProductTypes::ensureFieldLayout() puts this on every donation type's layout. It is not
 * mandatory in Craft's sense, which would add it to the shop's product types as well.
 */
class DonationSettingsField extends BaseNativeField
{
    public bool $mandatory = false;
    public string $attribute = 'donationSettings';

    public function attribute(): string
    {
        return 'donationSettings';
    }

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('small-change', 'Donation settings');
    }

    protected function selectorLabel(): string
    {
        return Craft::t('small-change', 'Donation settings');
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

        $currency = Plugin::getInstance()->getSettings()->currency;

        return Craft::$app->getView()->renderTemplate('small-change/products/_donation-settings', [
            'product' => $element,
            'currency' => $currency,
            'minimum' => $element->minimumAmount !== null ? Money::toDecimal($element->minimumAmount, $currency) : '',
            'suggested' => implode(', ', array_map(static fn(int $amount): string => Money::toDecimal($amount, $currency), $element->suggestedAmounts ?? [])),
            'static' => $static,
            'intervals' => $element->getAllowedIntervals(),
        ]);
    }
}
