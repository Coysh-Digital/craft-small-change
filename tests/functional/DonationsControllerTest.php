<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\functional;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\services\Orders;
use coyshdigital\smallchange\services\Stripe;
use coyshdigital\smallchange\tests\FakeEmails;
use coyshdigital\smallchange\tests\FakeStripe;
use coyshdigital\smallchange\tests\Fixtures;
use coyshdigital\smallchange\tests\FunctionalTester;
use Craft;
use craft\elements\User;

/**
 * The donation controller over HTTP: routing, hashed inputs, JSON and redirect responses.
 */
final class DonationsControllerTest extends Unit
{
    use Fixtures;

    protected FunctionalTester $tester;

    protected function _before(): void
    {
        // One fake for every request in the test: the connector rebuilds the app per request.
        Craft::$container->setSingleton(Stripe::class, FakeStripe::class);
        Craft::$container->setSingleton(Emails::class, FakeEmails::class);

        $this->useFakes();
        $this->stripe = Craft::$container->get(Stripe::class);
        $this->emails = Craft::$container->get(Emails::class);
        Plugin::getInstance()->set('stripe', $this->stripe);
        Plugin::getInstance()->set('emails', $this->emails);

        // The connector builds a fresh app per request, so presets go through the container.
        Craft::$app->getCache()->flush();
        Craft::$container->set(Settings::class, ['currency' => 'GBP', 'giftAidEnabled' => true, 'charityName' => 'Small Church']);
    }

