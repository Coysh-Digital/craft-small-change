<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\exceptions\CheckoutException;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;

final class CartCheckoutTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
        Craft::$app->getSession()->remove('smallChange.cartNumber');
    }

    private function reload(Order $order): Order
    {
        return Order::find()->id($order->id)->one();
    }

    /**
     * A cart ready to pay: two products, delivery, and optionally a code.
     */
    private function readyCart(?string $code = null, int $maxUses = 0): Order
    {
        if (!Plugin::getInstance()->shippingMethods->getAllMethods()) {
            $this->makeShippingMethod('Post', 350, ['GB']);
        }

        $cart = $this->makeCart([[$this->makeShopProduct(2000), 2], [$this->makeShopProduct(500, ProductType::KIND_PHYSICAL, 'Mug'), 1]]);

        if ($code !== null) {
            $existing = Plugin::getInstance()->discounts->getDiscountByCode($code);
            $existing ?? $this->makeDiscount($code, Discount::TYPE_FIXED, 700, $maxUses ?: null);
            Plugin::getInstance()->carts->applyDiscount($cart, $code);
        }

        $this->addDeliveryDetails($cart);

        return $cart;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionParams(): array
    {
        $calls = $this->stripe->callsTo('createCheckoutSession');

        return end($calls)[0];
    }

    // What Stripe is asked to charge ----------------------------------------

    public function testTheSessionHasOneLinePerItemPlusDelivery(): void
    {
        $cart = $this->readyCart();
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $params = $this->sessionParams();

        $lines = array_map(static fn(array $l): array => [$l['price_data']['product_data']['name'], $l['price_data']['unit_amount'], $l['quantity']], $params['line_items']);
        $this->assertSame([['Tea towel', 2000, 2], ['Mug', 500, 1], ['Delivery: Post', 350, 1]], $lines);
        $this->assertArrayNotHasKey('discounts', $params);
        $this->assertArrayNotHasKey('shipping_address_collection', $params, 'Stripe is not asked to collect an address');
        $this->assertStringStartsWith('Order ', $params['payment_intent_data']['description']);
    }

    public function testStripesTotalAlwaysEqualsOurTotal(): void
    {
        $cart = $this->readyCart('SEVEN');
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');

        $saved = $this->reload($cart);
        $this->assertSame(4500 + 350 - 700, $saved->total);
        $this->assertSame($saved->total, $this->stripe->sessions[$saved->stripeCheckoutSessionId]['amount_total']);
    }

    public function testADiscountTravelsAsAOneUseCoupon(): void
    {
        $cart = $this->readyCart('SEVEN');
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $params = $this->sessionParams();
        $couponId = 'sc_' . $cart->number . '_0_700';

        $this->assertSame([['coupon' => $couponId]], $params['discounts']);
        $this->assertSame(['id' => $couponId, 'amount_off' => 700, 'currency' => 'gbp', 'duration' => 'once', 'max_redemptions' => 1, 'name' => 'Discount SEVEN'], $this->stripe->coupons[$couponId]);
    }

    public function testACodeSurvivesTheCheckoutBeingStartedAgain(): void
    {
        $plugin = Plugin::getInstance();
        $cart = $this->readyCart('SEVEN');
        $plugin->checkout->start($cart, 'checkout/payment');
        $firstCoupon = $cart->getStripeCouponId();

        // Back to the details page and on again: the first coupon was deleted with the first session, and Stripe
        // would answer a second create under the same id with the old response, so the next attempt needs its own.
        $plugin->carts->applyDetails($cart, ['customerNote' => 'Leave with a neighbour']);
        $plugin->carts->save($cart);
        $plugin->checkout->start($cart, 'checkout/payment');

        $this->assertSame(Order::STATE_PENDING, $this->reload($cart)->state);
        $this->assertNotSame($firstCoupon, $cart->getStripeCouponId());
        $this->assertArrayNotHasKey($firstCoupon, $this->stripe->coupons);
        $this->assertArrayHasKey($cart->getStripeCouponId(), $this->stripe->coupons);
        $this->assertSame(4500 + 350 - 700, $this->stripe->sessions[$cart->stripeCheckoutSessionId]['amount_total']);
    }

    public function testListenersCannotRemoveTheDiscountOrTheDeliveryLine(): void
    {
        \yii\base\Event::on(\coyshdigital\smallchange\services\Checkout::class, \coyshdigital\smallchange\services\Checkout::EVENT_BUILD_CHECKOUT_SESSION, static function(\coyshdigital\smallchange\events\BuildCheckoutSessionEvent $e): void {
            unset($e->params['discounts']);
            array_pop($e->params['line_items']);
        });

        $cart = $this->readyCart('SEVEN');
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $params = $this->sessionParams();
        \yii\base\Event::off(\coyshdigital\smallchange\services\Checkout::class, \coyshdigital\smallchange\services\Checkout::EVENT_BUILD_CHECKOUT_SESSION);

        $this->assertCount(3, $params['line_items']);
        $this->assertArrayHasKey('discounts', $params);
    }

    public function testAMixedBasketGiftAidsOnlyTheDonation(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $carts->addProduct($cart, $this->makeProduct(), 1, '30');
        $carts->applyDetails($cart, ['giftAid' => '1', 'billingAddress' => ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'addressLine1' => '1 Road', 'postalCode' => 'SW1A 1AA']]);
        $this->addDeliveryDetails($cart);

        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $metadata = $this->sessionParams()['payment_intent_data']['metadata'];

        $this->assertSame('yes', $metadata['smallChangeGiftAid']);
        $this->assertSame('3000', $metadata['smallChangeGiftAidAmount']);
        $this->assertSame(2000 + 3000 + 350, $this->reload($cart)->total);
    }

    public function testHostedCheckoutOfGoodsSaysPayNotDonate(): void
    {
        Plugin::getInstance()->getSettings()->checkoutMode = 'hosted_page';
        $cart = $this->readyCart();

        $url = Plugin::getInstance()->checkout->start($cart, 'checkout/payment');

        $this->assertNotNull($url);
        $this->assertSame('pay', $this->sessionParams()['submit_type']);
    }

    // Things that stop checkout ---------------------------------------------

    public function testCheckoutIsRefusedUntilThereIsADeliveryMethod(): void
    {
        $this->makeShippingMethod('Post', 350, ['FR']);
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $this->addDeliveryDetails($cart, 'GB');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/delivery/i');
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
    }

    public function testAPriceChangeStopsCheckoutOnceSoTheCustomerSeesTheNewTotal(): void
    {
        $this->makeShippingMethod('Post', 350, ['GB']);
        $towel = $this->makeShopProduct(2000);
        $cart = $this->makeCart([[$towel, 1]]);
        $this->addDeliveryDetails($cart);

        $towel->price = 2500;
        Craft::$app->getElements()->saveElement($towel);

        try {
            Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
            $this->fail('expected the price change to stop checkout');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('price of Tea towel has changed', $e->getMessage());
        }

        $this->assertSame(2850, $this->reload($cart)->total, 'the new figures were saved');
        $this->assertSame([], $this->stripe->sessions, 'nothing was sent to Stripe');

        // They have now seen it, so a second go goes through at the new price.
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $this->assertSame(2850, $this->stripe->sessions['cs_test_1']['amount_total']);
    }

    public function testAnEmptyTotalIsRefused(): void
    {
        $this->makeDiscount('ALL', Discount::TYPE_PERCENT, 10000);
        $this->makeShippingMethod('Free', 0, null);
        $cart = $this->makeCart([[$this->makeShopProduct(1000, ProductType::KIND_NON_PHYSICAL, 'Ticket'), 1]]);
        Plugin::getInstance()->carts->applyDiscount($cart, 'ALL');
        Plugin::getInstance()->carts->applyDetails($cart, ['email' => 'a@example.test']);
        Plugin::getInstance()->carts->save($cart);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/nothing to pay/i');
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
    }

    // Usage limits under competition ----------------------------------------

    public function testOnlyOneOfTwoCustomersGetsTheLastUse(): void
    {
        $this->makeDiscount('LAST', Discount::TYPE_FIXED, 700, 1);
        $first = $this->readyCart('LAST');
        $second = $this->readyCart('LAST');

        Plugin::getInstance()->checkout->start($first, 'checkout/payment');

        try {
            Plugin::getInstance()->checkout->start($second, 'checkout/payment');
            $this->fail('the second customer must not get the last use');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('discount code was removed', $e->getMessage());
        }

        $loser = $this->reload($second);
        $this->assertNull($loser->discountCode, 'the code is taken off their basket');
        $this->assertSame(4850, $loser->total, 'and the new total saved');
        $this->assertCount(1, $this->stripe->sessions, 'only the winner has a Checkout Session');
    }

    public function testTheRealRaceIsClosedByTheReservationNotTheEarlierCheck(): void
    {
        $discount = $this->makeDiscount('RAZOR', Discount::TYPE_FIXED, 700, 1);
        $first = $this->readyCart('RAZOR');
        $second = $this->readyCart('RAZOR');

        // Both customers pass the "is the code still usable?" check before either reserves, as two
        // simultaneous requests would. Only the reservation, taken under a lock, can decide.
        $stale = new class() extends \coyshdigital\smallchange\services\Discounts {
            public function problemWith(Discount $discount, Order $order): ?string
            {
                return null;
            }
        };
        Plugin::getInstance()->set('discounts', $stale);

        Plugin::getInstance()->checkout->start($first, 'checkout/payment');

        try {
            Plugin::getInstance()->checkout->start($second, 'checkout/payment');
            $this->fail('the reservation must refuse the second customer');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('fully used', $e->getMessage());
        }

        $this->assertNull($this->reload($second)->discountCode);
        $this->assertSame(4850, $this->reload($second)->total);
        $this->assertCount(1, $this->stripe->sessions);
        $this->assertSame(1, $stale->usesTaken($discount));
    }

    public function testAbandoningACheckoutGivesTheUseToSomeoneElse(): void
    {
        $this->makeDiscount('PASS', Discount::TYPE_FIXED, 700, 1);
        $first = $this->readyCart('PASS');
        $second = $this->readyCart('PASS');
        Plugin::getInstance()->checkout->start($first, 'checkout/payment');

        // Their session runs out.
        Plugin::getInstance()->orders->releaseExpired($this->reload($first), (string) $this->reload($first)->stripeCheckoutSessionId);

        Plugin::getInstance()->carts->applyDiscount($second, 'PASS');
        Plugin::getInstance()->checkout->start($this->reload($second), 'checkout/payment');
        $this->assertSame(1, Plugin::getInstance()->discounts->usesTaken(Plugin::getInstance()->discounts->getDiscountByCode('PASS')));
    }

    public function testManyCustomersRacingForALimitedCodeNeverExceedIt(): void
    {
        $this->makeDiscount('RACE', Discount::TYPE_FIXED, 700, 3);
        $won = 0;

        for ($i = 0; $i < 8; $i++) {
            $cart = $this->readyCart('RACE');

            try {
                Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
                // Starting at all proves nothing: a cart that never got the code starts fine without it.
                $won += $this->reload($cart)->discountCode === 'RACE' ? 1 : 0;
            } catch (CheckoutException) {
                // lost the race
            }
        }

        $this->assertSame(3, $won);
        $this->assertSame(3, Plugin::getInstance()->discounts->usesTaken(Plugin::getInstance()->discounts->getDiscountByCode('RACE')));
    }

    public function testPayingSpendsTheUseAndTidiesTheCoupon(): void
    {
        $this->makeDiscount('SPEND', Discount::TYPE_FIXED, 700, 2);
        $cart = $this->readyCart('SPEND');
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $couponId = $cart->getStripeCouponId();

        $this->stripe->pay((string) $cart->stripeCheckoutSessionId);
        $this->assertTrue(Plugin::getInstance()->orders->completeOrder($cart));

        $this->assertSame(1, Plugin::getInstance()->discounts->getDiscountByCode('SPEND')?->uses);
        $this->assertArrayNotHasKey($couponId, $this->stripe->coupons);
        $this->assertSame(4150, $this->reload($cart)->totalPaid);

        // A repeated webhook must not spend it again.
        Plugin::getInstance()->orders->completeOrder($this->reload($cart));
        $this->assertSame(1, Plugin::getInstance()->discounts->getDiscountByCode('SPEND')?->uses);
    }

    public function testAnExpiredSessionGivesTheUseAndTheCouponBack(): void
    {
        $discount = $this->makeDiscount('LAPSE', Discount::TYPE_FIXED, 700, 1);
        $cart = $this->readyCart('LAPSE');
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $couponId = $cart->getStripeCouponId();

        Plugin::getInstance()->orders->releaseExpired($this->reload($cart), (string) $cart->stripeCheckoutSessionId);

        $this->assertSame(0, Plugin::getInstance()->discounts->usesTaken($discount));
        $this->assertArrayNotHasKey($couponId, $this->stripe->coupons);
        $this->assertSame(Order::STATE_CART, $this->reload($cart)->state);
    }

    public function testACompletedCartEndsTheSessionsCart(): void
    {
        $cart = $this->readyCart();
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $this->stripe->pay((string) $cart->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($cart);

        $this->assertNull(Plugin::getInstance()->carts->getCart(), 'the next visit starts a fresh basket');
    }

    public function testTheReceiptShowsDeliveryAndTheDiscount(): void
    {
        $cart = $this->readyCart('SEVEN');
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $this->stripe->pay((string) $cart->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($cart);

        $html = (new \coyshdigital\smallchange\services\Emails())->render('receipt', [
            'order' => $this->reload($cart),
            'payment' => null,
            'lineItems' => $this->reload($cart)->getLineItems(),
            'billingAddress' => null,
            'siteName' => 'Test',
            'settings' => Plugin::getInstance()->getSettings(),
            'giftAid' => ['declared' => null, 'amount' => 0, 'text' => null],
        ]);

        $this->assertStringContainsString('Tea towel', $html);
        $this->assertStringContainsString('Delivery', $html);
        $this->assertStringContainsString('£3.50', $html);
        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('£41.50', $html);
        $this->assertStringContainsString('Thank you for your order', $html);
    }
}
