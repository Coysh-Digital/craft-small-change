<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\functional;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\services\Stripe;
use coyshdigital\smallchange\tests\FakeEmails;
use coyshdigital\smallchange\tests\FakeStripe;
use coyshdigital\smallchange\tests\Fixtures;
use coyshdigital\smallchange\tests\FunctionalTester;
use Craft;
use craft\elements\User;

/**
 * The donor-facing portal: asking for a link, and following it.
 */
final class PortalControllerTest extends Unit
{
    use Fixtures;

    protected FunctionalTester $tester;

    protected function _before(): void
    {
        Craft::$container->setSingleton(Stripe::class, FakeStripe::class);
        Craft::$container->setSingleton(Emails::class, FakeEmails::class);

        $this->useFakes();
        $this->stripe = Craft::$container->get(Stripe::class);
        $this->emails = Craft::$container->get(Emails::class);
        Plugin::getInstance()->set('stripe', $this->stripe);
        Plugin::getInstance()->set('emails', $this->emails);

        Craft::$app->getCache()->flush();
        Craft::$container->set(Settings::class, ['currency' => 'GBP', 'giftAidEnabled' => true, 'charityName' => 'Small Church']);
    }

    protected function _after(): void
    {
        Craft::$container->clear(Stripe::class);
        Craft::$container->clear(Emails::class);
        Craft::$container->clear(Settings::class);
    }

    private function startRecurring(?User $user = null): string
    {
        $product = $this->makeProduct();
        $product->allowRecurring = true;
        $product->recurringIntervals = ['month'];
        Craft::$app->getElements()->saveElement($product);
        $order = Plugin::getInstance()->orders->buildDonation($product, '10', 'monthly@example.test', false, null, 'donate/thanks', 'month');
        $order->userId = $user?->id;
        Craft::$app->getElements()->saveElement($order);
        Plugin::getInstance()->checkout->start($order, 'donate/payment');
        $this->stripe->paySubscription((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        return (string) Order::find()->id($order->id)->one()->number;
    }

    private function requestLink(string $email): void
    {
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/portal/request-link', ['action' => 'small-change/portal/request-link', 'email' => $email]);
    }

    private function linkToken(): string
    {
        parse_str((string) parse_url($this->emails->portalLinks[0][1], PHP_URL_QUERY), $query);

        return $query['link'];
    }

    public function testAKnownDonorIsEmailedALink(): void
    {
        $this->startRecurring();
        $this->requestLink('Monthly@Example.test');

        $this->tester->seeResponseCodeIs(200);
        $this->assertCount(1, $this->emails->portalLinks);
        $this->assertSame('monthly@example.test', $this->emails->portalLinks[0][0]);
    }

    public function testNoLinkIsPromisedWhenTheEmailIsSwitchedOff(): void
    {
        Craft::$container->set(Settings::class, ['currency' => 'GBP', 'giftAidEnabled' => true, 'charityName' => 'Small Church', 'disabledEmails' => [Emails::KEY_PORTAL_LINK]]);
        $this->startRecurring();
        $this->requestLink('monthly@example.test');

        $this->tester->seeResponseCodeIs(400);
        $this->assertCount(0, $this->emails->portalLinks);
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNoEmail(): void
    {
        $this->startRecurring();
        $this->requestLink('monthly@example.test');
        $known = $this->tester->grabPageSource();

        $this->requestLink('stranger@example.test');

        $this->tester->seeResponseCodeIs(200);
        $this->assertSame($known, $this->tester->grabPageSource(), 'the answer must not reveal who donates');
        $this->assertCount(1, $this->emails->portalLinks);
    }

    public function testSomeoneWithOnlyAOneOffDonationGetsNoLink(): void
    {
        $order = $this->makePendingOrder();
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        $this->requestLink('donor@example.test');

        $this->assertCount(0, $this->emails->portalLinks);
    }

    public function testRequestsAreRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->requestLink('nobody@example.test');
            $this->tester->seeResponseCodeIs(200);
        }

        $this->requestLink('nobody@example.test');
        $this->tester->seeResponseCodeIs(429);
    }

    public function testTheLinkOpensTheStripePortal(): void
    {
        $this->startRecurring();
        $this->requestLink('monthly@example.test');

        $this->tester->stopFollowingRedirects();
        $this->tester->amOnPage('/index.php?action=small-change/portal/open&link=' . urlencode($this->linkToken()));

        $this->tester->seeResponseCodeIs(302);
        $this->assertCount(1, $this->stripe->callsTo('createPortalSession'));
        [$customer, $return] = $this->stripe->callsTo('createPortalSession')[0];
        $this->assertStringStartsWith('cus_test_', $customer);
        $this->assertNotSame('', $return);
    }

    public function testASignedInDonorOpensThePortalForTheirOwnRecurringGift(): void
    {
        $user = new User(['username' => 'monthly', 'email' => 'monthly-account@example.test']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user, false));
        $this->startRecurring($user);

        $this->tester->amLoggedInAs($user);
        $this->tester->stopFollowingRedirects();
        $this->tester->amOnPage('/index.php?action=small-change/portal/open');

        $this->tester->seeResponseCodeIs(302);
        $this->assertCount(1, $this->stripe->callsTo('createPortalSession'));
    }

    public function testAnAccountWithADonorsEmailAddressGetsNoWayIntoTheirPortal(): void
    {
        $this->startRecurring();

        // Registers with the donor's address on a site that doesn't verify them.
        $impostor = new User(['username' => 'impostor', 'email' => 'monthly@example.test']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($impostor, false));

        $this->tester->amLoggedInAs($impostor);
        $this->tester->amOnPage('/index.php?action=small-change/portal/open');

        $this->tester->seeResponseCodeIs(400);
        $this->assertSame([], $this->stripe->callsTo('createPortalSession'));
    }

    public function testAForgedOrMissingTokenIsRefused(): void
    {
        $this->startRecurring();

        foreach (['', 'garbage', 'abc.def'] as $token) {
            $this->tester->amOnPage('/index.php?action=small-change/portal/open&link=' . $token);
            $this->tester->seeResponseCodeIs(400);
        }

        $this->assertSame([], $this->stripe->callsTo('createPortalSession'));
    }

    public function testATokenSignedForSomethingElseIsRefused(): void
    {
        $this->startRecurring();
        $expired = Craft::$app->getSecurity()->hashData(\craft\helpers\StringHelper::base64UrlEncode((string) json_encode(['c' => 1, 'e' => time() - 10, 'r' => null])));

        $this->tester->amOnPage('/index.php?action=small-change/portal/open&link=' . urlencode($expired));

        $this->tester->seeResponseCodeIs(400);
        $this->assertSame([], $this->stripe->callsTo('createPortalSession'));
    }

    public function testAStripeFailureIsAnErrorPageNotALeak(): void
    {
        $this->startRecurring();
        $this->requestLink('monthly@example.test');
        $this->stripe->failPortal = true;

        $this->tester->amOnPage('/index.php?action=small-change/portal/open&link=' . urlencode($this->linkToken()));

        $this->tester->seeResponseCodeIs(500);
        $this->tester->dontSee('network down');
    }
}
