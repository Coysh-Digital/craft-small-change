<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\events\EmailEvent;
use coyshdigital\smallchange\jobs\SendEmail;
use coyshdigital\smallchange\jobs\SendPortalLink;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use coyshdigital\smallchange\tests\Fixtures;
use coyshdigital\smallchange\tests\RecordingMailer;
use Craft;
use yii\base\Event;

final class EmailsTest extends Unit
{
    use Fixtures;

    private RecordingMailer $mailer;
    private Emails $service;

    protected function _before(): void
    {
        $this->useFakes();

        $this->mailer = new RecordingMailer(['from' => 'site@example.test']);
        Craft::$app->set('mailer', $this->mailer);

        // The real service, not the recording one the other tests use.
        $this->service = new Emails();
        Plugin::getInstance()->set('emails', $this->service);
    }

    protected function _after(): void
    {
        $override = CRAFT_TEMPLATES_PATH . '/_small-change';

        if (is_dir($override)) {
            array_map('unlink', glob($override . '/emails/*') ?: []);
            @rmdir($override . '/emails');
            @rmdir($override);
        }
    }

    private function paidOrder(bool $giftAid = true, string $amount = '25'): \coyshdigital\smallchange\elements\Order
    {
        $order = $this->makePendingOrder($amount, $giftAid);
        $this->stripe->pay((string) $order->stripeCheckoutSessionId);
        Plugin::getInstance()->orders->completeOrder($order);

        return \coyshdigital\smallchange\elements\Order::find()->id($order->id)->one();
    }

