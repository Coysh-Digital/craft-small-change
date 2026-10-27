<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\events\WebhookEvent;
use coyshdigital\smallchange\models\Payment;
use coyshdigital\smallchange\models\Refund;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\WebhookEventRecord;
use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use Stripe\Event as StripeEvent;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeObject;
use Stripe\Webhook;
use Throwable;
use yii\db\IntegrityException;

/**
 * Webhooks are the source of truth for payment state. Every verified event is stored first, so a
 * duplicate delivery is recognised, a failed one can be replayed, and the log shows what happened.
 */
class Webhooks extends Component
{
    public const EVENT_BEFORE_PROCESS_EVENT = 'beforeProcessEvent';
    public const EVENT_AFTER_PROCESS_EVENT = 'afterProcessEvent';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';

    /**
     * Everything the handlers above act on; the endpoint this plugin registers listens for nothing else.
     */
    public const ENABLED_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.processing',
        'charge.refunded',
        'refund.created',
        'refund.updated',
        'refund.failed',
        'charge.dispute.created',
        'charge.dispute.updated',
        'charge.dispute.closed',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
        'invoice.voided',
        'invoice.marked_uncollectible',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'customer.updated',
        'customer.deleted',
    ];

    public const STATE_ENDPOINT_ID = 'webhookEndpointId';
    public const STATE_LAST_DELIVERY = 'lastWebhookDelivery';

    /**
     * Stripe's own default: signatures older than this are refused, which blunts replays.
     */
    private const TOLERANCE = 300;

    /**
     * The most recent stored events, newest first, for the control panel.
     *
     * @return WebhookEventRecord[]
     */
    public function recentEvents(int $limit = 50): array
    {
        /** @var WebhookEventRecord[] $records */
        $records = WebhookEventRecord::find()->orderBy(['id' => SORT_DESC])->limit($limit)->all();

        return $records;
    }

    /**
     * Whether Stripe has ever sent this site a signed event with the key type in use now. This is what "the
     * webhook is connected" means: a secret in the environment only says one has been configured.
     */
    public function hasReceivedDelivery(): bool
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();

        return $mode !== null && $plugin->state->get(self::STATE_LAST_DELIVERY, $mode) !== null;
    }

    /**
     * Verifies, stores and processes one delivery.
     *
     * @return array{status: int, message: string} what to answer Stripe with
     */
    public function handleRequest(string $payload, ?string $signatureHeader): array
    {
        $plugin = Plugin::getInstance();
        $secret = $plugin->stripe->webhookSecret();

        if ($secret === null) {
            return ['status' => 403, 'message' => 'The webhook signing secret is not configured.'];
        }

        // Newer Stripe endpoints default to "thin" events, which carry no object to act on. Older
        // SDK majors don't spot them, so look at the payload itself rather than trusting the SDK.
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return ['status' => 400, 'message' => 'The payload is not valid JSON.'];
        }

        if (($decoded['object'] ?? null) === 'v2.core.event') {
            return ['status' => 400, 'message' => 'This endpoint must use the Snapshot payload format, not Thin.'];
        }

        try {
            $event = Webhook::constructEvent($payload, (string) $signatureHeader, $secret, self::TOLERANCE);
        } catch (SignatureVerificationException) {
            return ['status' => 403, 'message' => 'The signature could not be verified.'];
        } catch (\UnexpectedValueException) {
            return ['status' => 400, 'message' => 'The payload could not be read.'];
        }

        $eventMode = $event->livemode ? Stripe::MODE_LIVE : Stripe::MODE_TEST;

        // A verified delivery proves Stripe can reach this site. Replayed events don't, so they never set this.
        $plugin->state->set(self::STATE_LAST_DELIVERY, $eventMode, (string) time());

        // Keys are per environment; an event for the other mode belongs to another install, such as a live endpoint
        // that a staging copy was registered on by mistake. The row is kept to show it happened, without its contents,
        // so a test site doesn't end up holding live donors' details.
        $foreign = $plugin->stripe->mode() !== $eventMode;

        $record = new WebhookEventRecord();
        $record->stripeEventId = $event->id;
        $record->type = $event->type;
        $record->stripeMode = $eventMode;
        $record->livemode = (bool) $event->livemode;
        $record->apiVersion = $event->api_version ?? null;
        $record->stripeObjectId = $foreign ? null : $this->objectId($event);
        $record->stripeCustomerId = $foreign ? null : $this->customerId($event);
        $record->status = self::STATUS_RECEIVED;
        $record->attempts = 0;
        $record->payload = $foreign ? (string) json_encode(['id' => $event->id, 'type' => $event->type, 'livemode' => (bool) $event->livemode, 'created' => $event->created ?? null]) : $payload;
        $record->dateReceived = Db::prepareDateForDb(new DateTime());

        // Its own (nested) transaction: on PostgreSQL a failed insert poisons whatever transaction it is in.
        $insert = Craft::$app->getDb()->beginTransaction();

        try {
            $record->save(false);
            $insert->commit();
        } catch (IntegrityException) {
            $insert->rollBack();
            $existing = WebhookEventRecord::findOne(['stripeEventId' => $event->id]);

            // Already handled, or being handled by a concurrent delivery: nothing more to do.
            if (!$existing || in_array($existing->status, [self::STATUS_PROCESSED, self::STATUS_IGNORED], true)) {
                return ['status' => 200, 'message' => 'Duplicate event.'];
            }

            // A previous attempt failed (or died part-way): this retry is the one that should finish it.
            $record = $existing;
        }

        if ($foreign) {
            $this->finish($record, self::STATUS_IGNORED, 'The event is for the other Stripe mode.');

            return ['status' => 200, 'message' => 'Ignored: other mode.'];
        }

        return $this->process($record, $event);
    }

    /**
     * Where Stripe should send events: always the public site, even when asked for from the control panel
     * (where Craft's own action URLs point at the CP).
     */
    public function endpointUrl(?string $siteUrl = null): string
    {
        $path = trim(Craft::$app->getConfig()->getGeneral()->actionTrigger, '/') . '/small-change/webhooks/stripe';

        return $siteUrl !== null ? rtrim($siteUrl, '/') . '/' . $path : UrlHelper::siteUrl($path);
    }

    /**
     * Whether Stripe could reach this address from the internet. A plain http address or a development host
     * such as localhost or *.ddev.site can only get events through the Stripe CLI.
     */
    public static function isPublicUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach (['.localhost', '.test', '.local', '.ddev.site', '.lndo.site', '.internal'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Creates the webhook endpoint at Stripe for this site. The signing secret is only ever shown once,
     * at creation, so it is returned for the caller to show and is not stored.
     *
     * @return array{id: string, secret: string, url: string}
     * @throws ApiErrorException
     */
    public function registerEndpoint(string $url): array
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();

        if ($mode === null) {
            throw new \RuntimeException('Add a Stripe secret key first.');
        }

        // Each endpoint has its own secret, so a second one for the same address would only leave Stripe signing
        // deliveries with a secret this site doesn't hold. The one registered before is kept if it still exists.
        $existingId = $plugin->state->get(self::STATE_ENDPOINT_ID, $mode);

        if ($existingId !== null) {
            try {
                $existing = $plugin->stripe->retrieveWebhookEndpoint($existingId);

                if (($existing->url ?? null) === $url && ($existing->status ?? 'enabled') !== 'disabled') {
                    throw new \RuntimeException("This site already has the endpoint {$existingId} at Stripe. Delete it in the Dashboard first if you need a new signing secret.");
                }
            } catch (ApiErrorException) {
                // Gone at Stripe, so a new one is wanted.
            }
        }

        $endpoint = $plugin->stripe->createWebhookEndpoint([
            'url' => $url,
            'enabled_events' => self::ENABLED_EVENTS,
            // Event payloads take the shape of the endpoint's API version, so pin it to the one the handlers were written for.
            'api_version' => Stripe::API_VERSION,
            'description' => 'Small Change',
        ]);
        $plugin->state->set(self::STATE_ENDPOINT_ID, $mode, (string) $endpoint->id);

        return ['id' => (string) $endpoint->id, 'secret' => (string) $endpoint->secret, 'url' => $url];
    }

    /**
     * Fetches events Stripe still holds and runs any this install never stored, which is how a gap
     * (a deploy, an outage, a wrong secret) is closed. Events already stored are left alone.
     *
     * @return array{found: int, replayed: int, failed: int}
     * @throws ApiErrorException
     */
    public function replay(int $since, ?string $type = null): array
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();
        $result = ['found' => 0, 'replayed' => 0, 'failed' => 0];

        if ($mode === null) {
            return $result;
        }

        $params = ['created' => ['gte' => $since]];

        if ($type !== null && $type !== '') {
            $params['type'] = $type;
        }

        foreach ($plugin->stripe->listEvents($params) as $event) {
            if (!in_array($event->type, self::ENABLED_EVENTS, true)) {
                continue;
            }

            $result['found']++;
            $existing = WebhookEventRecord::findOne(['stripeEventId' => $event->id]);

            if ($existing && in_array($existing->status, [self::STATUS_PROCESSED, self::STATUS_IGNORED], true)) {
                continue;
            }

            $record = $existing ?? new WebhookEventRecord();

            if (!$existing) {
                $record->stripeEventId = $event->id;
                $record->type = $event->type;
                $record->stripeMode = $mode;
                $record->livemode = (bool) $event->livemode;
                $record->apiVersion = $event->api_version ?? null;
                $record->stripeObjectId = $this->objectId($event);
                $record->stripeCustomerId = $this->customerId($event);
                $record->attempts = 0;
                $record->payload = json_encode($event->toArray(), JSON_THROW_ON_ERROR);
                $record->dateReceived = Db::prepareDateForDb(new DateTime());
            }

            $record->status = self::STATUS_RECEIVED;
            $record->save(false);

            $outcome = $this->process($record, $event);
            $outcome['status'] === 200 ? $result['replayed']++ : $result['failed']++;
        }

        return $result;
    }

    /**
     * Runs a stored event through its handler and records the outcome. Stripe can deliver one event twice at
     * once (it retries after a slow answer), so the handlers run under a lock: the second delivery waits, then
     * finds the first one's outcome and has nothing left to do.
     *
     * @return array{status: int, message: string}
     */
    public function process(WebhookEventRecord $record, StripeEvent $event): array
    {
        $mutex = Craft::$app->getMutex();
        $lock = 'smallchange:event:' . $event->id;

        if (!$mutex->acquire($lock, 10)) {
            return ['status' => 503, 'message' => 'This event is being handled by another request; Stripe will retry.'];
        }

        try {
            $record->refresh();

            if (in_array($record->status, [self::STATUS_PROCESSED, self::STATUS_IGNORED], true)) {
                return ['status' => 200, 'message' => 'Duplicate event.'];
            }

            return $this->run($record, $event);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * @return array{status: int, message: string}
     */
    private function run(WebhookEventRecord $record, StripeEvent $event): array
    {
        $record->attempts++;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $webhookEvent = new WebhookEvent(['stripeEvent' => $event]);
            $this->trigger(self::EVENT_BEFORE_PROCESS_EVENT, $webhookEvent);

            $webhookEvent->actedOn = $this->dispatch($event) || $webhookEvent->actedOn;

            $this->trigger(self::EVENT_AFTER_PROCESS_EVENT, $webhookEvent);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::error('Webhook ' . $event->id . ' (' . $event->type . ') failed: ' . $e->getMessage(), __METHOD__);
            $this->finish($record, self::STATUS_FAILED, mb_substr($e->getMessage(), 0, 1000));

            // A 5xx makes Stripe retry, which is what we want for anything that might be transient.
            return ['status' => 500, 'message' => 'Processing failed; Stripe will retry.'];
        }

        $this->finish($record, $webhookEvent->actedOn ? self::STATUS_PROCESSED : self::STATUS_IGNORED, null);

        return ['status' => 200, 'message' => $webhookEvent->actedOn ? 'Processed.' : 'Ignored.'];
    }

    /**
     * Returns whether the event was acted on.
     */
    private function dispatch(StripeEvent $event): bool
    {
        $object = $event->data->object;

        return match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->onSessionPaid($object),
            'checkout.session.async_payment_failed' => $this->onSessionPaymentFailed($object),
            'checkout.session.expired' => $this->onSessionExpired($object),
            'payment_intent.succeeded' => $this->onIntentSucceeded($object),
            'payment_intent.payment_failed' => $this->onIntentFailed($object),
            'payment_intent.processing' => $this->onIntentProcessing($object),
            'charge.refunded' => $this->onChargeRefunded($object),
            'refund.created', 'refund.updated' => $this->onRefund($object, false),
            'refund.failed' => $this->onRefund($object, true),
            'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' => $this->onDispute($object, $event->type === 'charge.dispute.created'),
            'invoice.paid' => $this->onInvoicePaid($object),
            'invoice.payment_failed' => $this->onInvoiceProblem($object, Emails::KEY_SUBSCRIPTION_PAYMENT_FAILED, true),
            'invoice.payment_action_required' => $this->onInvoiceProblem($object, Emails::KEY_SUBSCRIPTION_ACTION_REQUIRED, false),
            'invoice.voided', 'invoice.marked_uncollectible' => $this->onInvoiceVoided($object),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed' => $this->onSubscriptionChanged($object, (int) $event->created),
            'customer.updated' => $this->onCustomerUpdated($object),
            'customer.deleted' => $this->onCustomerDeleted($object),
            default => false,
        };
    }

    private function onSessionPaid(StripeObject $session): bool
    {
        $plugin = Plugin::getInstance();
        $order = $plugin->checkout->orderForSession($session);

        if (!$order) {
            Craft::info('No order for Checkout Session ' . $session->id . '; ignoring.', __METHOD__);

            return false;
        }

        if ($session->payment_status === 'paid') {
            $plugin->orders->completeOrder($order, $session);
        } else {
            // Completed but not yet paid: a delayed payment method. async_payment_succeeded finishes it.
            $plugin->orders->markProcessing($order);
        }

        return true;
    }

    private function onSessionPaymentFailed(StripeObject $session): bool
    {
        $plugin = Plugin::getInstance();
        $order = $plugin->checkout->orderForSession($session);

        if (!$order) {
            return false;
        }

        $plugin->orders->markFailed($order);
        $plugin->emails->queue(Emails::KEY_PAYMENT_FAILED, $order);

        return true;
    }

    private function onSessionExpired(StripeObject $session): bool
    {
        $plugin = Plugin::getInstance();
        $order = $plugin->checkout->orderForSession($session);

        if (!$order) {
            return false;
        }

        $plugin->orders->releaseExpired($order, (string) $session->id);

        return true;
    }

    private function onIntentSucceeded(StripeObject $intent): bool
    {
        $plugin = Plugin::getInstance();
        $order = $this->orderForIntent($intent);
        $mode = $plugin->stripe->mode();

        if (!$order || $mode === null) {
            return false;
        }

        $plugin->payments->recordPaymentIntent($order, $intent, $mode);
        // The session is the authority on whether the order is paid; this is a no-op if it isn't yet.
        $plugin->orders->completeOrder($order);

        return true;
    }

    private function onIntentFailed(StripeObject $intent): bool
    {
        $plugin = Plugin::getInstance();
        $order = $this->orderForIntent($intent);
        $mode = $plugin->stripe->mode();

        if (!$order || $mode === null) {
            return false;
        }

        $plugin->payments->recordPaymentIntent($order, $intent, $mode, Payment::STATUS_FAILED);

        return true;
    }

    private function onIntentProcessing(StripeObject $intent): bool
    {
        $plugin = Plugin::getInstance();
        $order = $this->orderForIntent($intent);
        $mode = $plugin->stripe->mode();

        if (!$order || $mode === null) {
            return false;
        }

        $plugin->payments->recordPaymentIntent($order, $intent, $mode);
        $plugin->orders->markProcessing($order);

        return true;
    }

    /**
     * The subscription an invoice belongs to. Newer API versions nest it under the invoice's parent; older ones
     * put it on the invoice itself. Both are read so events from either still work.
     */
    private function invoiceSubscriptionId(StripeObject $invoice): ?string
    {
        $sub = $invoice->parent->subscription_details->subscription ?? $invoice->subscription ?? null;
        $sub = $sub instanceof StripeObject ? $sub->id : $sub;

        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    /**
     * A recurring donation was paid: the first time (which completes the order that started it) or any
     * later cycle (which is a new payment on the same order, with its own receipt).
     */
    private function onInvoicePaid(StripeObject $invoice): bool
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();
        $subId = $this->invoiceSubscriptionId($invoice);

        // Invoices that aren't for one of our subscriptions belong to something else on the Stripe account.
        if ($mode === null || $subId === null) {
            return false;
        }

        // Only subscriptions this plugin started are recorded: ones already known, or ones whose metadata (which
        // Stripe copies onto each invoice) names an order. Anything else on the account is left alone, unasked.
        $snapshot = $invoice->parent->subscription_details->metadata->smallChangeOrderNumber
            ?? $invoice->subscription_details->metadata->smallChangeOrderNumber
            ?? null;

        if ($plugin->subscriptions->getSubscriptionByStripeId($subId) === null && !is_string($snapshot)) {
            return false;
        }

        $local = $plugin->subscriptions->syncFromStripe($plugin->stripe->retrieveSubscription($subId));
        $order = $local && $local->orderId ? Order::find()->id($local->orderId)->one() : null;

        if (!$local || !$order) {
            return false;
        }

        $intentId = $plugin->payments->intentIdFromInvoice($plugin->stripe->retrieveInvoice((string) $invoice->id, ['payments']));

        if ($intentId === null) {
            return false;
        }

        $intent = $plugin->stripe->retrievePaymentIntent($intentId, ['latest_charge']);
        $payment = $plugin->payments->recordPaymentIntent($order, $intent, $mode, null, (string) $invoice->id, $local->id);

        if (($invoice->billing_reason ?? '') === 'subscription_create') {
            $plugin->orders->completeOrder($order);

            return true;
        }

        $order = Order::find()->id($order->id)->one() ?? $order;
        $plugin->orders->syncPaymentStatus($order);
        $plugin->emails->queue(Emails::KEY_RECEIPT, $order, ['paymentId' => $payment->id]);

        return true;
    }

    private function onInvoiceProblem(StripeObject $invoice, string $emailKey, bool $isFailure): bool
    {
        $plugin = Plugin::getInstance();
        $subId = $this->invoiceSubscriptionId($invoice);
        $local = $subId ? $plugin->subscriptions->getSubscriptionByStripeId($subId) : null;
        $order = $local && $local->orderId ? Order::find()->id($local->orderId)->one() : null;

        if (!$local || !$order) {
            return false;
        }

        if ($isFailure) {
            $plugin->subscriptions->markPaymentFailed($local);
        }

        $plugin->emails->queue($emailKey, $order, [
            'amount' => (int) ($invoice->amount_due ?? $local->amount),
            'interval' => $local->interval,
            'invoiceUrl' => (string) ($invoice->hosted_invoice_url ?? ''),
        ]);

        return true;
    }

    private function onInvoiceVoided(StripeObject $invoice): bool
    {
        $mode = Plugin::getInstance()->stripe->mode();

        return $mode !== null && Plugin::getInstance()->payments->markInvoiceFailed((string) $invoice->id, $mode) !== null;
    }

    private function onSubscriptionChanged(StripeObject $stripeSub, int $created): bool
    {
        $plugin = Plugin::getInstance();
        $known = $plugin->subscriptions->getSubscriptionByStripeId((string) $stripeSub->id) !== null;

        // Other things on the account can have subscriptions; only ones this plugin started carry its metadata.
        if (!$known && !isset($stripeSub->metadata->smallChangeOrderNumber)) {
            return false;
        }

        return $plugin->subscriptions->syncFromStripe($stripeSub, $created) !== null;
    }

    private function onCustomerUpdated(StripeObject $customer): bool
    {
        $mode = Plugin::getInstance()->stripe->mode();

        return $mode !== null && Plugin::getInstance()->customers->syncFromStripe($customer, $mode);
    }

    private function onCustomerDeleted(StripeObject $customer): bool
    {
        $mode = Plugin::getInstance()->stripe->mode();

        return $mode !== null && Plugin::getInstance()->customers->forgetStripeCustomer((string) $customer->id, $mode);
    }

    private function onChargeRefunded(StripeObject $charge): bool
    {
        $mode = Plugin::getInstance()->stripe->mode();

        return $mode !== null && Plugin::getInstance()->payments->recordChargeRefunded($charge, $mode) !== null;
    }

    private function onRefund(StripeObject $stripeRefund, bool $failed): bool
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();

        if ($mode === null) {
            return false;
        }

        $refund = $plugin->payments->recordRefund($stripeRefund, $mode);

        if ($refund === null) {
            return false;
        }

        if ($failed || $refund->status === Refund::STATUS_FAILED) {
            $payment = $plugin->payments->getPaymentById($refund->paymentId);
            $order = $payment ? Order::find()->id($payment->orderId)->one() : null;

            if ($order) {
                $plugin->emails->queue(Emails::KEY_ADMIN_REFUND_FAILED, $order, ['amount' => $refund->amount, 'reason' => $refund->failureReason]);
            }
        }

        return true;
    }

    private function onDispute(StripeObject $dispute, bool $isNew): bool
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();
        $payment = $mode !== null ? $plugin->payments->recordDispute($dispute, $mode) : null;

        if ($payment === null) {
            return false;
        }

        if ($isNew) {
            $order = Order::find()->id($payment->orderId)->one();

            if ($order) {
                $plugin->emails->queue(Emails::KEY_ADMIN_DISPUTE, $order, ['amount' => (int) $dispute->amount, 'reason' => (string) ($dispute->reason ?? '')]);
            }
        }

        return true;
    }

    private function orderForIntent(StripeObject $intent): ?Order
    {
        $number = $intent->metadata->smallChangeOrderNumber ?? null;

        if (is_string($number) && $number !== '') {
            $order = Order::find()->number($number)->one();

            if ($order) {
                return $order;
            }
        }

        return Order::find()->stripePaymentIntentId($intent->id)->one();
    }

    private function finish(WebhookEventRecord $record, string $status, ?string $error): void
    {
        $record->status = $status;
        $record->error = $error;
        $record->dateProcessed = Db::prepareDateForDb(new DateTime());
        $record->save(false);
    }

    private function objectId(StripeEvent $event): ?string
    {
        $id = $event->data->object->id ?? null;

        return is_string($id) ? $id : null;
    }

    private function customerId(StripeEvent $event): ?string
    {
        $customer = $event->data->object->customer ?? null;

        if ($customer instanceof StripeObject) {
            $customer = $customer->id ?? null;
        }

        return is_string($customer) ? $customer : null;
    }
}
