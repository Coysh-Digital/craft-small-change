<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\PaymentRecord;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;

final class ReportingTest extends Unit
{
    use Fixtures;

    private ?object $originalRequest = null;

    protected function _before(): void
    {
        $this->useFakes();
    }

    private function gift(\coyshdigital\smallchange\elements\Product $product, string $amount, ?string $when = null): Order
    {
        $order = Plugin::getInstance()->orders->buildDonation($product, $amount, 'donor' . random_int(1, 99999) . '@example.test', false, null, 'x');
        Craft::$app->getElements()->saveElement($order);
        Plugin::getInstance()->checkout->start($order, 'donate/payment');
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        if ($when !== null) {
            Db::update('{{%smallchange_orders}}', ['dateCompleted' => Db::prepareDateForDb(new DateTime($when))], ['id' => $order->id]);
        }

        return Order::find()->id($order->id)->one();
    }

    private function total(\coyshdigital\smallchange\elements\Product $product, ?string $from = null, ?string $to = null): int
    {
        return Plugin::getInstance()->orders->donationTotal((int) $product->id, $from ? new DateTime($from) : null, $to ? new DateTime($to) : null);
    }

    // donationTotal ----------------------------------------------------------

    public function testItAddsUpCompletedGiftsToTheProductOnly(): void
    {
        $fund = $this->makeProduct([], 100, true, 'Fund');
        $other = $this->makeProduct([], 100, true, 'Other');
        $this->gift($fund, '10');
        $this->gift($fund, '2.50');
        $this->gift($other, '99');
        Plugin::getInstance()->orders->buildDonation($fund, '40', 'pending@example.test', false, null, 'x');

        $this->assertSame(1250, $this->total($fund));
        $this->assertSame(9900, $this->total($other));
    }

    public function testGiftsMadeWithTheOtherKindOfKeyAreNotCounted(): void
    {
        $fund = $this->makeProduct([], 100, true, 'Fund');
        $this->gift($fund, '10');

        // The site goes live: the test gift stays in the database but is not part of the campaign.
        $this->stripe->fakeMode = \coyshdigital\smallchange\tests\FakeStripe::MODE_LIVE;
        $this->gift($fund, '25');

        $this->assertSame(2500, $this->total($fund));

        $this->stripe->fakeMode = \coyshdigital\smallchange\tests\FakeStripe::MODE_TEST;
        $this->assertSame(1000, $this->total($fund));
    }

    public function testNothingGivenIsZero(): void
    {
        $this->assertSame(0, $this->total($this->makeProduct()));
    }

    public function testAPeriodIsHalfOpenAndUsesTheCompletionDate(): void
    {
        // A gift before the period, one exactly as it opens, and one after it has closed.
        $start = new DateTime('first day of last month midnight');
        $end = (clone $start)->modify('+1 month');
        $before = (clone $start)->modify('-17 days 12:00');
        $after = (clone $end)->modify('+9 days 09:00');
        $stamp = static fn(DateTime $when): string => $when->format('Y-m-d H:i:s');

        $fund = $this->makeProduct();
        $this->gift($fund, '10', $stamp($before));
        $this->gift($fund, '20', $stamp($start));
        $this->gift($fund, '40', $stamp($after));

        $this->assertSame(2000, $this->total($fund, $start->format('Y-m-d'), $end->format('Y-m-d')));
        $this->assertSame(3000, $this->total($fund, null, $end->format('Y-m-d')));
        $this->assertSame(6000, $this->total($fund, $start->format('Y-m-d')));
        $this->assertSame(7000, $this->total($fund));
    }

    public function testAFullyRefundedGiftIsLeftOut(): void
    {
        $fund = $this->makeProduct();
        $kept = $this->gift($fund, '10');
        $refunded = $this->gift($fund, '30');
        $payment = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $refunded->id)[0];
        Plugin::getInstance()->payments->refund($payment, 3000, null, null, null);

