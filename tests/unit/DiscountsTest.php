<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\DiscountReservationRecord;
use coyshdigital\smallchange\tests\Fixtures;
use craft\helpers\Db;
use DateTime;

final class DiscountsTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
    }

    public function testCodesAreStoredUpperCaseAndMatchedAnyCase(): void
    {
        $this->makeDiscount('spring-sale');

        $this->assertSame('SPRING-SALE', Plugin::getInstance()->discounts->getDiscountByCode(' Spring-Sale ')?->code);
        $this->assertNull(Plugin::getInstance()->discounts->getDiscountByCode('nope'));
    }

    public function testCodesMustBeUnique(): void
    {
        $this->makeDiscount('ONCE');
        $again = new Discount(['name' => 'Again', 'code' => 'once', 'type' => Discount::TYPE_FIXED, 'amount' => 100]);

        $this->assertFalse($again->validate(['code']));
    }

    /**
     * @dataProvider invalidDiscounts
     * @param array<string, mixed> $attributes
     */
    public function testInvalidDiscountsAreRefused(array $attributes, string $field): void
    {
        $discount = new Discount($attributes + ['name' => 'X', 'code' => 'X1', 'type' => Discount::TYPE_PERCENT, 'percent' => 1000]);

        $this->assertFalse($discount->validate(), json_encode($discount->getErrors()) ?: '');
        $this->assertTrue($discount->hasErrors($field), $field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDiscounts(): array
    {
        return [
            'zero percent' => [['percent' => 0], 'percent'],
            'over a hundred percent' => [['percent' => 10001], 'percent'],
            'percent missing' => [['percent' => null], 'percent'],
            'fixed without an amount' => [['type' => Discount::TYPE_FIXED, 'percent' => null, 'amount' => null], 'amount'],
            'negative fixed amount' => [['type' => Discount::TYPE_FIXED, 'percent' => null, 'amount' => -5], 'amount'],
            'zero uses' => [['maxUses' => 0], 'maxUses'],
            'bad characters in the code' => [['code' => 'HAS SPACE'], 'code'],
            'ends before it starts' => [['dateFrom' => new DateTime('+2 days'), 'dateTo' => new DateTime('+1 day')], 'dateTo'],
        ];
    }

    public function testAValidCodeHasNoProblem(): void
    {
        $discount = $this->makeDiscount();
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $this->assertNull(Plugin::getInstance()->discounts->problemWith($discount, $cart));
    }

    public function testDisabledNotYetStartedAndExpiredCodesAllLookInvalid(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $service = Plugin::getInstance()->discounts;

        $disabled = $this->makeDiscount('OFF');
        $disabled->enabled = false;
        $future = $this->makeDiscount('SOON');
        $future->dateFrom = new DateTime('+1 day');
        $past = $this->makeDiscount('GONE');
        $past->dateTo = new DateTime('-1 day');

        foreach ([$disabled, $future, $past] as $discount) {
            $this->assertSame('That code is not valid.', $service->problemWith($discount, $cart), $discount->code);
        }
    }

    public function testACodeNeedsSomethingToDiscount(): void
    {
        $discount = $this->makeDiscount();
        $donationOnly = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '25', 'a@example.test', false, null, null);

        $this->assertStringContainsString('does not apply', (string) Plugin::getInstance()->discounts->problemWith($discount, $donationOnly));
    }

    public function testTheMinimumCountsProductsNotDonations(): void
    {
        $discount = $this->makeDiscount('BIG', Discount::TYPE_FIXED, 500, null, 2000);
        $service = Plugin::getInstance()->discounts;
        $carts = Plugin::getInstance()->carts;

        $cart = $this->makeCart([[$this->makeShopProduct(1500), 1]]);
        $carts->addProduct($cart, $this->makeProduct(), 1, '100');

        $this->assertStringContainsString('at least £20', (string) $service->problemWith($discount, $cart), 'a £100 donation does not make up the shortfall');

        $carts->addProduct($cart, $this->makeShopProduct(1000, 'physical', 'Another'), 1);
        $this->assertNull($service->problemWith($discount, $cart));
    }

    // Usage limits -----------------------------------------------------------

    public function testTheLastUseCanBeHeldByOnlyOneOrder(): void
    {
        $discount = $this->makeDiscount('LAST', Discount::TYPE_PERCENT, 1000, 1);
        $service = Plugin::getInstance()->discounts;
        $first = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $second = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $this->assertTrue($service->reserve($discount, $first));
        $this->assertFalse($service->reserve($discount, $second), 'the one use is already held');
        $this->assertTrue($service->reserve($discount, $first), 'holding it again is harmless');
    }

    public function testHoldingAUseTwiceForTheSameOrderCountsOnce(): void
    {
        $discount = $this->makeDiscount('TWICE', Discount::TYPE_PERCENT, 1000, 2);
        $service = Plugin::getInstance()->discounts;
        $order = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $service->reserve($discount, $order);
        $service->reserve($discount, $order);

        $this->assertSame(1, $service->usesTaken($discount));
        $this->assertSame(1, (int) DiscountReservationRecord::find()->where(['orderId' => $order->id])->count());
    }

    public function testReleasingGivesTheUseBack(): void
    {
        $discount = $this->makeDiscount('GIVE', Discount::TYPE_PERCENT, 1000, 1);
        $service = Plugin::getInstance()->discounts;
        $first = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $second = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $service->reserve($discount, $first);
        $service->release($first);

        $this->assertTrue($service->reserve($discount, $second));
    }

    public function testAnExpiredReservationIsIgnored(): void
    {
        $discount = $this->makeDiscount('STALE', Discount::TYPE_PERCENT, 1000, 1);
        $service = Plugin::getInstance()->discounts;
        $abandoned = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $next = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $service->reserve($discount, $abandoned);
        DiscountReservationRecord::updateAll(['expiresAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['orderId' => $abandoned->id]);

        $this->assertSame(0, $service->usesTaken($discount));
        $this->assertTrue($service->reserve($discount, $next));
        $this->assertSame(1, $service->purgeExpiredReservations());
    }

    public function testConsumingTurnsTheHeldUseIntoARealOne(): void
    {
        $discount = $this->makeDiscount('SPEND', Discount::TYPE_PERCENT, 1000, 2);
        $service = Plugin::getInstance()->discounts;
        $order = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $order->discountId = $discount->id;

        $service->reserve($discount, $order);
        $service->consume($order);

        $this->assertSame(1, $service->getDiscountById((int) $discount->id)?->uses);
        $this->assertSame(0, (int) DiscountReservationRecord::find()->where(['orderId' => $order->id])->count());
        $this->assertSame(1, $service->usesTaken($discount), 'one use spent, none held');
    }

    public function testUsedUpCodesAreRefusedAtTheCartToo(): void
    {
        $discount = $this->makeDiscount('GONE1', Discount::TYPE_PERCENT, 1000, 1);
        $service = Plugin::getInstance()->discounts;
        $holder = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $service->reserve($discount, $holder);

        $latecomer = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $this->assertSame('That code is not valid.', $service->problemWith($discount, $latecomer));
        $this->assertNull($service->problemWith($discount, $holder), 'the order holding the use can keep using it');
    }

    public function testUnlimitedCodesAreNeverUsedUp(): void
    {
        $discount = $this->makeDiscount('FREE4ALL');
        $service = Plugin::getInstance()->discounts;

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($service->reserve($discount, $this->makeCart([[$this->makeShopProduct(2000), 1]])));
        }
    }

    public function testDeletingACodeLeavesOrdersAlone(): void
    {
        $discount = $this->makeDiscount('TEMP');
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        Plugin::getInstance()->carts->applyDiscount($cart, 'TEMP');
        Plugin::getInstance()->carts->save($cart);

        Plugin::getInstance()->discounts->deleteDiscount($discount);

        $reloaded = \coyshdigital\smallchange\elements\Order::find()->id($cart->id)->one();
        $this->assertNull($reloaded->discountId);
        $this->assertSame('TEMP', $reloaded->discountCode, 'the code used is still on the order as text');
    }

    public function testDescribe(): void
    {
        $this->assertSame('12.5%', (new Discount(['type' => 'percent', 'percent' => 1250]))->describe('GBP'));
        $this->assertSame('10%', (new Discount(['type' => 'percent', 'percent' => 1000]))->describe('GBP'));
        $this->assertSame('£5', (new Discount(['type' => 'fixed', 'amount' => 500]))->describe('GBP'));
    }
}
