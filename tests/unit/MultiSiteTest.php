<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use craft\db\Table as CraftTable;
use craft\models\Site;

/**
 * Orders are not localised, so Craft only ever looks for them on the primary site. An order placed on any
 * other site has to be found there too, and still remember where it was placed.
 */
final class MultiSiteTest extends Unit
{
    use Fixtures;

    private Site $primary;
    private ?Site $second = null;

    protected function _before(): void
    {
        $this->useFakes();
        $this->primary = Craft::$app->getSites()->getPrimarySite();
        $this->second = $this->addSecondSite();
    }

    protected function _after(): void
    {
        $sites = Craft::$app->getSites();
        $sites->setCurrentSite($this->primary);

        if ($this->second !== null) {
            $sites->deleteSite($this->second);
            $this->second = null;
        }

        Craft::$app->getIsMultiSite(true, true);
    }

    public function testAnOrderPlacedOnAnotherSiteIsFoundAndRemembersWhereItWasPlaced(): void
    {
        $order = $this->placeOrderOnSecondSite();

        foreach ([$this->primary, $this->second] as $current) {
            Craft::$app->getSites()->setCurrentSite($current);
            $found = Order::find()->id($order->id)->one();

            $this->assertNotNull($found, 'found whichever site the request is on (' . $current->handle . ')');
            $this->assertSame($this->second->id, $found->orderSiteId);
            $this->assertSame($this->primary->id, $found->siteId, 'the element itself lives on the primary site');
            $this->assertSame($order->id, Order::find()->number($order->number)->one()?->id);
        }
    }

    public function testASecondSiteOrderCompletesFromItsStripeSession(): void
    {
        $order = $this->placeOrderOnSecondSite(pending: true);
        Craft::$app->getSites()->setCurrentSite($this->primary);

        // The webhook arrives on a request that knows nothing about the site the donor used.
        $this->assertNotNull(Order::find()->stripeCheckoutSessionId($order->stripeCheckoutSessionId)->one());
        $this->stripe->pay($order->stripeCheckoutSessionId);
        $this->assertTrue(Plugin::getInstance()->orders->completeOrder($order));

        $done = Order::find()->id($order->id)->one();
        $this->assertSame(Order::STATE_COMPLETED, $done->state);
        $this->assertSame($this->second->id, $done->orderSiteId);
    }

    public function testTheCheckoutSessionIsDescribedWithTheSiteTheDonorUsed(): void
    {
        $this->placeOrderOnSecondSite(pending: true);

        $calls = $this->stripe->callsTo('createCheckoutSession');
        $description = $calls[array_key_last($calls)][0]['payment_intent_data']['description'];

        $this->assertStringContainsString($this->second->getName(), $description);
    }

    public function testASecondSiteOrderKeepsItsAddresses(): void
    {
        Craft::$app->getSites()->setCurrentSite($this->second);
        $order = $this->makeOrder(null, '25', true);
        Craft::$app->getSites()->setCurrentSite($this->primary);

        $found = Order::find()->id($order->id)->one();
        $this->assertSame('Lovelace', $found->getBillingAddress()?->lastName);
    }

    public function testErasingADonorReachesOrdersPlacedOnOtherSites(): void
    {
        $order = $this->placeOrderOnSecondSite(pending: true);
        $this->stripe->pay($order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);
        Craft::$app->getSites()->setCurrentSite($this->primary);

        $result = Plugin::getInstance()->privacy->anonymiseDonor('donor@example.test');

        $this->assertSame(1, $result['orders']);
        $this->assertNotNull(Order::find()->id($order->id)->one()?->dateAnonymised);
    }

    public function testDeletingASiteDoesNotDeleteTheOrdersPlacedOnIt(): void
    {
        $order = $this->placeOrderOnSecondSite();
        $siteId = $this->second->id;
        Craft::$app->getSites()->setCurrentSite($this->primary);
        Craft::$app->getSites()->deleteSite($this->second);
        Craft::$app->getIsMultiSite(true, true);
        $this->second = null;

        $this->assertNotNull(Order::find()->id($order->id)->one(), 'the order and its payment history outlive the site');

        // Craft removes a deleted site for good during garbage collection.
        Craft::$app->getDb()->createCommand()->delete(CraftTable::SITES, ['id' => $siteId])->execute();

        $this->assertNull(Order::find()->id($order->id)->one()?->orderSiteId);
    }

    private function placeOrderOnSecondSite(bool $pending = false): Order
    {
        Craft::$app->getSites()->setCurrentSite($this->second);

        if ($pending) {
            return $this->makePendingOrder();
        }

        return $this->makeOrder();
    }

    private function addSecondSite(): Site
    {
        $sites = Craft::$app->getSites();
        $site = new Site([
            'groupId' => $sites->getAllGroups()[0]->id,
            'name' => 'Second Site',
            'handle' => 'secondSite',
            'language' => 'en-GB',
            'hasUrls' => true,
            'baseUrl' => 'https://second.example.test/',
            'primary' => false,
        ]);

        if (!$sites->saveSite($site, false)) {
            throw new \RuntimeException('Could not add the second test site: ' . json_encode($site->getErrors()));
        }

        // Craft remembers whether the install has several sites; without this the queries behave as on a single site.
        Craft::$app->getIsMultiSite(true, true);

        return $site;
    }
}