        $this->assertSame(1000, $this->total($fund));
        $this->assertSame('paid', $kept->paymentStatus);
    }

    public function testAGiftThatNeverPaidIsNotCounted(): void
    {
        $fund = $this->makeProduct();
        $order = $this->makePendingOrder('15');
        $this->assertSame(0, $this->total($fund));
        $this->assertNotNull($order);
    }

    public function testEachRecurringPaymentCountsAsItSucceedsAndRefundsComeOff(): void
    {
        $product = $this->makeProduct();
        $product->allowRecurring = true;
        $product->recurringIntervals = ['month'];
        Craft::$app->getElements()->saveElement($product);
        $order = Plugin::getInstance()->orders->buildDonation($product, '10', 'monthly@example.test', false, null, 'x', 'month');
        Craft::$app->getElements()->saveElement($order);
        Plugin::getInstance()->checkout->start($order, 'donate/payment');
        $ids = $this->stripe->paySubscription((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        $this->assertSame(1000, $this->total($product));

        $invoice = $this->stripe->renew($ids['subscription']);
        $intent = $this->stripe->intents[$invoice['payments']['data'][0]['payment']['payment_intent']];
        $later = new DateTime('+1 year midnight');
        $second = Plugin::getInstance()->payments->recordPaymentIntent(Order::find()->id($order->id)->one(), \Stripe\StripeObject::constructFrom($intent), 'test', null, $invoice['id'], Plugin::getInstance()->subscriptions->getSubscriptionByStripeId($ids['subscription'])?->id);
        Db::update('{{%smallchange_payments}}', ['paidAt' => Db::prepareDateForDb($later)], ['id' => $second->id]);

        $this->assertSame(2000, $this->total($product));
        $this->assertSame(1000, $this->total($product, $later->format('Y-m-d')), 'only the later payment falls in the period');

        PaymentRecord::updateAll(['amountRefunded' => 400], ['id' => $second->id]);
        $this->assertSame(1600, $this->total($product));
    }

    public function testTheVariableAcceptsAProductOrAnIdAndLooseDates(): void
    {
        $start = new DateTime('first day of last month midnight');
        $end = (clone $start)->modify('+1 month');

        $fund = $this->makeProduct();
        $this->gift($fund, '10', (clone $start)->modify('+4 days 10:00')->format('Y-m-d H:i:s'));
        $variable = new \coyshdigital\smallchange\variables\SmallChangeVariable();

        $this->assertSame(1000, $variable->donationTotal($fund));
        $this->assertSame(1000, $variable->donationTotal((int) $fund->id, $start->format('Y-m-d'), $end->format('Y-m-d')));
        $this->assertSame(0, $variable->donationTotal($fund, $end->format('Y-m-d')));
    }

    // orders() ---------------------------------------------------------------

    /**
     * Makes the app answer as a public site request, which is what the scoping is for.
     */
    private function actAsSiteRequest(): void
    {
        $this->originalRequest = Craft::$app->has('request', true) ? Craft::$app->get('request') : null;
        Craft::$app->set('request', new class() extends \craft\web\Request {
            public function getIsSiteRequest(): bool
            {
                return true;
            }

            public function getIsCpRequest(): bool
            {
                return false;
            }

            public function getIsConsoleRequest(): bool
            {
                return false;
            }
        });
    }

    protected function _after(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }

        Craft::$app->getUser()->setIdentity(null);
    }

    private function user(string $name): User
    {
        $user = new User(['username' => $name, 'email' => $name . '@example.test']);
        Craft::$app->getElements()->saveElement($user, false);

        return $user;
    }

    public function testASignedInVisitorOnlyEverSeesTheirOwnCompletedOrders(): void
    {
        $fund = $this->makeProduct();
        $mine = $this->user('mine' . random_int(1, 9999));
        $theirs = $this->user('theirs' . random_int(1, 9999));

        foreach ([$mine, $theirs] as $user) {
            $order = $this->gift($fund, '10');
            Db::update('{{%smallchange_orders}}', ['userId' => $user->id], ['id' => $order->id]);
        }

        $pending = $this->makePendingOrder();
        Db::update('{{%smallchange_orders}}', ['userId' => $mine->id], ['id' => $pending->id]);

        $variable = new \coyshdigital\smallchange\variables\SmallChangeVariable();
        $this->assertCount(2, $variable->orders()->all(), 'outside a site request nothing is scoped (control panel, console)');

        $this->actAsSiteRequest();

        Craft::$app->getUser()->setIdentity($mine);
        $this->assertCount(1, $variable->orders()->all());
        $this->assertCount(1, $variable->orders(['userId' => $theirs->id])->all(), 'criteria cannot widen it to someone else');
        $this->assertSame($mine->id, $variable->orders(['userId' => $theirs->id])->one()->userId);
        $this->assertCount(1, $variable->orders(['email' => '*'])->all());
        $this->assertCount(1, $variable->orders(['isCompleted' => null])->all(), 'criteria cannot widen it to unfinished orders');
    }

    public function testAGuestSeesNoOrders(): void
    {
        $fund = $this->makeProduct();
        $this->gift($fund, '10');
        $this->actAsSiteRequest();
        Craft::$app->getUser()->setIdentity(null);

        $this->assertSame([], (new \coyshdigital\smallchange\variables\SmallChangeVariable())->orders()->all());
        $this->assertSame(0, (new \coyshdigital\smallchange\variables\SmallChangeVariable())->orders()->count());
    }
}
