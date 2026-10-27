<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\services\Totals;

final class TotalsTest extends Unit
{
    private Totals $totals;

    protected function _before(): void
    {
        $this->totals = new Totals();
    }

    public function testEmptyOrderIsZero(): void
    {
        $result = $this->totals->calculate([]);

        $this->assertSame(0, $result->itemSubtotal);
        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->giftAidEligibleAmount);
        $this->assertFalse($result->discountApplied);
    }

    public function testQuantityMultipliesUnitPrice(): void
    {
        $result = $this->totals->calculate([$this->product(1250, 3)]);

        $this->assertSame(3750, $result->itemSubtotal);
        $this->assertSame(3750, $result->total);
    }

    public function testDonationLinesAreGiftAidEligibleAndNothingElseIs(): void
    {
        $result = $this->totals->calculate([$this->donation(2500), $this->product(1000, 2)]);

        $this->assertSame(4500, $result->itemSubtotal);
        $this->assertSame(2500, $result->giftAidEligibleAmount);
    }

    public function testShippingIsAddedAfterDiscount(): void
    {
        $result = $this->totals->calculate(
            [$this->product(2000)],
            ['type' => Totals::DISCOUNT_FIXED, 'amount' => 500],
            350,
        );

        $this->assertSame(2000, $result->itemSubtotal);
        $this->assertSame(500, $result->discountAmount);
        $this->assertSame(350, $result->shippingAmount);
        $this->assertSame(1850, $result->total);
    }

    public function testNegativeShippingIsIgnored(): void
    {
        $this->assertSame(1000, $this->totals->calculate([$this->product(1000)], null, -500)->total);
    }

    /**
     * @dataProvider percentCases
     */
    public function testPercentDiscountRoundsHalfUp(int $unitPrice, int $basisPoints, int $expected): void
    {
        $result = $this->totals->calculate(
            [$this->product($unitPrice)],
            ['type' => Totals::DISCOUNT_PERCENT, 'percent' => $basisPoints],
        );

        $this->assertSame($expected, $result->discountAmount);
        $this->assertSame($unitPrice - $expected, $result->total);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function percentCases(): array
    {
        return [
            'ten percent' => [2000, 1000, 200],
            'twelve and a half percent rounds up' => [999, 1250, 125],
            'rounds down below half' => [1001, 1000, 100],
            'rounds up at exactly half' => [1005, 1000, 101],
            'one hundred percent' => [1234, 10000, 1234],
            'over one hundred percent is capped' => [1234, 25000, 1234],
            'zero percent' => [1234, 0, 0],
        ];
    }

    public function testFixedDiscountCannotExceedTheDiscountableSubtotal(): void
    {
        $result = $this->totals->calculate(
            [$this->product(300)],
            ['type' => Totals::DISCOUNT_FIXED, 'amount' => 5000],
        );

        $this->assertSame(300, $result->discountAmount);
        $this->assertSame(0, $result->total);
    }

    public function testDiscountsNeverTouchDonations(): void
    {
        $result = $this->totals->calculate(
            [$this->donation(5000), $this->product(1000)],
            ['type' => Totals::DISCOUNT_PERCENT, 'percent' => 10000],
        );

        $this->assertSame(1000, $result->discountAmount);
        $this->assertSame(5000, $result->total);
        $this->assertSame(5000, $result->giftAidEligibleAmount);
    }

    public function testDiscountOnADonationOnlyOrderIsZero(): void
    {
        $result = $this->totals->calculate(
            [$this->donation(5000)],
            ['type' => Totals::DISCOUNT_FIXED, 'amount' => 1000],
        );

        $this->assertSame(0, $result->discountAmount);
        $this->assertFalse($result->discountApplied);
    }

    public function testMinimumSubtotalIsMeasuredOnDiscountableLinesOnly(): void
    {
        $discount = ['type' => Totals::DISCOUNT_FIXED, 'amount' => 200, 'minSubtotal' => 2000];

        // The donation doesn't count towards the minimum.
        $below = $this->totals->calculate([$this->donation(10000), $this->product(1500)], $discount);
        $this->assertSame(0, $below->discountAmount);

        $met = $this->totals->calculate([$this->donation(10000), $this->product(2000)], $discount);
        $this->assertSame(200, $met->discountAmount);
        $this->assertTrue($met->discountApplied);
    }

    public function testUnknownDiscountTypeDoesNothing(): void
    {
        $this->assertSame(0, $this->totals->calculate([$this->product(1000)], ['type' => 'bogus', 'amount' => 500])->discountAmount);
    }

    public function testHashChangesWithAnyAmount(): void
    {
        $base = $this->totals->calculate([$this->product(1000)]);

        $this->assertSame($base->hash(), $this->totals->calculate([$this->product(1000)])->hash());
        $this->assertNotSame($base->hash(), $this->totals->calculate([$this->product(1001)])->hash());
        $this->assertNotSame($base->hash(), $this->totals->calculate([$this->product(1000)], null, 100)->hash());
        $this->assertNotSame($base->hash(), $this->totals->calculate([$this->donation(1000)])->hash());
    }

    private function donation(int $amount): LineItem
    {
        return new LineItem([
            'kind' => LineItem::KIND_DONATION,
            'description' => 'Donation',
            'unitPrice' => $amount,
            'qty' => 1,
            'isGiftAidEligible' => true,
        ]);
    }

    private function product(int $price, int $qty = 1): LineItem
    {
        return new LineItem([
            'kind' => LineItem::KIND_PHYSICAL,
            'description' => 'Thing',
            'unitPrice' => $price,
            'qty' => $qty,
        ]);
    }
}
