<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;

final class CartsTest extends Unit
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

    // Finding the cart -------------------------------------------------------

    public function testThereIsNoCartUntilOneIsStarted(): void
    {
        $carts = Plugin::getInstance()->carts;

        $this->assertNull($carts->getCart());

        $cart = $carts->getCart(true);
        $this->assertSame(Order::STATE_CART, $cart->state);
        $this->assertNull($cart->id, 'nothing is saved until something is added');
    }

    public function testTheCartIsFoundAgainThroughTheSession(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);

        $this->assertSame($cart->id, Plugin::getInstance()->carts->getCart()?->id);
    }

    public function testAnotherSessionCannotReachTheCart(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);
        Craft::$app->getSession()->remove('smallChange.cartNumber');

        $this->assertNull(Plugin::getInstance()->carts->getCart(), 'knowing the number is not enough');
        $this->assertNotNull($cart->number);
    }

    public function testACompletedOrderIsNotACartAnymore(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);
        $cart->state = Order::STATE_COMPLETED;
        Craft::$app->getElements()->saveElement($cart, false);

        $this->assertNull(Plugin::getInstance()->carts->getCart());
        $this->assertNotSame($cart->id, Plugin::getInstance()->carts->getCart(true)->id);
    }

    // Lines ------------------------------------------------------------------

    public function testAddingTheSameProductAgainMergesTheLine(): void
    {
        $carts = Plugin::getInstance()->carts;
        $towel = $this->makeShopProduct(1500);
        $cart = $this->makeCart([[$towel, 2]]);

        $this->assertNull($carts->addProduct($cart, $towel, 3));
        $carts->save($cart);

        $lines = $this->reload($cart)->getLineItems();
        $this->assertCount(1, $lines);
        $this->assertSame(5, $lines[0]->qty);
        $this->assertSame(7500, $lines[0]->subtotal);
        $this->assertSame(7500, $this->reload($cart)->total);
    }

    public function testQuantityIsCapped(): void
    {
        $carts = Plugin::getInstance()->carts;
        $towel = $this->makeShopProduct(100);
        $cart = $this->makeCart([[$towel, 990]]);

        $this->assertStringContainsString('more than 999', (string) $carts->addProduct($cart, $towel, 20));
        $carts->setQuantities($cart, [$towel->id => 5000]);
        $carts->save($cart);

        $this->assertSame(999, $this->reload($cart)->getLineItems()[0]->qty);
    }

    public function testQuantitiesCanBeChangedAndZeroRemovesTheLine(): void
    {
        $carts = Plugin::getInstance()->carts;
        $towel = $this->makeShopProduct(1000);
        $mug = $this->makeShopProduct(500, ProductType::KIND_PHYSICAL, 'Mug');
        $cart = $this->makeCart([[$towel, 1], [$mug, 1]]);

        $carts->setQuantities($cart, [$towel->id => 4, $mug->id => 0]);
        $carts->save($cart);

        $lines = $this->reload($cart)->getLineItems();
        $this->assertCount(1, $lines);
        $this->assertSame('Tea towel', $lines[0]->description);
        $this->assertSame(4000, $this->reload($cart)->total);
    }

    public function testAProductCanBeRemoved(): void
    {
        $carts = Plugin::getInstance()->carts;
        $towel = $this->makeShopProduct(1000);
        $mug = $this->makeShopProduct(500, ProductType::KIND_PHYSICAL, 'Mug');
        $cart = $this->makeCart([[$towel, 1], [$mug, 1]]);

        $carts->removeProduct($cart, (int) $towel->id);
        $carts->save($cart);

        $this->assertSame(['Mug'], array_map(static fn(LineItem $l): string => $l->description, $this->reload($cart)->getLineItems()));
    }

    public function testTheBasketHasALimitOnLines(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart(true);

        for ($i = 0; $i < 50; $i++) {
            $this->assertNull($carts->addProduct($cart, $this->makeShopProduct(100, ProductType::KIND_PHYSICAL, "Item $i"), 1));
        }

        $this->assertStringContainsString('full', (string) $carts->addProduct($cart, $this->makeShopProduct(100, ProductType::KIND_PHYSICAL, 'One too many'), 1));
    }

    public function testAnUnavailableProductCannotBeAdded(): void
    {
        $carts = Plugin::getInstance()->carts;
        $towel = $this->makeShopProduct(1000);
        $towel->enabled = false;
        Craft::$app->getElements()->saveElement($towel);

        $this->assertStringContainsString('not available', (string) $carts->addProduct($carts->getCart(true), $towel, 1));
    }

    // Repricing --------------------------------------------------------------

    public function testAChangedPriceIsPickedUpAndExplained(): void
    {
        $carts = Plugin::getInstance()->carts;
        $towel = $this->makeShopProduct(1000);
        $cart = $this->makeCart([[$towel, 2]]);
        $this->assertSame(2000, $cart->total);

        $towel->price = 1200;
        Craft::$app->getElements()->saveElement($towel);

        $notices = $carts->save($cart);

        $this->assertArrayHasKey('items', $notices);
        $this->assertStringContainsString('price of Tea towel has changed', $notices['items']);
        $this->assertSame(2400, $this->reload($cart)->total);
    }

    public function testAProductThatDisappearsIsRemoved(): void
    {
        $carts = Plugin::getInstance()->carts;
        $towel = $this->makeShopProduct(1000);
        $mug = $this->makeShopProduct(500, ProductType::KIND_PHYSICAL, 'Mug');
        $cart = $this->makeCart([[$towel, 1], [$mug, 1]]);

        $towel->enabled = false;
        Craft::$app->getElements()->saveElement($towel);
        $notices = $carts->save($cart);

        $this->assertStringContainsString('Tea towel is no longer available', $notices['items']);
        $this->assertCount(1, $this->reload($cart)->getLineItems());
    }

    // Donations in the basket ------------------------------------------------

    public function testADonationCanJoinTheBasketAndIsNeverDiscounted(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $donation = $this->makeProduct();

        $this->assertNull($carts->addProduct($cart, $donation, 1, '30'));
        $this->makeDiscount('HALF', Discount::TYPE_PERCENT, 5000);
        $this->assertNull($carts->applyDiscount($cart, 'half'));
        $carts->save($cart);

        $saved = $this->reload($cart);
        $this->assertSame(5000, $saved->itemSubtotal);
        $this->assertSame(1000, $saved->discountAmount, '50% of the £20 product only');
        $this->assertSame(4000, $saved->total);
    }

    public function testAddingADonationAgainChangesItsAmount(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart(true);
        $donation = $this->makeProduct();

        $carts->addProduct($cart, $donation, 1, '10');
        $carts->addProduct($cart, $donation, 1, '40');
        $carts->save($cart);

        $lines = $this->reload($cart)->getLineItems();
        $this->assertCount(1, $lines);
        $this->assertSame(4000, $lines[0]->unitPrice);
    }

    public function testDonationAmountsInTheBasketFollowTheSameRules(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart(true);
        $donation = $this->makeProduct([500, 1000], 200, false);

        $this->assertStringContainsString('suggested amounts', (string) $carts->addProduct($cart, $donation, 1, '7'));
        $this->assertStringContainsString('smallest gift', (string) $carts->addProduct($cart, $donation, 1, '1'));
        $this->assertStringContainsString('Enter the amount', (string) $carts->addProduct($cart, $donation, 1, 'lots'));
        $this->assertNull($carts->addProduct($cart, $donation, 1, '10'));
    }

    public function testGiftAidIsOnlyOfferedWhenThereIsADonation(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $carts->applyDetails($cart, ['giftAid' => '1']);
        $carts->save($cart);
        $this->assertNull($this->reload($cart)->giftAidDeclared, 'goods never qualify, so a posted tick is ignored');

        $carts->addProduct($cart, $this->makeProduct(), 1, '30');
        $carts->applyDetails($cart, ['giftAid' => '1', 'billingAddress' => ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'addressLine1' => '1 Road', 'postalCode' => 'AB1 2CD']]);
        $carts->save($cart);

        $saved = $this->reload($cart);
        $this->assertTrue($saved->giftAidDeclared);
        $this->assertSame(3000, $saved->giftAidEligibleAmount, 'only the donation, not the £20 product');
        $this->assertSame('Ada', $saved->getBillingAddress()?->firstName);
    }

    public function testUntickingGiftAidDeletesTheHomeAddress(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeProduct(), 1]]);
        $carts->applyDetails($cart, ['giftAid' => '1', 'billingAddress' => ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'addressLine1' => '1 Road', 'postalCode' => 'AB1 2CD']]);
        $carts->save($cart);
        $addressId = $this->reload($cart)->billingAddressId;
        $this->assertNotNull($addressId);

        $carts->applyDetails($cart, ['giftAid' => '']);
        $carts->save($cart);

        $this->assertNull($this->reload($cart)->billingAddressId);
        $this->assertNull(\craft\elements\Address::find()->id($addressId)->one(), 'the address is deleted, not just unlinked');
    }

    // Delivery ---------------------------------------------------------------

    public function testOnlyPhysicalGoodsNeedDelivery(): void
    {
        $this->makeShippingMethod('Post', 350);
        $carts = Plugin::getInstance()->carts;

        $ticket = $this->makeCart([[$this->makeShopProduct(1000, ProductType::KIND_NON_PHYSICAL, 'Ticket'), 1]]);
        $this->assertFalse($ticket->getRequiresShipping());
        $this->assertSame(0, $ticket->shippingAmount);

        $carts->addProduct($ticket, $this->makeShopProduct(1000), 1);
        $carts->save($ticket);
        $this->assertTrue($ticket->getRequiresShipping());
    }

    public function testAnAddressWithOneApplicableMethodSelectsItAutomatically(): void
    {
        $post = $this->makeShippingMethod('Post', 350, ['GB']);
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 2]]);
        $this->assertSame(0, $cart->shippingAmount, 'no address yet, so no delivery charge');

        $this->addDeliveryDetails($cart, 'GB');

        $saved = $this->reload($cart);
        $this->assertSame($post->id, $saved->shippingMethodId);
        $this->assertSame('Post', $saved->shippingMethodName);
        $this->assertSame(350, $saved->shippingAmount);
        $this->assertSame(2350, $saved->total);
        $this->assertSame('GB', $saved->getShippingCountryCode());
    }

    public function testWithSeveralMethodsTheCustomerChooses(): void
    {
        $standard = $this->makeShippingMethod('Standard', 350, ['GB']);
        $express = $this->makeShippingMethod('Express', 900, ['GB']);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);

        $this->addDeliveryDetails($cart);
        $this->assertNull($this->reload($cart)->shippingMethodId, 'two choices, none picked for them');
        $this->assertSame(1000, $this->reload($cart)->total);

        $carts->applyDetails($cart, ['shippingMethod' => (string) $express->id]);
        $carts->save($cart);

        $this->assertSame(900, $this->reload($cart)->shippingAmount);
        $this->assertSame(1900, $this->reload($cart)->total);

        $carts->applyDetails($cart, ['shippingMethod' => (string) $standard->id]);
        $carts->save($cart);
        $this->assertSame(1350, $this->reload($cart)->total);
    }

    public function testAMethodThatDoesNotServeTheCountryIsDropped(): void
    {
        $uk = $this->makeShippingMethod('UK post', 350, ['GB']);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);
        $this->addDeliveryDetails($cart, 'GB');
        $this->assertSame($uk->id, $this->reload($cart)->shippingMethodId);

        $carts->applyDetails($cart, ['shippingAddress' => ['countryCode' => 'FR']]);
        $carts->save($cart);

        $saved = $this->reload($cart);
        $this->assertNull($saved->shippingMethodId);
        $this->assertSame(0, $saved->shippingAmount);
    }

    public function testPostedMethodsThatDoNotExistOrAreDisabledAreIgnored(): void
    {
        $this->makeShippingMethod('Standard', 350, ['GB']);
        $this->makeShippingMethod('Express', 900, ['GB']);
        $off = $this->makeShippingMethod('Hidden', 1, ['GB']);
        $off->enabled = false;
        Plugin::getInstance()->shippingMethods->saveMethod($off);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);
        $this->addDeliveryDetails($cart);

        foreach (['999999', (string) $off->id, 'free'] as $bogus) {
            $carts->applyDetails($cart, ['shippingMethod' => $bogus]);
            $carts->save($cart);
            $this->assertNull($this->reload($cart)->shippingMethodId, "'$bogus' must not select a method");
        }
    }

    public function testAPriceOrAmountPostedByTheBrowserIsNeverUsed(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);

        $carts->applyDetails($cart, ['total' => '1', 'itemSubtotal' => '1', 'shippingAmount' => '0', 'discountAmount' => '99999', 'unitPrice' => '1']);
        $carts->save($cart);

        $saved = $this->reload($cart);
        $this->assertSame(1000, $saved->total);
        $this->assertSame(0, $saved->discountAmount);
    }

    // Details ----------------------------------------------------------------

    public function testDetailsAreOnlyChangedWhenPosted(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);

        $carts->applyDetails($cart, ['email' => ' Buyer@Example.TEST ', 'customerNote' => 'Leave with the neighbour']);
        $carts->save($cart);
        $carts->applyDetails($cart, ['customerNote' => 'Ring the bell']);
        $carts->save($cart);

        $saved = $this->reload($cart);
        $this->assertSame('buyer@example.test', $saved->email, 'untouched by the second post');
        $this->assertSame('Ring the bell', $saved->customerNote);
    }

    public function testLongNotesAndAddressPartsAreTrimmed(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);

        $carts->applyDetails($cart, ['customerNote' => str_repeat('x', 5000), 'shippingAddress' => ['firstName' => str_repeat('y', 500)]]);
        $carts->save($cart);

        $saved = $this->reload($cart);
        $this->assertSame(1000, mb_strlen((string) $saved->customerNote));
        $this->assertSame(255, mb_strlen((string) $saved->getShippingAddress()?->firstName));
    }

    // Discounts --------------------------------------------------------------

    public function testApplyingACodeChangesTheTotals(): void
    {
        $carts = Plugin::getInstance()->carts;
        $this->makeDiscount('TEN', Discount::TYPE_PERCENT, 1000);
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $this->assertNull($carts->applyDiscount($cart, 'ten'));
        $carts->save($cart);

        $saved = $this->reload($cart);
        $this->assertSame('TEN', $saved->discountCode);
        $this->assertSame(200, $saved->discountAmount);
        $this->assertSame(1800, $saved->total);

        $carts->removeDiscount($cart);
        $carts->save($cart);
        $this->assertSame(2000, $this->reload($cart)->total);
        $this->assertNull($this->reload($cart)->discountCode);
    }

    public function testABadCodeChangesNothing(): void
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);

        $this->assertSame('That code is not valid.', $carts->applyDiscount($cart, 'nonsense'));
        $this->assertNull($cart->discountId);
    }

    public function testACodeThatStopsBeingValidIsRemovedWithAnExplanation(): void
    {
        $carts = Plugin::getInstance()->carts;
        $discount = $this->makeDiscount('SOON', Discount::TYPE_PERCENT, 1000);
        $cart = $this->makeCart([[$this->makeShopProduct(2000), 1]]);
        $carts->applyDiscount($cart, 'SOON');
        $carts->save($cart);

        $discount->enabled = false;
        Plugin::getInstance()->discounts->saveDiscount($discount);
        $notices = $carts->save($cart);

        $this->assertStringContainsString('discount code was removed', $notices['discount']);
        $this->assertSame(2000, $this->reload($cart)->total);
    }

    public function testRemovingTheProductRemovesTheDiscountIfNothingIsLeftToDiscount(): void
    {
        $carts = Plugin::getInstance()->carts;
        $this->makeDiscount('TEN', Discount::TYPE_PERCENT, 1000);
        $towel = $this->makeShopProduct(2000);
        $cart = $this->makeCart([[$towel, 1]]);
        $carts->addProduct($cart, $this->makeProduct(), 1, '10');
        $carts->applyDiscount($cart, 'TEN');
        $carts->save($cart);

        $carts->removeProduct($cart, (int) $towel->id);
        $notices = $carts->save($cart);

        $this->assertArrayHasKey('discount', $notices);
        $this->assertSame(1000, $this->reload($cart)->total);
    }

    // Changing a basket that is being paid for -------------------------------

    public function testChangingTheBasketCancelsAStartedPayment(): void
    {
        $this->makeShippingMethod('Post', 350);
        $discount = $this->makeDiscount('TEN', Discount::TYPE_FIXED, 500, 5);
        $carts = Plugin::getInstance()->carts;
        $towel = $this->makeShopProduct(2000);
        $cart = $this->makeCart([[$towel, 1]]);
        $carts->applyDiscount($cart, 'TEN');
        $this->addDeliveryDetails($cart);
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');

        $session = $cart->stripeCheckoutSessionId;
        $couponId = $cart->getStripeCouponId();
        $this->assertSame(Order::STATE_PENDING, $this->reload($cart)->state);
        $this->assertSame(1, Plugin::getInstance()->discounts->usesTaken($discount));
        $this->assertArrayHasKey($couponId, $this->stripe->coupons);

        $carts->setQuantities($cart, [$towel->id => 2]);
        $carts->save($cart);

        $saved = $this->reload($cart);
        $this->assertSame(Order::STATE_CART, $saved->state);
        $this->assertNull($saved->stripeCheckoutSessionId);
        $this->assertSame('expired', $this->stripe->sessions[$session]['status'], 'the old session can no longer be paid');
        $this->assertSame(0, Plugin::getInstance()->discounts->usesTaken($discount), 'the held use is back');
        $this->assertArrayNotHasKey($couponId, $this->stripe->coupons);
    }
}