    protected function _after(): void
    {
        Craft::$container->clear(Stripe::class);
        Craft::$container->clear(Emails::class);
        Craft::$container->clear(Settings::class);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function form(int $productId, array $overrides = []): array
    {
        $security = Craft::$app->getSecurity();

        return $overrides + [
            'action' => 'small-change/donations/start',
            'productId' => $productId,
            'amount' => '25',
            'email' => 'donor@example.test',
            'returnUrl' => $security->hashData('donate/complete'),
            'paymentUrl' => $security->hashData('donate/payment'),
            'redirect' => $security->hashData('donate/payment?number={number}'),
        ];
    }

    public function testTheThirtyFirstAttemptInTheWindowIsRefused(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');

        for ($i = 0; $i < 30; $i++) {
            // Invalid on purpose: attempts count whether or not they succeed.
            $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['amount' => '']));
            $this->tester->seeResponseCodeIs(400);
        }

        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id));
        $this->tester->seeResponseCodeIs(429);
        $this->assertSame([], $this->stripe->sessions, 'nothing was created for the refused attempt');
    }

    public function testAValidDonationReturnsTheOrderNumberAndStartsASession(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id));

        $this->tester->seeResponseCodeIs(200);
        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertArrayHasKey('number', $response, $this->tester->grabPageSource());
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{10}$/', $response['number']);
        $this->assertSame('donate/payment?number=' . $response['number'], $response['redirect'], 'the {number} placeholder is filled in');

        $order = Order::find()->number($response['number'])->one();
        $this->assertSame(Order::STATE_PENDING, $order->state);
        $this->assertSame(2500, $order->total);
        $this->assertCount(1, $this->stripe->sessions);
    }

    public function testDonationFieldsAreSavedWithTheOrder(): void
    {
        $this->giveOrderFields(['dietary' => false], Orders::LAYOUT_DONATION);
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');

        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['fields' => ['dietary' => 'In memory of Ann']]));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertArrayHasKey('number', $response, $this->tester->grabPageSource());
        $order = Order::find()->number($response['number'])->one();
        $this->assertSame('In memory of Ann', $order->getFieldValue('dietary'));
    }

    public function testARequiredDonationFieldMustBeAnswered(): void
    {
        $this->giveOrderFields(['dietary' => true], Orders::LAYOUT_DONATION);
        $product = $this->makeProduct();
        $before = (int) Order::find()->count();
        $this->tester->haveHttpHeader('Accept', 'application/json');

        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertArrayNotHasKey('number', $response);
        $this->assertArrayHasKey('dietary', $response['errors'], $this->tester->grabPageSource());
        $this->assertSame($before, (int) Order::find()->count());
        $this->assertSame([], $this->stripe->sessions);
    }

    public function testCheckoutFieldsPostedToADonationAreIgnored(): void
    {
        $this->giveOrderFields(['giftMessage' => true]);
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');

        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['fields' => ['giftMessage' => 'sneaky']]));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertArrayHasKey('number', $response, 'the basket question is not asked of a donor: ' . $this->tester->grabPageSource());
        $order = Order::find()->number($response['number'])->one();
        $this->assertSame([], $order->getFieldLayout()?->getCustomFields());
    }

    public function testASignedInDonorsGiftIsAttachedToTheirAccount(): void
    {
        $user = new User(['username' => 'donor', 'email' => 'donor@example.test']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user, false));
        $product = $this->makeProduct();
        $this->tester->amLoggedInAs($user);
        $this->tester->haveHttpHeader('Accept', 'application/json');

        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertArrayHasKey('number', $response, $this->tester->grabPageSource());
        $this->assertSame($user->id, Order::find()->number($response['number'])->one()->userId);
    }

    public function testAGuestsGiftHasNoUser(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');

        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertNull(Order::find()->number($response['number'])->one()->userId);
    }

    public function testTheResponseNeverIncludesTheOrderRecord(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id));

        $body = $this->tester->grabPageSource();

        foreach (['stripeCheckoutSessionId', 'cs_test_', 'lastIp', 'customerId', 'client_secret'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "$leak must not be in the response");
        }
    }

    public function testValidationErrorsComeBackAsJsonAndCreateNothing(): void
    {
        $product = $this->makeProduct();
        $before = (int) Order::find()->count();

        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['amount' => '', 'email' => 'nope']));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertArrayNotHasKey('number', $response);
        $this->assertArrayHasKey('amount', $response['errors']);
        $this->assertArrayHasKey('email', $response['errors']);
        $this->assertSame($before, (int) Order::find()->count());
        $this->assertSame([], $this->stripe->sessions);
    }

    public function testTamperedHiddenValuesAreRefused(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');

        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['returnUrl' => 'https://evil.test/']));
        $this->tester->seeResponseCodeIs(400);

        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['paymentUrl' => '//evil.test']));
        $this->tester->seeResponseCodeIs(400);
    }

    public function testAnUnknownProductIsABadRequest(): void
    {
        $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form(99999999));

        $this->tester->seeResponseCodeIs(400);
    }

    public function testGetIsNotAllowed(): void
    {
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxGetRequest('/actions/small-change/donations/start');

        $this->tester->seeResponseCodeIsClientError();
        $this->assertSame([], $this->stripe->sessions);
    }

    public function testGiftAidWithoutAnAddressIsRefused(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['giftAid' => '1']));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertArrayNotHasKey('number', $response);
        $this->assertArrayHasKey('billingAddress.postalCode', $response['errors']);
    }

    public function testGiftAidWithAnAddressIsAccepted(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, [
            'giftAid' => '1',
            'billingAddress' => ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'addressLine1' => '12 St James Square', 'postalCode' => 'SW1Y 4JH'],
        ]));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertArrayHasKey('number', $response, $this->tester->grabPageSource());
        $order = Order::find()->number($response['number'])->one();
        $this->assertTrue($order->giftAidDeclared);
        $this->assertSame('SW1Y 4JH', $order->getBillingAddress()?->postalCode);
    }

    public function testTheSuggestedAmountIsUsedWhenNothingIsTyped(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['amount' => '', 'suggestedAmount' => '10.00']));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertSame(1000, Order::find()->number($response['number'])->one()->total);
    }

    public function testATypedAmountBeatsASuggestedOne(): void
    {
        $product = $this->makeProduct();
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id, ['amount' => '7', 'suggestedAmount' => '10.00']));

        $response = json_decode($this->tester->grabPageSource(), true);
        $this->assertSame(700, Order::find()->number($response['number'])->one()->total);
    }

    public function testAStripeOutageIsReportedWithoutLeakingDetail(): void
    {
        $product = $this->makeProduct();
        $this->stripe->failCreate = true;
        $this->tester->haveHttpHeader('Accept', 'application/json');
        $this->tester->sendAjaxPostRequest('/actions/small-change/donations/start', $this->form((int) $product->id));

        $body = $this->tester->grabPageSource();
        $this->assertStringContainsString('try again', $body);
        $this->assertStringNotContainsString('network down', $body);
    }
}
