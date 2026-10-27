<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;

final class OrdersTest extends Unit
{
    use Fixtures;

    protected function _before(): void
    {
        $this->useFakes();
    }

    public function testADonationBecomesAnOrderWithOneDonationLine(): void
    {
        $order = $this->makeOrder(null, '25');

        $this->assertNotNull($order->id);
        $this->assertSame(10, strlen((string) $order->number));
        $this->assertSame(Order::STATE_CART, $order->state);
        $this->assertSame(2500, $order->total);
        $this->assertSame(2500, $order->itemSubtotal);
        $this->assertSame('donor@example.test', $order->email, 'emails are lower-cased');
        $this->assertSame('GBP', $order->currency);
        $this->assertSame('test', $order->stripeMode);

        $lines = Order::find()->id($order->id)->one()?->getLineItems();
        $this->assertCount(1, $lines);
        $this->assertSame('donation', $lines[0]->kind);
        $this->assertSame(2500, $lines[0]->unitPrice);
        $this->assertSame(2500, $lines[0]->subtotal);
        $this->assertTrue($lines[0]->isGiftAidEligible);
    }

    public function testOnlyALiveOrderLocksTheCurrency(): void
    {
        $orders = Plugin::getInstance()->orders;
        $this->assertFalse($orders->currencyIsLocked(), 'a fresh install may still choose');

        $this->makeProduct();
        $this->makeShippingMethod();
        $this->makeDiscount('FIVEOFF', \coyshdigital\smallchange\models\Discount::TYPE_FIXED, 500);
        $order = $this->makePendingOrder();
        $this->assertFalse($orders->currencyIsLocked(), 'prices keep their numbers and test orders their own currency');

        Craft::$app->getDb()->createCommand()->update('{{%smallchange_orders}}', ['stripeMode' => 'live'], ['id' => $order->id])->execute();
        $this->assertTrue($orders->currencyIsLocked(), 'real payments were taken in it');
    }

    public function testTheSettingsPageIsToldWhatHoldsAnAmount(): void
    {
        $orders = Plugin::getInstance()->orders;
        $this->assertSame([], $orders->currencyHolders());

        $this->makeProduct();
        $this->makeShippingMethod();
        $this->makeDiscount('FIVEOFF', \coyshdigital\smallchange\models\Discount::TYPE_FIXED, 500);
        $this->makeDiscount('TENPERCENT', \coyshdigital\smallchange\models\Discount::TYPE_PERCENT, 1000);
        $this->assertSame(['products' => 1, 'shippingMethods' => 1, 'discountCodes' => 1], $orders->currencyHolders(), 'a percentage holds no amount');

        $order = $this->makePendingOrder();
        $holders = $orders->currencyHolders();
        $this->assertSame(1, $holders['testOrders']);
        $this->assertArrayNotHasKey('liveOrders', $holders);

        Craft::$app->getDb()->createCommand()->update('{{%smallchange_orders}}', ['stripeMode' => 'live'], ['id' => $order->id])->execute();
        $holders = $orders->currencyHolders();
        $this->assertSame(1, $holders['liveOrders']);
        $this->assertArrayNotHasKey('testOrders', $holders);
    }

    public function testACurrencyChangeIsRefusedOnlyWhenItWouldChangeWhatAmountsMean(): void
    {
        $orders = Plugin::getInstance()->orders;
        $this->assertNull($orders->currencyChangeError('GBP', 'JPY'), 'nothing is priced yet');

        $this->makeShippingMethod();
        $this->assertNull($orders->currencyChangeError('GBP', 'EUR'), 'same decimal places');
        $this->assertNull($orders->currencyChangeError('GBP', 'gbp'), 'not a change');
        $this->assertNotNull($orders->currencyChangeError('GBP', 'JPY'), '350p would become ¥350');

        $order = $this->makePendingOrder();
        Craft::$app->getDb()->createCommand()->update('{{%smallchange_orders}}', ['stripeMode' => 'live'], ['id' => $order->id])->execute();
        $this->assertStringContainsString('live order', (string) $orders->currencyChangeError('GBP', 'EUR'));
    }

