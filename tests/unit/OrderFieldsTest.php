<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Orders;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\models\FieldLayout;

final class OrderFieldsTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
        Craft::$app->getSession()->remove('smallChange.cartNumber');
    }

    public function testASavedLayoutIsWhatOrdersCarry(): void
    {
        $this->giveOrderFields(['giftMessage' => false]);

        $handles = array_map(static fn($f) => $f->handle, Plugin::getInstance()->orders->getFieldLayout()->getCustomFields());

        $this->assertSame(['giftMessage'], $handles);

        $order = new Order();
        $this->assertSame([], $order->getFieldLayout()?->getCustomFields(), 'an order with no layout has no fields');

        Plugin::getInstance()->orders->useFieldLayout($order, Orders::LAYOUT_CHECKOUT);
        $this->assertSame('giftMessage', $order->getFieldLayout()?->getCustomFields()[0]->handle);
    }

    public function testOnlySafeFieldTypesAreOfferedToCustomers(): void
    {
        $this->giveOrderFields(['giftMessage' => false, 'wrapped' => false, 'website' => false]);
        $orders = Plugin::getInstance()->orders;

        $this->assertSame(['giftMessage', 'wrapped'], array_map(static fn($f) => $f->handle, $orders->getCheckoutFields()));
        $this->assertSame(['website'], array_map(static fn($f) => $f->handle, $orders->getUnsupportedFields()), 'the settings page warns about this one');
    }

    public function testCheckoutValuesAreSavedWithTheOrder(): void
    {
        $this->giveOrderFields(['giftMessage' => false, 'wrapped' => false]);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);

        $carts->applyDetails($cart, ['fields' => ['giftMessage' => 'Happy birthday', 'wrapped' => '1']]);
        $carts->save($cart);

        $saved = Order::find()->id($cart->id)->one();
        $this->assertSame('Happy birthday', $saved->getFieldValue('giftMessage'));
        $this->assertTrue($saved->getFieldValue('wrapped'));
    }

    public function testFieldsTheFormMayNotFillInAreIgnored(): void
    {
        $this->giveOrderFields(['giftMessage' => false, 'website' => false]);
        $carts = Plugin::getInstance()->carts;
        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);

        $carts->applyDetails($cart, ['fields' => ['website' => 'https://evil.test', 'giftMessage' => 'hi', 'notAField' => 'x', 'title' => 'hacked']]);
        $carts->save($cart);

        $saved = Order::find()->id($cart->id)->one();
        $this->assertSame('hi', $saved->getFieldValue('giftMessage'));
        $this->assertEmpty($saved->getFieldValue('website'));
        $this->assertNotSame('hacked', $saved->title);
    }

    public function testARequiredCheckoutFieldHoldsUpCheckout(): void
    {
        $this->giveOrderFields(['giftMessage' => true]);
        $cart = $this->makeCart([[$this->makeShopProduct(1000, 'nonPhysical', 'Ticket'), 1]]);
        Plugin::getInstance()->carts->applyDetails($cart, ['email' => 'a@example.test']);
        Plugin::getInstance()->carts->save($cart);

        $cart->setScenario(Element::SCENARIO_LIVE);
        $this->assertFalse($cart->validate());
        $this->assertTrue($cart->hasErrors('giftMessage'), json_encode($cart->getErrors()) ?: '');

        Plugin::getInstance()->carts->applyDetails($cart, ['fields' => ['giftMessage' => 'Thanks']]);
        $this->assertTrue($cart->validate(), json_encode($cart->getErrors()) ?: '');
    }

    public function testClearingTheLayoutRemovesTheFields(): void
    {
        $this->giveOrderFields(['giftMessage' => false]);
        Plugin::getInstance()->orders->saveFieldLayout(new FieldLayout(['type' => Order::class]));

        $this->assertSame([], Plugin::getInstance()->orders->getCheckoutFields());
    }

    public function testNoFieldsMeansNoRequiredFields(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(1000, 'nonPhysical', 'Ticket'), 1]]);
        $cart->setScenario(Element::SCENARIO_LIVE);

        $this->assertTrue($cart->validate());
    }

    public function testDonationFieldsAreKeptApartFromCheckoutFields(): void
    {
        $this->giveOrderFields(['giftMessage' => false]);
        $this->giveOrderFields(['dietary' => false, 'website' => false], Orders::LAYOUT_DONATION);
        $orders = Plugin::getInstance()->orders;

        $this->assertSame(['giftMessage'], array_map(static fn($f) => $f->handle, $orders->getCheckoutFields()));
        $this->assertSame(['dietary'], array_map(static fn($f) => $f->handle, $orders->getDonationFields()));
        $this->assertSame(['website'], array_map(static fn($f) => $f->handle, $orders->getUnsupportedFields(Orders::LAYOUT_DONATION)));
    }

    public function testClearingOneSetOfFieldsLeavesTheOther(): void
    {
        $this->giveOrderFields(['giftMessage' => false]);
        $this->giveOrderFields(['dietary' => false], Orders::LAYOUT_DONATION);

        Plugin::getInstance()->orders->saveFieldLayout(new FieldLayout(['type' => Order::class]), Orders::LAYOUT_DONATION);

        $this->assertSame([], Plugin::getInstance()->orders->getDonationFields());
        $this->assertSame(['giftMessage'], array_map(static fn($f) => $f->handle, Plugin::getInstance()->orders->getCheckoutFields()));
    }

    public function testADonationCarriesTheDonationFieldsAndABasketTheCheckoutFields(): void
    {
        $this->giveOrderFields(['giftMessage' => false]);
        $this->giveOrderFields(['dietary' => false], Orders::LAYOUT_DONATION);

        $donation = $this->makeOrder();
        $saved = Order::find()->id($donation->id)->one();
        $this->assertSame(['dietary'], array_map(static fn($f) => $f->handle, $saved->getFieldLayout()?->getCustomFields() ?? []));

        $cart = $this->makeCart([[$this->makeShopProduct(1000), 1]]);
        $this->assertSame(['giftMessage'], array_map(static fn($f) => $f->handle, $cart->getFieldLayout()?->getCustomFields() ?? []));
    }

    public function testADonationNeverBorrowsTheCheckoutFields(): void
    {
        $this->giveOrderFields(['giftMessage' => true]);
        $donation = $this->makeOrder();

        $donation->setScenario(Element::SCENARIO_LIVE);
        $this->assertTrue($donation->validate(), 'a required basket question must not block a donation: ' . json_encode($donation->getErrors()));
        $this->assertSame([], $donation->getFieldLayout()?->getCustomFields());
    }

    public function testAnOrderKeepsTheLayoutItWasPlacedWith(): void
    {
        $this->giveOrderFields(['dietary' => false], Orders::LAYOUT_DONATION);
        $donation = $this->makeOrder();

        $this->giveOrderFields(['giftMessage' => false]);

        $saved = Order::find()->id($donation->id)->one();
        $this->assertSame(['dietary'], array_map(static fn($f) => $f->handle, $saved->getFieldLayout()?->getCustomFields() ?? []), 'new checkout fields do not reshape a donation');
    }

    public function testAnOrderKnowsItsUser(): void
    {
        $user = new User(['username' => 'donor', 'email' => 'donor@example.test']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user, false), json_encode($user->getErrors()) ?: '');
        $order = new Order();

        $this->assertNull($order->getUser(), 'a guest has no user');

        $order->userId = (int) $user->id;
        $this->assertSame($user->id, $order->getUser()?->id);
    }

    public function testTheOrdersIndexLoadsEveryUserInOneQuery(): void
    {
        $user = new User(['username' => 'donor', 'email' => 'donor@example.test']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user, false), json_encode($user->getErrors()) ?: '');

        $mine = $this->makeOrder();
        $mine->userId = (int) $user->id;
        $this->assertTrue(Craft::$app->getElements()->saveElement($mine, false));
        $guest = $this->makeOrder();

        $orders = Order::find()->id([$mine->id, $guest->id])->orderBy('id')->with('user')->all();

        $this->assertCount(2, $orders);
        $this->assertNotNull($orders[0]->getEagerLoadedElements('user'), 'loaded up front, not per row');
        $this->assertSame($user->id, $orders[0]->getUser()?->id);
        $this->assertNull($orders[1]->getUser());
    }
}
