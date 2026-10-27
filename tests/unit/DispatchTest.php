<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use DateTime;

final class DispatchTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
        Craft::$app->getSession()->remove('smallChange.cartNumber');
    }

    /**
     * A paid order for the given product.
     */
    private function paid(\coyshdigital\smallchange\elements\Product $product): Order
    {
        if (!Plugin::getInstance()->shippingMethods->getAllMethods()) {
            $this->makeShippingMethod('Post', 350, ['GB']);
        }

        $cart = $this->makeCart([[$product, 1]]);
        $this->addDeliveryDetails($cart);
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $this->stripe->pay((string) $cart->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($cart);

        return Order::find()->id($cart->id)->one();
    }

    public function testPaidGoodsNeedDispatchUntilMarkedSent(): void
    {
        $order = $this->paid($this->makeShopProduct(1000));

        $this->assertContains($order->id, Order::find()->needsDispatch()->ids());

        $order->dateFulfilled = new DateTime();
        Craft::$app->getElements()->saveElement($order, false);

        $this->assertNotContains($order->id, Order::find()->needsDispatch()->ids());
        $this->assertNotNull(Order::find()->id($order->id)->one()->dateFulfilled);
    }

    public function testThingsThatCannotBePostedNeverNeedDispatch(): void
    {
        $ticket = $this->paid($this->makeShopProduct(1000, ProductType::KIND_NON_PHYSICAL, 'Ticket'));

        $this->assertNotContains($ticket->id, Order::find()->needsDispatch()->ids());
    }

    public function testDonationsNeverNeedDispatch(): void
    {
        $order = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '10', 'a@example.test', false, null, null);
        Craft::$app->getElements()->saveElement($order);
        Plugin::getInstance()->checkout->start($order, 'donate/payment');
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        $this->assertNotContains($order->id, Order::find()->needsDispatch()->ids());
    }

    public function testUnpaidAndRefundedOrdersAreNotWaitingToBeSent(): void
    {
        $pending = $this->makeCart([[$this->makeShopProduct(1000), 1]]);
        $this->assertNotContains($pending->id, Order::find()->needsDispatch()->ids());

        $refunded = $this->paid($this->makeShopProduct(1000, ProductType::KIND_PHYSICAL, 'Second'));
        $refunded->paymentStatus = Order::PAYMENT_REFUNDED;
        Craft::$app->getElements()->saveElement($refunded, false);

        $this->assertNotContains($refunded->id, Order::find()->needsDispatch()->ids());
    }
}