    public function testOpenBasketsMoveToTheNewCurrencyAndPlacedOrdersKeepTheirs(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(), 1]]);
        $placed = $this->makePendingOrder();

        Plugin::getInstance()->orders->moveCartsToCurrency('eur');

        $this->assertSame('EUR', Order::find()->id($cart->id)->one()?->currency);
        $this->assertSame('GBP', Order::find()->id($placed->id)->one()?->currency);
    }

    /**
     * @dataProvider badAmounts
     */
    public function testAmountsAreValidatedOnTheServer(mixed $amount, string $expectedFragment): void
    {
        $product = $this->makeProduct([500, 1000], 200, true);
        $order = Plugin::getInstance()->orders->buildDonation($product, $amount, 'a@example.test', false, null, null);

        $this->assertTrue($order->hasErrors('amount'));
        $this->assertStringContainsString($expectedFragment, $order->getFirstError('amount'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function badAmounts(): array
    {
        return [
            'nothing' => ['', 'Enter the amount'],
            'words' => ['lots', 'Enter the amount'],
            'negative' => ['-5', 'Enter the amount'],
            'zero' => ['0', 'Enter the amount'],
            'comma decimal' => ['10,50', 'Enter the amount'],
            'too much precision' => ['10.555', 'Enter the amount'],
            'below the minimum' => ['1', 'smallest gift'],
        ];
    }

    public function testOnlySuggestedAmountsWhenCustomAmountsAreOff(): void
    {
        $product = $this->makeProduct([500, 1000], 200, false);
        $orders = Plugin::getInstance()->orders;

        $this->assertFalse($orders->buildDonation($product, '10', 'a@example.test', false, null, null)->hasErrors());

        $custom = $orders->buildDonation($product, '7', 'a@example.test', false, null, null);
        $this->assertTrue($custom->hasErrors('amount'));
        $this->assertStringContainsString('suggested amounts', $custom->getFirstError('amount'));
    }

    public function testTheDefaultMinimumIsOneWholeUnit(): void
    {
        $product = $this->makeProduct([], null, true);

        $this->assertSame(100, $product->getEffectiveMinimum());
        $this->assertTrue(Plugin::getInstance()->orders->buildDonation($product, '0.50', 'a@example.test', false, null, null)->hasErrors('amount'));
        $this->assertFalse(Plugin::getInstance()->orders->buildDonation($product, '1', 'a@example.test', false, null, null)->hasErrors());
    }

    public function testEmailIsRequired(): void
    {
        foreach (['', 'not-an-email', 'a@'] as $email) {
            $order = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '10', $email, false, null, null);
            $this->assertTrue($order->hasErrors('email'), "'$email' should be refused");
        }
    }

    public function testADisabledProductCannotBeDonatedTo(): void
    {
        $product = $this->makeProduct();
        $product->enabled = false;
        Craft::$app->getElements()->saveElement($product);

        $order = Plugin::getInstance()->orders->buildDonation($product, '10', 'a@example.test', false, null, null);

        $this->assertTrue($order->hasErrors('productId'));
    }

    // Gift Aid ---------------------------------------------------------------

    public function testGiftAidTickedRecordsTheAnswerAndTheEligibleAmount(): void
    {
        $order = $this->makeOrder(null, '25', true);
        $saved = Order::find()->id($order->id)->one();

        $this->assertTrue($saved->giftAidDeclared);
        $this->assertSame(2500, $saved->giftAidEligibleAmount);
        $this->assertSame('Ada', $saved->getBillingAddress()?->firstName);
        $this->assertSame('SW1Y 4JH', $saved->getBillingAddress()?->postalCode);
    }

    public function testNotTickingGiftAidKeepsNoAddressAndNoEligibleAmount(): void
    {
        $order = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '25', 'a@example.test', false, $this->homeAddress(), null);
        $this->assertFalse($order->hasErrors());
        Craft::$app->getElements()->saveElement($order);

        $saved = Order::find()->id($order->id)->one();
        $this->assertFalse($saved->giftAidDeclared);
        $this->assertSame(0, $saved->giftAidEligibleAmount);
        $this->assertNull($saved->getBillingAddress(), 'the home address of a donor who said no is not kept');
    }

    public function testGiftAidNeedsNameAndHomeAddress(): void
    {
        $address = $this->homeAddress();
        $address->lastName = '';
        $address->postalCode = '';

        $order = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '25', 'a@example.test', true, $address, null);

        $this->assertTrue($order->hasErrors('billingAddress.lastName'));
        $this->assertTrue($order->hasErrors('billingAddress.postalCode'));
        $this->assertFalse($order->hasErrors('billingAddress.firstName'));
    }

    public function testGiftAidWithNoAddressAtAllIsRefused(): void
    {
        $order = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '25', 'a@example.test', true, null, null);

        $this->assertCount(4, array_filter(array_keys($order->getErrors()), static fn(string $key): bool => str_starts_with($key, 'billingAddress.')));
    }

    public function testGiftAidIsNotOfferedWhenDisabled(): void
    {
        Plugin::getInstance()->getSettings()->giftAidEnabled = false;

        $order = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '25', 'a@example.test', true, $this->homeAddress(), null);

        $this->assertNull($order->giftAidDeclared, 'a tick posted to a site that does not offer Gift Aid is ignored');
        $this->assertSame(0, $order->giftAidEligibleAmount);
        $this->assertSame([], Plugin::getInstance()->giftAid->metadata($order));
    }

    public function testGiftAidMetadataCarriesOnlyTheAnswerAndTheAmount(): void
    {
        $yes = $this->makeOrder(null, '25', true);
        $this->assertSame(['smallChangeGiftAid' => 'yes', 'smallChangeGiftAidAmount' => '2500'], Plugin::getInstance()->giftAid->metadata($yes));

        $no = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '25', 'a@example.test', false, null, null);
        $this->assertSame(['smallChangeGiftAid' => 'no', 'smallChangeGiftAidAmount' => '0'], Plugin::getInstance()->giftAid->metadata($no));
    }

    // Who may see an order ---------------------------------------------------

    public function testAnOrderNumberAloneNeverGrantsAccess(): void
    {
        $order = $this->makeOrder();

        $this->assertNull(Plugin::getInstance()->orders->recentOrder($order->number), 'not in this session');
        $this->assertNull(Plugin::getInstance()->orders->recentOrder(null));
        $this->assertNull(Plugin::getInstance()->orders->recentOrder(''));
    }

    public function testAnOrderIsVisibleOnceThisSessionPlacedIt(): void
    {
        $order = $this->makeOrder();
        Plugin::getInstance()->orders->addToSession((string) $order->number);

        $this->assertSame($order->id, Plugin::getInstance()->orders->recentOrder($order->number)?->id);
        $this->assertNull(Plugin::getInstance()->orders->recentOrder('SOMEONEELSE'));
    }

    public function testTheSessionListIsCapped(): void
    {
        $orders = Plugin::getInstance()->orders;

        for ($i = 0; $i < 25; $i++) {
            $orders->addToSession('NUMBER' . $i);
        }

        $this->assertFalse($orders->isInSession('NUMBER0'));
        $this->assertTrue($orders->isInSession('NUMBER24'));
    }

    public function testAccessTokensRoundTrip(): void
    {
        $orders = Plugin::getInstance()->orders;
        $order = $this->makeOrder();

        $token = $orders->createAccessToken($order, 'donate/payment');
        $parsed = $orders->parseAccessToken($token);

        $this->assertSame($order->number, $parsed['number'] ?? null);
        $this->assertSame('donate/payment', $parsed['paymentUrl'] ?? null);
    }

    public function testAccessTokensCannotBeForgedOrAltered(): void
    {
        $orders = Plugin::getInstance()->orders;
        $order = $this->makeOrder();
        $token = $orders->createAccessToken($order, 'donate/payment');

        $this->assertNull($orders->parseAccessToken(''));
        $this->assertNull($orders->parseAccessToken('garbage'));
        $this->assertNull($orders->parseAccessToken(substr($token, 0, -3) . 'AAA'));
        $this->assertNull($orders->parseAccessToken(strrev($token)));

        // Same payload with a valid-looking but unsigned prefix.
        $this->assertNull($orders->parseAccessToken(str_repeat('a', 64) . substr($token, 64)));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $orders = Plugin::getInstance()->orders;
        $payload = \craft\helpers\StringHelper::base64UrlEncode((string) json_encode(['n' => 'ABC', 'e' => time() - 10, 'p' => '']));
        $expired = Craft::$app->getSecurity()->hashData($payload);

        $this->assertNull($orders->parseAccessToken($expired));
    }

    public function testTokensAreStableWithinTheirTimeBucket(): void
    {
        $orders = Plugin::getInstance()->orders;
        $order = $this->makeOrder();

        $this->assertSame($orders->createAccessToken($order, 'x'), $orders->createAccessToken($order, 'x'));
    }

    public function testOrderNumbersUseAnUnambiguousAlphabet(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{10}$/', Order::generateNumber());
        }
    }

    public function testOrderStatusIsDerivedFromState(): void
    {
        $order = new Order();
        $this->assertNull($order->getStatus(), 'a cart is not an order yet');

        $order->state = Order::STATE_PENDING;
        $this->assertSame('pending', $order->getStatus());

        $order->state = Order::STATE_COMPLETED;
        $order->paymentStatus = Order::PAYMENT_PAID;
        $this->assertSame('completed', $order->getStatus());

        $order->paymentStatus = Order::PAYMENT_PARTIALLY_REFUNDED;
        $this->assertSame('partiallyRefunded', $order->getStatus());

        $order->paymentStatus = Order::PAYMENT_REFUNDED;
        $this->assertSame('refunded', $order->getStatus());

        $order->paymentStatus = Order::PAYMENT_DISPUTED;
        $this->assertSame('disputed', $order->getStatus());

        $order->state = Order::STATE_CANCELLED;
        $this->assertSame('cancelled', $order->getStatus());
    }

    public function testQueryFindsDonationsAndGiftAid(): void
    {
        $with = $this->makeOrder(null, '10', true);
        $without = $this->makeOrder(null, '10', false);

        $donations = Order::find()->hasDonation()->ids();
        $this->assertContains($with->id, $donations);
        $this->assertContains($without->id, $donations);

        $giftAid = Order::find()->giftAidDeclared(true)->ids();
        $this->assertContains($with->id, $giftAid);
        $this->assertNotContains($without->id, $giftAid);

        $this->assertSame([], Order::find()->hasDonation(false)->id([$with->id, $without->id])->ids());
    }
}
