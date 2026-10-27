<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

/**
 * The result of a totals calculation: plain integers in minor units.
 */
final class Totals
{
    public function __construct(
        public readonly int $itemSubtotal,
        public readonly int $discountAmount,
        public readonly int $shippingAmount,
        public readonly int $total,
        public readonly int $giftAidEligibleAmount,
        public readonly bool $discountApplied,
    ) {
    }

    /**
     * Identifies a set of totals, so a Checkout Session is only reused for the amounts it was made for.
     */
    public function hash(): string
    {
        return substr(hash('sha256', implode('|', [$this->itemSubtotal, $this->discountAmount, $this->shippingAmount, $this->total, $this->giftAidEligibleAmount])), 0, 12);
    }
}