    public function testTheReceiptGoesToTheDonorWithTheRightContent(): void
    {
        $order = $this->paidOrder();

        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order));

        $this->assertCount(1, $this->mailer->sent);
        $message = $this->mailer->sent[0];
        $this->assertSame(['donor@example.test'], array_keys($message->getTo()));
        $this->assertStringStartsWith('Your receipt from ', $message->getSubject());

        $body = $this->bodyOf($message);
        $this->assertStringContainsString($order->number, $body['html']);
        $this->assertStringContainsString('£25.00', $body['html']);
        $this->assertStringContainsString('General fund', $body['html']);
        $this->assertStringContainsString('Gift Aid', $body['html']);
        $this->assertStringContainsString('Ada Lovelace', $body['html']);
        $this->assertStringContainsString('I want to Gift Aid my donation', $body['html']);
        $this->assertStringContainsString('Small Church', $body['html']);
    }

    public function testTheReceiptNeverLinksToAnOrderPage(): void
    {
        $order = $this->paidOrder();
        $this->service->send(Emails::KEY_RECEIPT, $order);

        $html = $this->bodyOf($this->mailer->sent[0])['html'];

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('http', $html);
    }

    public function testTheTextVersionHasNoMarkup(): void
    {
        $order = $this->paidOrder();
        $this->service->send(Emails::KEY_RECEIPT, $order);

        $text = $this->bodyOf($this->mailer->sent[0])['text'];

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('Reference: ' . $order->number, $text);
        $this->assertStringContainsString('£25.00', $text);
    }

    public function testADonorWhoSaidNoIsToldSo(): void
    {
        $order = $this->paidOrder(false);
        $this->service->send(Emails::KEY_RECEIPT, $order);

        $html = $this->bodyOf($this->mailer->sent[0])['html'];

        $this->assertStringContainsString('You chose not to Gift Aid', $html);
        $this->assertStringNotContainsString('I want to Gift Aid my donation', $html);
    }

    public function testNoGiftAidTextWhenItWasNeverOffered(): void
    {
        Plugin::getInstance()->getSettings()->giftAidEnabled = false;
        $order = $this->paidOrder(false);
        $this->service->send(Emails::KEY_RECEIPT, $order);

        $this->assertStringNotContainsString('Gift Aid', $this->bodyOf($this->mailer->sent[0])['html']);
    }

    public function testTheReceiptIsSentOnceNoMatterHowOftenItIsAttempted(): void
    {
        $order = $this->paidOrder();

        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order));
        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order));
        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order));

        $this->assertCount(1, $this->mailer->sent);
        $this->assertNotNull(Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id)[0]->receiptSentAt);
    }

    public function testEachPaymentOnAnOrderGetsItsOwnReceiptEvenIfTheyLandTogether(): void
    {
        $order = $this->paidOrder();
        $payments = Plugin::getInstance()->payments;
        $first = $payments->getPaymentsByOrderId((int) $order->id)[0];

        // A renewal arrives before the first receipt has been sent.
        $this->stripe->intents['pi_renewal'] = ['id' => 'pi_renewal', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 2500, 'amount_received' => 2500, 'currency' => 'gbp', 'latest_charge' => 'ch_renewal', 'last_payment_error' => null, 'metadata' => []];
        $second = $payments->recordPaymentIntent($order, \Stripe\StripeObject::constructFrom($this->stripe->intents['pi_renewal']), 'test');

        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order, ['paymentId' => $first->id]));
        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order, ['paymentId' => $second->id]));
        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order, ['paymentId' => $second->id]), 'a repeated job is harmless');

        $this->assertCount(2, $this->mailer->sent);
        $this->assertNotNull($payments->getPaymentById((int) $first->id)->receiptSentAt);
        $this->assertNotNull($payments->getPaymentById((int) $second->id)->receiptSentAt);
    }

    public function testNoReceiptBeforeThereIsASucceededPayment(): void
    {
        $order = $this->makePendingOrder();

        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order));
        $this->assertCount(0, $this->mailer->sent);
    }

    public function testTheAdminNoticeNeedsAnAddressAndLinksToTheControlPanel(): void
    {
        $order = $this->paidOrder();

        $this->service->send(Emails::KEY_ADMIN_NEW_ORDER, $order);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame(['admin@example.test'], array_keys($this->mailer->sent[0]->getTo()));
        $this->assertStringContainsString('New donation ' . $order->number, $this->mailer->sent[0]->getSubject());
        $this->assertStringContainsString('small-change/orders/' . $order->id, $this->bodyOf($this->mailer->sent[0])['html']);

        Plugin::getInstance()->getSettings()->adminEmail = '';
        $this->service->send(Emails::KEY_ADMIN_NEW_ORDER, $order);
        $this->assertCount(1, $this->mailer->sent, 'no admin address, no notice');
    }

    public function testThePaymentFailedEmail(): void
    {
        $order = $this->makePendingOrder();
        $this->service->send(Emails::KEY_PAYMENT_FAILED, $order);

        $this->assertCount(1, $this->mailer->sent);
        $this->assertStringContainsString("We couldn't take your payment to", $this->mailer->sent[0]->getSubject());
        $this->assertStringContainsString("you haven't been charged", $this->bodyOf($this->mailer->sent[0])['html']);
    }

    public function testAnOverrideCanExtendTheShippedLayoutAndIncludeTheSummary(): void
    {
        $dir = CRAFT_TEMPLATES_PATH . '/_small-change/emails';
        mkdir($dir, 0775, true);
        // A different template from the other override test: Twig keeps a compiled template per name for the whole process.
        file_put_contents($dir . '/admin-new-order.twig', "{% extends 'small-change/emails/_layout' %}{% block body %}Mine {{ order.number }}{% include 'small-change/emails/_summary' %}{% endblock %}");

        $order = $this->paidOrder();
        $this->assertTrue($this->service->send(Emails::KEY_ADMIN_NEW_ORDER, $order));

        $body = $this->bodyOf($this->mailer->sent[0]);
        $this->assertStringContainsString('Mine ' . $order->number, $body['html']);
        $this->assertStringContainsString('£25.00', $body['html']);
    }

    public function testASiteCanOverrideATemplateAndSettingsControlTheEnvelope(): void
    {
        $dir = CRAFT_TEMPLATES_PATH . '/_small-change/emails';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/receipt.twig', 'Custom receipt for {{ order.number }} from {{ siteName }}');

        $settings = Plugin::getInstance()->getSettings();
        $settings->emailFromAddress = 'giving@example.test';
        $settings->emailFromName = 'Small Church Giving';
        $settings->emailReplyTo = 'office@example.test';
        $settings->receiptSubject = 'Thanks from {siteName}';

        $order = $this->paidOrder();
        $this->service->send(Emails::KEY_RECEIPT, $order);

        $message = $this->mailer->sent[0];
        $this->assertSame('Custom receipt for ' . $order->number . ' from ' . Craft::$app->getSites()->getCurrentSite()->getName(), $this->bodyOf($message)['html']);
        $this->assertSame(['giving@example.test' => 'Small Church Giving'], $message->getFrom());
        $this->assertSame(['office@example.test'], array_keys($message->getReplyTo()));
        $this->assertStringStartsWith('Thanks from ', $message->getSubject());
    }

    public function testAListenerCanCancelAnEmail(): void
    {
        Event::on(Emails::class, Emails::EVENT_BEFORE_SEND, static function(EmailEvent $e): void {
            $e->isValid = false;
        });

        $order = $this->paidOrder();
        $this->service->send(Emails::KEY_RECEIPT, $order);
        Event::off(Emails::class, Emails::EVENT_BEFORE_SEND);

        $this->assertCount(0, $this->mailer->sent);
    }

    public function testTheQueueJobSendsTheEmail(): void
    {
        $order = $this->paidOrder();

        (new SendEmail(['key' => Emails::KEY_RECEIPT, 'orderId' => (int) $order->id]))->execute(Craft::$app->getQueue());

        $this->assertCount(1, $this->mailer->sent);
    }

    public function testTheQueueJobIgnoresAnOrderThatNoLongerExists(): void
    {
        (new SendEmail(['key' => Emails::KEY_RECEIPT, 'orderId' => 99999999]))->execute(Craft::$app->getQueue());

        $this->assertCount(0, $this->mailer->sent);
    }

    public function testThePortalLinkIsQueuedAndOnlySignedWhenTheJobRuns(): void
    {
        $customer = Plugin::getInstance()->customers->findOrCreate('donor@example.test', 'test');
        $queue = Craft::$app->getQueue();
        $before = $queue->getTotalJobs();

        $this->service->queuePortalLink($customer, 'https://example.test/account');

        // The visitor's request does no sending, so it takes as long for an address with a donation as for one without.
        $this->assertSame($before + 1, $queue->getTotalJobs());
        $this->assertCount(0, $this->mailer->sent);

        (new SendPortalLink(['customerId' => (int) $customer->id, 'returnUrl' => 'https://example.test/account']))->execute($queue);

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame(['donor@example.test'], array_keys($this->mailer->sent[0]->getTo()));
        $html = $this->bodyOf($this->mailer->sent[0])['html'];
        $this->assertStringContainsString('small-change/portal/open', $html);
        $this->assertStringContainsString('link=', $html);
    }

    public function testThePortalLinkJobSkipsACustomerWhoHasBeenErased(): void
    {
        (new SendPortalLink(['customerId' => 99999999]))->execute(Craft::$app->getQueue());

        $this->assertCount(0, $this->mailer->sent);
    }

    public function testTheMasterSwitchStopsEveryEmail(): void
    {
        $order = $this->paidOrder();
        Plugin::getInstance()->getSettings()->sendEmails = false;

        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT, $order));
        $this->assertTrue($this->service->send(Emails::KEY_RECEIPT_RESEND, $order));
        $this->assertTrue($this->service->send(Emails::KEY_ADMIN_NEW_ORDER, $order));
        $this->assertTrue($this->service->sendPortalLink('donor@example.test', 'https://example.test/link'));
        $this->assertCount(0, $this->mailer->sent);

        $queue = Craft::$app->getQueue();
        $before = $queue->getTotalJobs();
        $this->service->queue(Emails::KEY_RECEIPT, $order);
        $this->assertSame($before, $queue->getTotalJobs(), 'nothing is queued either');

        Plugin::getInstance()->getSettings()->sendEmails = true;
        $this->service->queue(Emails::KEY_RECEIPT, $order);
        $this->assertSame($before + 1, $queue->getTotalJobs());
    }

    public function testOneEmailCanBeSwitchedOffWithoutLosingTheReceipt(): void
    {
        $order = $this->paidOrder();
        $settings = Plugin::getInstance()->getSettings();
        $settings->disabledEmails = [Emails::KEY_RECEIPT];

        $this->service->send(Emails::KEY_RECEIPT, $order);
        $this->assertCount(0, $this->mailer->sent);

        $this->service->send(Emails::KEY_ADMIN_NEW_ORDER, $order);
        $this->assertCount(1, $this->mailer->sent, 'the others still go');

        $payments = Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id);
        $this->assertNull($payments[0]->receiptSentAt, 'a skipped receipt is not recorded as sent');

        $settings->disabledEmails = [];
        $this->service->send(Emails::KEY_RECEIPT, $order);
        $this->assertCount(2, $this->mailer->sent);
    }

    public function testAFromNameOnItsOwnIsPairedWithCraftsAddress(): void
    {
        $service = new class() extends Emails {
            protected function systemFromAddress(): string
            {
                return 'system@example.test';
            }
        };
        Plugin::getInstance()->set('emails', $service);
        Plugin::getInstance()->getSettings()->emailFromName = 'Small Church Giving';

        $service->send(Emails::KEY_RECEIPT, $this->paidOrder());

        $this->assertSame(['system@example.test' => 'Small Church Giving'], $this->mailer->sent[0]->getFrom());
    }

    public function testHtmlToText(): void
    {
        $text = $this->service->toText('<h1>Hi</h1><p>One &amp; two</p><table><tr><td>A</td><td>£5</td></tr></table>');

        $this->assertSame("Hi\nOne & two\nA £5", $text);
    }

    public function testLinksSurviveInThePlainTextVersion(): void
    {
        $text = $this->service->toText('<p><a href="https://example.test/a?x=1&amp;y=2">Manage my donation</a></p><p><a href="https://example.test/b">https://example.test/b</a></p>');

        $this->assertSame("Manage my donation: https://example.test/a?x=1&y=2\nhttps://example.test/b", $text);
    }

    public function testWordingFromTheControlPanelReplacesTheBuiltInWording(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $settings->emailContent = [Emails::KEY_RECEIPT => [
            'subject' => 'Thank you, {firstName}',
            'heading' => 'Bless you, {firstName}',
            'message' => "Your gift of {amount} to {charityName} matters.\n\nOrder {orderNumber}. <b>Not bold</b>",
        ]];

        $order = $this->paidOrder();
        $this->service->send(Emails::KEY_RECEIPT, $order);

        $message = $this->mailer->sent[0];
        $html = $this->bodyOf($message)['html'];
        $this->assertSame('Thank you, Ada', $message->getSubject());
        $this->assertStringContainsString('Bless you, Ada</h1>', $html);
        $this->assertStringContainsString('<p>Your gift of £25.00 to Small Church matters.</p>', $html);
        $this->assertStringContainsString('<p>Order ' . $order->number . '. &lt;b&gt;Not bold&lt;/b&gt;</p>', $html, 'text from the control panel is escaped');
        $this->assertStringNotContainsString('This is your receipt from', $html);
        $this->assertStringContainsString('General fund', $html, 'the order summary is always there');
        $this->assertStringContainsString('I want to Gift Aid my donation', $html, 'and so is the declaration');
    }

    public function testBlankWordingKeepsTheBuiltInWordingAndTheOldReceiptSubject(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $settings->emailContent = [Emails::KEY_RECEIPT => ['subject' => '', 'heading' => '', 'message' => '']];
        $settings->receiptSubject = 'Receipt {orderNumber} from {siteName}';

        $order = $this->paidOrder();
        $this->service->send(Emails::KEY_RECEIPT, $order);

        $message = $this->mailer->sent[0];
        $this->assertStringStartsWith('Receipt ' . $order->number . ' from ', $message->getSubject());
        $this->assertStringContainsString('Thank you for your gift', $this->bodyOf($message)['html']);
    }

    public function testAPreviewSendsAndRecordsNothing(): void
    {
        $order = $this->paidOrder();
        $preview = $this->service->preview(Emails::KEY_RECEIPT, ['heading' => 'Unsaved heading']);

        $this->assertSame($order->id, $preview['order']?->id, 'the latest order is used');
        $this->assertStringContainsString('Unsaved heading', $preview['html']);
        $this->assertCount(0, $this->mailer->sent);

        foreach (Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id) as $payment) {
            $this->assertNull($payment->receiptSentAt);
        }
    }

    public function testEveryEmailCanBePreviewedBeforeThereAreAnyOrders(): void
    {
        foreach (array_keys(Emails::definitions()) as $key) {
            $preview = $this->service->preview($key);

            $this->assertNull($preview['order'], $key);
            $this->assertNotSame('', $preview['subject'], $key);
            $this->assertStringContainsString('</h1>', $preview['html'], $key);
        }

        $this->assertCount(0, $this->mailer->sent);
    }

    /**
     * @return array{html: string, text: string}
     */
    private function bodyOf(\craft\mail\Message $message): array
    {
        $html = '';
        $text = '';
        $reflection = new \ReflectionClass(\yii\symfonymailer\Message::class);
        $symfony = $reflection->getMethod('getSymfonyEmail')->invoke($message);

        $html = (string) $symfony->getHtmlBody();
        $text = (string) $symfony->getTextBody();

        return ['html' => $html, 'text' => $text];
    }
}
