<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\OrderEvent;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\CustomerRecord;
use coyshdigital\smallchange\records\DiscountReservationRecord;
use coyshdigital\smallchange\records\PaymentRecord;
use coyshdigital\smallchange\records\RefundRecord;
use coyshdigital\smallchange\records\SubscriptionRecord;
use coyshdigital\smallchange\records\WebhookEventRecord;
use coyshdigital\smallchange\services\Orders;
use coyshdigital\smallchange\services\Privacy;
use coyshdigital\smallchange\tests\FakeStripe;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use craft\db\Table as CraftTable;
use craft\elements\Address;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\helpers\Db;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use DateTime;
use yii\base\Event;
use yii\db\Query;

final class PrivacyTest extends Unit
{
    use Fixtures;

    private Privacy $privacy;

    protected function _before(): void
    {
        $this->useFakes();
        $this->privacy = Plugin::getInstance()->privacy;
        Craft::$app->getSession()->remove('smallChange.cartNumber');
    }

    // Helpers ----------------------------------------------------------------

    private function paidOrder(string $amount = '25', bool $giftAid = true): Order
    {
        $order = $this->makePendingOrder($amount, $giftAid);
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        return Order::find()->id($order->id)->one();
    }

    // Go-live clean-up ------------------------------------------------------

    public function testTestDataCannotBeDeletedWhileTheSiteIsStillInTestMode(): void
    {
        $order = $this->paidOrder('10', false);

        $this->expectException(\RuntimeException::class);

        try {
            $this->privacy->deleteTestData();
        } finally {
            $this->assertNotNull(Order::find()->id($order->id)->one(), 'nothing was removed');
        }
    }

    public function testGoingLiveRemovesOnlyWhatTestModeMade(): void
    {
        $testOrder = $this->paidOrder('10', true);
        $testBasket = $this->makeOrder();

        $this->stripe->fakeMode = FakeStripe::MODE_LIVE;
        $liveOrder = $this->paidOrder('25', false);
        $this->assertTrue($this->privacy->hasTestData());

        $result = $this->privacy->deleteTestData();

        $this->assertSame(2, $result['orders']);
        $this->assertNull(Order::find()->id($testOrder->id)->status(null)->one());
        $this->assertNull(Order::find()->id($testBasket->id)->status(null)->one());
        $this->assertNotNull(Order::find()->id($liveOrder->id)->one(), 'live orders are untouched');
        $this->assertSame(0, PaymentRecord::find()->where(['orderId' => $testOrder->id])->count(), 'payments go with their order');
        $this->assertSame(1, PaymentRecord::find()->where(['orderId' => $liveOrder->id])->count());
        $this->assertFalse($this->privacy->hasTestData());
        $this->assertSame(0, WebhookEventRecord::find()->where(['stripeMode' => 'test'])->count());
    }

