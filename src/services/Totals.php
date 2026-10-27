<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\models\Totals as TotalsResult;
use craft\base\Component;

/**
 * Pure arithmetic in integer minor units. Nothing here reads the database,
 * Stripe or the request, so it can be exercised exhaustively in unit tests.
 */
class Totals extends Component
{
    public const DISCOUNT_PERCENT = 'percent';
    public const DISCOUNT_FIXED = 'fixed';

    /**
     * @param LineItem[] $lineItems
     * @param array{type: string, percent?: ?int, amount?: ?int, minSubtotal?: ?int}|null $discount `percent` is in basis points
     */
    public function calculate(array $lineItems, ?array $discount = null, int $shippingAmount = 0): TotalsResult
    {
        $itemSubtotal = 0;
        $discountable = 0;
        $giftAidEligible = 0;

        foreach ($lineItems as $item) {
            $subtotal = $item->unitPrice * $item->qty;
            $itemSubtotal += $subtotal;

            // Donations are never discounted.
            if (!$item->getIsDonation()) {
                $discountable += $subtotal;
            }

            if ($item->isGiftAidEligible) {
                $giftAidEligible += $subtotal;
            }
        }

        $discountAmount = $discount !== null ? $this->discountFor($discount, $discountable) : 0;
        $shippingAmount = max(0, $shippingAmount);

        return new TotalsResult(
            itemSubtotal: $itemSubtotal,
            discountAmount: $discountAmount,
            shippingAmount: $shippingAmount,
            total: $itemSubtotal - $discountAmount + $shippingAmount,
            giftAidEligibleAmount: $giftAidEligible,
            discountApplied: $discountAmount > 0,
        );
    }

    /**
     * @param array{type: string, percent?: ?int, amount?: ?int, minSubtotal?: ?int} $discount
     */
    private function discountFor(array $discount, int $discountable): int
    {
        if ($discountable <= 0) {
            return 0;
        }

        $minimum = $discount['minSubtotal'] ?? null;
        if ($minimum !== null && $discountable < $minimum) {
            return 0;
        }

        $amount = match ($discount['type']) {
            // Half-up rounding, so 12.5% of 999 is 125 rather than 124.
            self::DISCOUNT_PERCENT => intdiv($discountable * max(0, min(10000, (int) ($discount['percent'] ?? 0))) + 5000, 10000),
            self::DISCOUNT_FIXED => max(0, (int) ($discount['amount'] ?? 0)),
            default => 0,
        };

        return min($amount, $discountable);
    }
}