    public function testTestOrdersDoNotUseUpALiveDiscountCode(): void
    {
        $discount = $this->makeDiscount('GOLIVE', Discount::TYPE_PERCENT, 1000, 5);
        $this->makeShippingMethod();
        $cart = $this->makeCart([[$this->makeShopProduct(), 1]]);
        $this->addDeliveryDetails($cart);
        Plugin::getInstance()->carts->applyDiscount($cart, 'GOLIVE');
        Plugin::getInstance()->carts->save($cart);
        Plugin::getInstance()->checkout->start($cart, 'checkout/payment');
        $this->stripe->pay((string) $cart->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($cart);
        $this->assertSame(1, Plugin::getInstance()->discounts->getDiscountById($discount->id)->uses);

        $this->stripe->fakeMode = FakeStripe::MODE_LIVE;
        $this->privacy->deleteTestData();

        $this->assertSame(0, Plugin::getInstance()->discounts->getDiscountById($discount->id)->uses);
    }

    private function age(Order $order, string $when): void
    {
        $date = Db::prepareDateForDb(new DateTime($when));
        Db::update('{{%smallchange_orders}}', ['dateLastActivity' => $date, 'dateCompleted' => $order->dateCompleted ? $date : null, 'dateUpdated' => $date], ['id' => $order->id]);
        Db::update('{{%elements}}', ['dateCreated' => $date], ['id' => $order->id]);
    }

    private function storeEvent(string $id, string $type, array $object, ?string $customer = null, ?string $objectId = null, string $received = 'now'): void
    {
        $record = new WebhookEventRecord();
        $record->stripeEventId = $id;
        $record->type = $type;
        $record->stripeMode = 'test';
        $record->livemode = false;
        $record->stripeObjectId = $objectId ?? ($object['id'] ?? null);
        $record->stripeCustomerId = $customer;
        $record->status = 'processed';
        $record->attempts = 1;
        $record->payload = (string) json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]);
        $record->dateReceived = Db::prepareDateForDb(new DateTime($received));
        $record->save(false);
    }

    private function giveDonationsAField(): void
    {
        $field = new PlainText(['name' => 'Gift message', 'handle' => 'giftMessage']);
        Craft::$app->getFields()->saveField($field);
        $layout = new FieldLayout(['type' => Order::class]);
        $tab = new FieldLayoutTab(['name' => 'Checkout', 'layout' => $layout]);
        $tab->setElements([new CustomField($field)]);
        $layout->setTabs([$tab]);
        Plugin::getInstance()->orders->saveFieldLayout($layout, Orders::LAYOUT_DONATION);
    }

    private function setting(string $name, mixed $value): void
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $settings->$name = $value;
    }

    // Baskets ----------------------------------------------------------------

    public function testOnlyIdleBasketsArePurgedAndTheirAddressesGoWithThem(): void
    {
        $product = $this->makeShopProduct();
        $old = $this->makeCart([[$product, 1]]);
        $this->addDeliveryDetails($old);
        $old = Order::find()->id($old->id)->one();
        $addressId = $old->shippingAddressId;
        $this->assertNotNull($addressId);
        $this->age($old, '-40 days');

        $fresh = $this->makeCart([[$product, 1]]);
        $started = $this->makePendingOrder();
        $this->age($started, '-90 days');

        $removed = $this->privacy->purgeInactiveCarts(30);

        $this->assertSame(1, $removed);
        $this->assertNull(Order::find()->id($old->id)->status(null)->one());
        $this->assertNull(Address::find()->id($addressId)->status(null)->one(), 'the address is deleted too, not left unowned');
        $this->assertNotNull(Order::find()->id($fresh->id)->one());
        $this->assertNotNull(Order::find()->id($started->id)->one(), 'an order that has started checkout is not a basket');
    }

    public function testReservationsGoWithAPurgedBasket(): void
    {
        $product = $this->makeShopProduct();
        $cart = $this->makeCart([[$product, 1]]);
        $discount = $this->makeDiscount('HOLD', 'fixed', 100, 5);
        DiscountReservationRecord::getDb()->createCommand()->insert(DiscountReservationRecord::tableName(), [
            'discountId' => $discount->id, 'orderId' => $cart->id, 'expiresAt' => Db::prepareDateForDb(new DateTime('+1 day')),
            'dateCreated' => Db::prepareDateForDb(new DateTime()), 'dateUpdated' => Db::prepareDateForDb(new DateTime()), 'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();
        $this->age(Order::find()->id($cart->id)->one(), '-60 days');

        $this->privacy->purgeInactiveCarts(30);

        $this->assertSame(0, (int) DiscountReservationRecord::find()->where(['orderId' => $cart->id])->count());
    }

    public function testTheBasketPurgeUsesTheConfiguredDuration(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(), 1]]);
        $this->age(Order::find()->id($cart->id)->one(), '-10 days');
        $this->setting('purgeInactiveCartsDuration', 7);

        $this->assertSame(1, $this->privacy->purgeInactiveCarts());
    }

    public function testDeletingAnOrderRemovesItsAddresses(): void
    {
        $order = $this->paidOrder();
        $addressId = $order->billingAddressId;
        $this->assertNotNull(Address::find()->id($addressId)->one());

        Craft::$app->getElements()->deleteElement($order, true);

        $this->assertNull(Address::find()->id($addressId)->status(null)->one());
    }

    // Webhook payload retention ----------------------------------------------

    public function testOldWebhookEventsAreDeletedAndRecentOnesKept(): void
    {
        $this->storeEvent('evt_old', 'invoice.paid', ['id' => 'in_1'], null, null, '-120 days');
        $this->storeEvent('evt_new', 'invoice.paid', ['id' => 'in_2'], null, null, '-10 days');

        $this->assertSame(1, $this->privacy->purgeWebhookEvents(90));
        $this->assertNull(WebhookEventRecord::findOne(['stripeEventId' => 'evt_old']));
        $this->assertNotNull(WebhookEventRecord::findOne(['stripeEventId' => 'evt_new']));
    }

    // Anonymising ------------------------------------------------------------

    public function testAnOrdersPersonalDataIsRemovedAndTheBooksAreKept(): void
    {
        $this->giveDonationsAField();
        $order = $this->paidOrder();
        $order->customerNote = 'Leave with the vicar';
        $order->adminNote = 'Rang about the receipt';
        $order->lastIp = '203.0.113.9';
        $order->setFieldValue('giftMessage', 'For Mum');
        $order->setShippingAddress(new Address(['title' => 'Delivery', 'firstName' => 'Ada', 'addressLine1' => '1 High St', 'countryCode' => 'GB']));
        Craft::$app->getElements()->saveElement($order, false, false);
        $order = Order::find()->id($order->id)->one();
        $addressIds = [$order->billingAddressId, $order->shippingAddressId];
        $this->assertNotContains(null, $addressIds);

        $this->assertNull($this->privacy->anonymiseOrder($order));

        $saved = Order::find()->id($order->id)->one();
        $this->assertMatchesRegularExpression('/^anonymised-[0-9a-f]{16}@invalid$/', $saved->email);
        $this->assertNull($saved->customerNote);
        $this->assertNull($saved->adminNote);
        $this->assertNull($saved->lastIp);
        $this->assertNull($saved->getFieldValue('giftMessage'));
        $this->assertNull($saved->userId);
        $this->assertNull($saved->billingAddressId);
        $this->assertNull($saved->shippingAddressId);
        $this->assertNotNull($saved->dateAnonymised);

        foreach ($addressIds as $id) {
            $this->assertNull(Address::find()->id($id)->status(null)->one());
        }

        // What an accountant or a Gift Aid return needs is untouched.
        $this->assertSame(2500, $saved->total);
        $this->assertSame(2500, $saved->totalPaid);
        $this->assertTrue($saved->giftAidDeclared);
        $this->assertSame(2500, $saved->giftAidEligibleAmount);
        $this->assertSame($order->number, $saved->number);
        $this->assertSame($order->stripePaymentIntentId, $saved->stripePaymentIntentId);
        $this->assertCount(1, Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id));
    }

    public function testTheCustomerRowIsAnonymisedAndTheStripeCustomerDeleted(): void
    {
        $order = $this->paidOrder();
        $customer = CustomerRecord::findOne($order->customerId);
        $this->assertSame('cus_test_paid', $customer->stripeCustomerId);

        $this->privacy->anonymiseOrder($order);

        $customer = CustomerRecord::findOne($order->customerId);
        $this->assertMatchesRegularExpression('/^anonymised-[0-9a-f]{16}@invalid$/', $customer->email);
        $this->assertNull($customer->name);
        $this->assertNull($customer->stripeCustomerId);
        $this->assertNotNull($customer->dateAnonymised);
        $this->assertSame(['cus_test_paid'], $this->stripe->deletedCustomers);
    }

    public function testAStripeFailureDoesNotStopTheLocalErasure(): void
    {
        $stripe = new class() extends \coyshdigital\smallchange\tests\FakeStripe {
            public function deleteCustomer(string $id): void
            {
                throw new \Stripe\Exception\ApiConnectionException('network down');
            }
        };
        $order = $this->paidOrder();
        Plugin::getInstance()->set('stripe', $stripe);

        $this->assertNull($this->privacy->anonymiseOrder($order));
        $this->assertNotNull(Order::find()->id($order->id)->one()->dateAnonymised);
        $this->assertSame('cus_test_paid', CustomerRecord::findOne($order->customerId)->stripeCustomerId, 'kept so it can be removed later');
    }

    public function testStoredWebhookPayloadsAreRedactedByEveryRouteThatFindsThem(): void
    {
        $order = $this->paidOrder();
        $payment = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id)[0];
        $email = strtolower((string) $order->email);

        $this->storeEvent('evt_by_customer', 'customer.updated', ['id' => 'cus_test_paid', 'email' => 'x'], 'cus_test_paid');
        $this->storeEvent('evt_by_object', 'payment_intent.succeeded', ['id' => $payment->stripePaymentIntentId, 'receipt_email' => null]);
        $this->storeEvent('evt_by_session', 'checkout.session.completed', ['id' => $order->stripeCheckoutSessionId]);
        $this->storeEvent('evt_by_email', 'customer.created', ['id' => 'cus_other', 'email' => $email]);
        $this->storeEvent('evt_by_email_upper', 'customer.created', ['id' => 'cus_other2', 'email' => strtoupper($email)]);
        $this->storeEvent('evt_unrelated', 'customer.created', ['id' => 'cus_someone_else', 'email' => 'someone@else.test']);

        $this->privacy->anonymiseOrder($order);

        foreach (['evt_by_customer', 'evt_by_object', 'evt_by_session', 'evt_by_email', 'evt_by_email_upper'] as $id) {
            $record = WebhookEventRecord::findOne(['stripeEventId' => $id]);
            $this->assertNotNull($record->redactedAt, "$id should be redacted");
            $this->assertSame(['redacted' => true, 'type' => $record->type, 'id' => $id], json_decode($record->payload, true));
        }

        $untouched = WebhookEventRecord::findOne(['stripeEventId' => 'evt_unrelated']);
        $this->assertNull($untouched->redactedAt);
        $this->assertStringContainsString('someone@else.test', $untouched->payload);
    }

    public function testRefundNotesAreClearedToo(): void
    {
        $order = $this->paidOrder();
        $payment = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id)[0];
        Plugin::getInstance()->payments->refund($payment, 500, null, 'Mrs Lovelace phoned', null);
        $this->assertSame('Mrs Lovelace phoned', RefundRecord::find()->one()->note);

        $this->privacy->anonymiseOrder(Order::find()->id($order->id)->one());

        $this->assertNull(RefundRecord::find()->one()->note);
        $this->assertSame(500, RefundRecord::find()->one()->amount, 'the refund itself is a financial record and stays');
    }

    public function testAnonymisingTwiceChangesNothingFurther(): void
    {
        $order = $this->paidOrder();
        $this->privacy->anonymiseOrder($order);
        $first = Order::find()->id($order->id)->one();

        $this->assertNull($this->privacy->anonymiseOrder($first));
        $this->assertSame($first->email, Order::find()->id($order->id)->one()->email);
    }

    public function testTheEventFiresWithTheOrder(): void
    {
        $seen = [];
        Event::on(Privacy::class, Privacy::EVENT_AFTER_ANONYMISE_ORDER, static function(OrderEvent $event) use (&$seen): void {
            $seen[] = $event->order->number;
        });
        $order = $this->paidOrder();

        $this->privacy->anonymiseOrder($order);

        $this->assertSame([$order->number], $seen);
        Event::offAll(Privacy::class);
    }

    public function testAnOrderStillAwaitingPaymentIsNotErased(): void
    {
        $order = $this->makePendingOrder();

        $this->assertStringContainsString('not been completed', (string) $this->privacy->anonymiseOrder($order));
        $this->assertNull(Order::find()->id($order->id)->one()->dateAnonymised);
    }

    public function testARunningRecurringDonationBlocksErasureUntilItIsCancelled(): void
    {
        $order = $this->recurringPaid();
        $reason = $this->privacy->anonymiseOrder($order);

        $this->assertStringContainsString('still running', (string) $reason);
        $this->assertNull(Order::find()->id($order->id)->one()->dateAnonymised);
        $this->assertSame([], $this->stripe->deletedCustomers);

        SubscriptionRecord::updateAll(['status' => 'canceled'], ['orderId' => $order->id]);

        $this->assertNull($this->privacy->anonymiseOrder(Order::find()->id($order->id)->one()));
    }

    private function recurringPaid(): Order
    {
        $product = $this->makeProduct();
        $product->allowRecurring = true;
        $product->recurringIntervals = ['month'];
        Craft::$app->getElements()->saveElement($product);
        $order = Plugin::getInstance()->orders->buildDonation($product, '10', 'monthly@example.test', false, null, 'donate/thanks', 'month');
        Craft::$app->getElements()->saveElement($order);
        Plugin::getInstance()->checkout->start($order, 'donate/payment');
        $this->stripe->paySubscription((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        return Order::find()->id($order->id)->one();
    }

    // Erasing a person -------------------------------------------------------

    public function testDeletingACraftUserKeepsTheirOrdersButUnlinksThem(): void
    {
        $user = new \craft\elements\User(['username' => 'leaver' . random_int(1, 99999), 'email' => 'leaver' . random_int(1, 99999) . '@example.test']);
        Craft::$app->getElements()->saveElement($user, false);
        $order = $this->paidOrder('10', false);
        Db::update('{{%smallchange_orders}}', ['userId' => $user->id], ['id' => $order->id]);
        Db::update('{{%smallchange_customers}}', ['userId' => $user->id], ['id' => $order->customerId]);

        Craft::$app->getElements()->deleteElement($user, true);

        $this->assertNull(Order::find()->id($order->id)->one()->userId);
        $this->assertNull(CustomerRecord::findOne($order->customerId)->userId);
        $this->assertSame(1000, Order::find()->id($order->id)->one()->totalPaid, 'the order and its money survive');
    }

    public function testErasingADonorCoversEveryOrderPlacedWithTheAddress(): void
    {
        $first = $this->paidOrder('10', false);
        $second = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '5', 'DONOR@example.test', false, null, 'x');
        Craft::$app->getElements()->saveElement($second);
        Plugin::getInstance()->checkout->start($second, 'donate/payment');
        $this->stripe->pay((string) $second->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($second);
        $other = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '5', 'other@example.test', false, null, 'x');
        Craft::$app->getElements()->saveElement($other);

        $result = $this->privacy->anonymiseDonor(' Donor@Example.test ');

        $this->assertSame(2, $result['orders']);
        $this->assertSame([], $result['skipped']);
        $this->assertNotNull(Order::find()->id($first->id)->one()->dateAnonymised);
        $this->assertNotNull(Order::find()->id($second->id)->one()->dateAnonymised);
        $this->assertSame('other@example.test', Order::find()->id($other->id)->one()->email);
    }

    public function testErasingAnOrderAlsoRemovesItFromTheSearchIndex(): void
    {
        $this->giveDonationsAField();
        $order = $this->paidOrder();
        $order->setFieldValue('giftMessage', 'For Mum');
        Craft::$app->getElements()->saveElement($order, false, false);
        $order = Order::find()->id($order->id)->one();
        $search = Craft::$app->getSearch();
        $keywords = fn(): string => implode(' ', (new Query())->select('keywords')->from(CraftTable::SEARCHINDEX)->where(['elementId' => $order->id])->column());

        // Searches are indexed by a queue job; run it as the site would have.
        $search->indexElementAttributes($order);
        $this->assertStringContainsString('donor', $keywords(), 'the donor can be found by email before erasure');

        $this->assertNull($this->privacy->anonymiseOrder($order));

        $this->assertStringNotContainsString('donor', $keywords(), 'and cannot be afterwards');
        $this->assertStringNotContainsString('mum', $keywords(), 'nor by what they wrote');
    }

    public function testErasingADonorAlsoCoversBasketsAndUnpaidCheckouts(): void
    {
        $product = $this->makeShopProduct();
        $cart = $this->makeCart([[$product, 1]]);
        $this->addDeliveryDetails($cart);
        $addressId = Order::find()->id($cart->id)->one()->shippingAddressId;
        $pending = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '5', 'buyer@example.test', false, null, 'x');
        Craft::$app->getElements()->saveElement($pending);
        Plugin::getInstance()->checkout->start($pending, 'donate/payment');

        $result = $this->privacy->anonymiseDonor('buyer@example.test');

        $this->assertSame(2, $result['orders']);
        $this->assertSame([], $result['skipped']);
        $this->assertNull(Order::find()->id($cart->id)->status(null)->one(), 'the basket is deleted');
        $this->assertNull(Address::find()->id($addressId)->status(null)->one());
        $saved = Order::find()->id($pending->id)->one();
        $this->assertSame(Order::STATE_CANCELLED, $saved->state);
        $this->assertNotNull($saved->dateAnonymised);
        $this->assertContains('expireCheckoutSession', array_column($this->stripe->calls, 'method'), 'the unpaid Stripe session can no longer be paid');
    }

    public function testErasingADonorReportsWhatItLeftAlone(): void
    {
        $recurring = $this->recurringPaid();
        $this->paidOrder('10', false);
        $result = $this->privacy->anonymiseDonor('monthly@example.test');

        $this->assertSame(0, $result['orders']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString($recurring->number, $result['skipped'][0]);
        $this->assertNull(CustomerRecord::findOne(['stripeCustomerId' => $this->stripe->subscriptions[array_key_first($this->stripe->subscriptions)]['customer']])->dateAnonymised, 'the customer behind a live donation keeps working');
    }

    public function testACustomerRecordWhoseOrdersWerePurgedIsStillErased(): void
    {
        $order = $this->paidOrder('10', false);
        $customerId = $order->customerId;
        Craft::$app->getElements()->deleteElement($order, true);

        $this->privacy->anonymiseDonor('donor@example.test');

        $this->assertNotNull(CustomerRecord::findOne($customerId)->dateAnonymised);
    }

    public function testAnEmptyAddressErasesNothing(): void
    {
        $order = $this->paidOrder();

        $this->assertSame(['orders' => 0, 'skipped' => []], $this->privacy->anonymiseDonor('  '));
        $this->assertNull(Order::find()->id($order->id)->one()->dateAnonymised);
    }

    public function testAnAddressIsMatchedLiterallyNotAsAListOrAnOperator(): void
    {
        $donor = $this->paidOrder();
        $other = Plugin::getInstance()->orders->buildDonation($this->makeProduct(), '5', 'other@example.test', false, null, 'x');
        Craft::$app->getElements()->saveElement($other);

        // Neither is an address anyone has, so neither may erase the people they happen to mention.
        $this->assertSame(['orders' => 0, 'skipped' => []], $this->privacy->anonymiseDonor('donor@example.test, other@example.test'));
        $this->assertSame(['orders' => 0, 'skipped' => []], $this->privacy->anonymiseDonor('not other@example.test'));

        $this->assertNull(Order::find()->id($donor->id)->one()->dateAnonymised);
        $this->assertNotNull(Order::find()->id($other->id)->status(null)->one(), 'the other basket is still there');
    }

    // Retention --------------------------------------------------------------

    public function testRetentionAnonymisesOnlyOldFinishedOrders(): void
    {
        $old = $this->paidOrder('10', false);
        $this->age($old, '-400 days');
        $recent = $this->paidOrder('10', false);

        $this->setting('anonymiseOrdersAfter', null);
        $this->assertSame(0, $this->privacy->anonymiseDueOrders(), 'off by default');

        $this->setting('anonymiseOrdersAfter', 365);
        $this->assertSame(1, $this->privacy->anonymiseDueOrders());

        $this->assertNotNull(Order::find()->id($old->id)->one()->dateAnonymised);
        $this->assertNull(Order::find()->id($recent->id)->one()->dateAnonymised);
    }

    public function testRetentionLeavesOrdersWithRunningRecurringDonations(): void
    {
        $order = $this->recurringPaid();
        $this->age($order, '-800 days');
        $this->setting('anonymiseOrdersAfter', 365);

        $this->assertSame(0, $this->privacy->anonymiseDueOrders());
        $this->assertNull(Order::find()->id($order->id)->one()->dateAnonymised);
    }

    public function testHousekeepingRunsEveryTask(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(), 1]]);
        $this->age(Order::find()->id($cart->id)->one(), '-90 days');
        $this->storeEvent('evt_old', 'invoice.paid', ['id' => 'in_1'], null, null, '-200 days');

        $result = $this->privacy->runHousekeeping();

        $this->assertSame(['carts' => 1, 'events' => 1, 'reservations' => 0, 'anonymised' => 0], $result);
    }

    public function testCraftGarbageCollectionRunsTheHousekeeping(): void
    {
        $cart = $this->makeCart([[$this->makeShopProduct(), 1]]);
        $this->age(Order::find()->id($cart->id)->one(), '-90 days');

        Craft::$app->getGc()->run(true);

        $this->assertNull(Order::find()->id($cart->id)->status(null)->one());
    }
}
