<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests;

use coyshdigital\smallchange\services\Stripe;
use RuntimeException;
use Stripe\StripeObject;

/**
 * Stands in for the Stripe service so no test touches the network. It keeps just enough state
 * (sessions, intents) to play a payment through, and records every call.
 */
class FakeStripe extends Stripe
{
    public string $fakeMode = self::MODE_TEST;
    public ?string $fakeWebhookSecret = 'whsec_test_secret';

    /**
     * @var array<int, array{method: string, args: array<int, mixed>}>
     */
    public array $calls = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $sessions = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $intents = [];

    /**
     * @var array<string, string> idempotency key => session ID
     */
    public array $idempotency = [];

    public bool $failCreate = false;
    private int $counter = 0;

    public function mode(): ?string
    {
        return $this->fakeMode;
    }

    public function webhookSecret(): ?string
    {
        return $this->fakeWebhookSecret;
    }

    public function publishableKey(): ?string
    {
        return 'pk_test_fake';
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): StripeObject
    {
        $this->calls[] = ['method' => 'createCheckoutSession', 'args' => [$params, $idempotencyKey]];

        if ($this->failCreate) {
            throw new \Stripe\Exception\ApiConnectionException('network down');
        }

        if (isset($this->idempotency[$idempotencyKey])) {
            return StripeObject::constructFrom($this->sessions[$this->idempotency[$idempotencyKey]]);
        }

        $id = 'cs_test_' . ++$this->counter;
        $hosted = ($params['ui_mode'] ?? '') === 'hosted_page';
        $total = 0;

        foreach ($params['line_items'] as $line) {
            $total += $line['price_data']['unit_amount'] * $line['quantity'];
        }

        $recurring = $params['line_items'][0]['price_data']['recurring']['interval'] ?? null;

        // Like Stripe, take a fixed-amount coupon off the total.
        foreach ($params['discounts'] ?? [] as $discount) {
            $coupon = $this->coupons[$discount['coupon']] ?? throw new \Stripe\Exception\InvalidRequestException('No such coupon: ' . $discount['coupon']);
            $total -= (int) $coupon['amount_off'];
        }

        $this->sessions[$id] = [
            'id' => $id,
            'object' => 'checkout.session',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'livemode' => $this->fakeMode === self::MODE_LIVE,
            'mode' => $params['mode'] ?? 'payment',
            'subscription' => null,
            'subscription_data' => $params['subscription_data'] ?? null,
            'ui_mode' => $params['ui_mode'] ?? 'hosted_page',
            'amount_total' => $total,
            'currency' => $params['line_items'][0]['price_data']['currency'],
            'client_reference_id' => $params['client_reference_id'] ?? null,
            'client_secret' => $hosted ? null : $id . '_secret_abc',
            'url' => $hosted ? 'https://checkout.stripe.test/' . $id : null,
            'recurring_interval' => $recurring,
            'customer' => $params['customer'] ?? null,
            'customer_email' => $params['customer_email'] ?? null,
            'metadata' => $params['metadata'] ?? [],
            'payment_intent' => null,
            'return_url' => $params['return_url'] ?? null,
        ];
        $this->idempotency[$idempotencyKey] = $id;

        return StripeObject::constructFrom($this->sessions[$id]);
    }

    public function retrieveCheckoutSession(string $id, array $expand = []): StripeObject
    {
        $this->calls[] = ['method' => 'retrieveCheckoutSession', 'args' => [$id, $expand]];

        if (!isset($this->sessions[$id])) {
            throw new RuntimeException("No fake session $id");
        }

        return StripeObject::constructFrom($this->sessions[$id]);
    }

    public function expireCheckoutSession(string $id): void
    {
        $this->calls[] = ['method' => 'expireCheckoutSession', 'args' => [$id]];

        if (isset($this->sessions[$id]) && $this->sessions[$id]['status'] === 'open') {
            $this->sessions[$id]['status'] = 'expired';
        }
    }

    public function retrievePaymentIntent(string $id, array $expand = []): StripeObject
    {
        $this->calls[] = ['method' => 'retrievePaymentIntent', 'args' => [$id, $expand]];

        if (!isset($this->intents[$id])) {
            throw new RuntimeException("No fake intent $id");
        }

        return StripeObject::constructFrom($this->intents[$id]);
    }

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $coupons = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $refunds = [];

    public string $nextRefundStatus = 'succeeded';
    public bool $failRefund = false;

    public function createRefund(array $params, string $idempotencyKey): StripeObject
    {
        $this->calls[] = ['method' => 'createRefund', 'args' => [$params, $idempotencyKey]];

        if ($this->failRefund) {
            throw new \Stripe\Exception\InvalidRequestException('Charge has already been refunded.');
        }

        if (isset($this->idempotency['r:' . $idempotencyKey])) {
            return StripeObject::constructFrom($this->refunds[$this->idempotency['r:' . $idempotencyKey]]);
        }

        $id = 're_test_' . ++$this->counter;
        $this->refunds[$id] = [
            'id' => $id,
            'object' => 'refund',
            'amount' => $params['amount'],
            'currency' => 'gbp',
            'status' => $this->nextRefundStatus,
            'reason' => $params['reason'] ?? null,
            'payment_intent' => $params['payment_intent'],
            'charge' => $this->intents[$params['payment_intent']]['latest_charge'] ?? null,
            'failure_reason' => null,
            'metadata' => $params['metadata'] ?? [],
        ];
        $this->idempotency['r:' . $idempotencyKey] = $id;

        return StripeObject::constructFrom($this->refunds[$id]);
    }

    public function createCoupon(array $params, string $idempotencyKey): void
    {
        $this->calls[] = ['method' => 'createCoupon', 'args' => [$params, $idempotencyKey]];

        // Like Stripe: a repeated key gets the first answer again, even if the coupon has since been deleted.
        if (isset($this->idempotency['c:' . $idempotencyKey])) {
            return;
        }

        $this->idempotency['c:' . $idempotencyKey] = $params['id'];
        $this->coupons[$params['id']] = $params;
    }

    public function deleteCoupon(string $id): void
    {
        $this->calls[] = ['method' => 'deleteCoupon', 'args' => [$id]];
        unset($this->coupons[$id]);
    }

    public function createCustomer(array $params, string $idempotencyKey): StripeObject
    {
        $this->calls[] = ['method' => 'createCustomer', 'args' => [$params, $idempotencyKey]];

        return StripeObject::constructFrom(['id' => 'cus_test_' . ++$this->counter] + $params);
    }

    public function updateCustomer(string $id, array $params): StripeObject
    {
        $this->calls[] = ['method' => 'updateCustomer', 'args' => [$id, $params]];

        return StripeObject::constructFrom(['id' => $id] + $params);
    }

    /**
     * Plays a successful payment for a session, as Stripe would after the donor confirms.
     *
     * @param array<string, string> $intentMetadata
     * @return string the PaymentIntent ID
     */
    public function pay(string $sessionId, ?int $amount = null, array $intentMetadata = []): string
    {
        $session = $this->sessions[$sessionId];
        $intentId = 'pi_test_' . ++$this->counter;
        $amount ??= (int) $session['amount_total'];

        $this->intents[$intentId] = [
            'id' => $intentId,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => $amount,
            'amount_received' => $amount,
            'currency' => $session['currency'],
            'latest_charge' => 'ch_test_' . $this->counter,
            'last_payment_error' => null,
            'metadata' => $intentMetadata ?: ['smallChangeOrderNumber' => $session['client_reference_id']],
        ];

        $this->sessions[$sessionId]['status'] = 'complete';
        $this->sessions[$sessionId]['payment_status'] = 'paid';
        $this->sessions[$sessionId]['payment_intent'] = $intentId;
        $this->sessions[$sessionId]['customer'] ??= 'cus_test_paid';

        return $intentId;
    }

    // Recurring donations ----------------------------------------------------

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $subscriptions = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $invoices = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $products = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $webhookEndpoints = [];

    /**
     * @var string[]
     */
    public array $deletedCustomers = [];

    /**
     * @var array<int, \Stripe\Event> what listEvents() hands back
     */
    public array $events = [];

    public bool $failPortal = false;

    /**
     * Plays the donor confirming a recurring donation: a subscription, its first invoice and the payment that settled it.
     *
     * @return array{subscription: string, invoice: string, intent: string}
     */
    public function paySubscription(string $sessionId): array
    {
        $session = $this->sessions[$sessionId];
        $subId = 'sub_test_' . ++$this->counter;
        $customer = $session['customer'] ?? 'cus_test_' . $this->counter;
        $this->subscriptions[$subId] = [
            'id' => $subId,
            'object' => 'subscription',
            'status' => 'active',
            'customer' => $customer,
            'currency' => strtolower((string) $session['currency']),
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'metadata' => $session['subscription_data']['metadata'] ?? [],
            'items' => ['data' => [[
                'quantity' => 1,
                'current_period_end' => strtotime('+1 ' . $session['recurring_interval']),
                'price' => ['unit_amount' => (int) $session['amount_total'], 'currency' => strtolower((string) $session['currency']), 'recurring' => ['interval' => $session['recurring_interval']]],
            ]]],
            'latest_invoice' => null,
        ];

        $invoice = $this->newInvoice($subId, 'subscription_create');
        $this->sessions[$sessionId]['status'] = 'complete';
        $this->sessions[$sessionId]['payment_status'] = 'paid';
        $this->sessions[$sessionId]['subscription'] = $subId;
        $this->sessions[$sessionId]['customer'] = $customer;

        return ['subscription' => $subId, 'invoice' => $invoice['id'], 'intent' => $invoice['payments']['data'][0]['payment']['payment_intent']];
    }

    /**
     * The next billing cycle: a new invoice and a new payment, as Stripe would raise them.
     *
     * @return array<string, mixed> the invoice
     */
    public function renew(string $subId, bool $paid = true): array
    {
        $invoice = $this->newInvoice($subId, 'subscription_cycle', $paid);
        $this->subscriptions[$subId]['status'] = $paid ? 'active' : 'past_due';

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function newInvoice(string $subId, string $reason, bool $paid = true): array
    {
        $sub = $this->subscriptions[$subId];
        $amount = (int) $sub['items']['data'][0]['price']['unit_amount'];
        $invoiceId = 'in_test_' . ++$this->counter;
        $intentId = 'pi_test_' . ++$this->counter;

        $this->intents[$intentId] = [
            'id' => $intentId,
            'object' => 'payment_intent',
            'status' => $paid ? 'succeeded' : 'requires_payment_method',
            'amount' => $amount,
            'amount_received' => $paid ? $amount : 0,
            'currency' => $sub['currency'],
            'latest_charge' => $paid ? 'ch_test_' . $this->counter : null,
            'last_payment_error' => $paid ? null : ['message' => 'Your card was declined.'],
            'metadata' => [],
        ];
        $this->invoices[$invoiceId] = [
            'id' => $invoiceId,
            'object' => 'invoice',
            'customer' => $sub['customer'],
            'billing_reason' => $reason,
            'amount_due' => $amount,
            'currency' => $sub['currency'],
            'hosted_invoice_url' => 'https://invoice.stripe.test/' . $invoiceId,
            // Like Stripe, each invoice carries a snapshot of its subscription's metadata.
            'parent' => ['subscription_details' => ['subscription' => $subId, 'metadata' => $sub['metadata']]],
            'payments' => ['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => $intentId]]]],
        ];
        $this->subscriptions[$subId]['latest_invoice'] = $invoiceId;

        return $this->invoices[$invoiceId];
    }

    public function retrieveSubscription(string $id, array $expand = []): StripeObject
    {
        $this->calls[] = ['method' => 'retrieveSubscription', 'args' => [$id, $expand]];

        if (!isset($this->subscriptions[$id])) {
            throw new RuntimeException("No fake subscription $id");
        }

        $sub = $this->subscriptions[$id];

        if (in_array('latest_invoice.payments', $expand, true) && $sub['latest_invoice']) {
            $sub['latest_invoice'] = $this->invoices[$sub['latest_invoice']];
        }

        return StripeObject::constructFrom($sub);
    }

    public function cancelSubscription(string $id): StripeObject
    {
        $this->calls[] = ['method' => 'cancelSubscription', 'args' => [$id]];
        $this->subscriptions[$id]['status'] = 'canceled';
        $this->subscriptions[$id]['canceled_at'] = time();
        $this->subscriptions[$id]['ended_at'] = time();

        return StripeObject::constructFrom($this->subscriptions[$id]);
    }

    public function updateSubscription(string $id, array $params): StripeObject
    {
        $this->calls[] = ['method' => 'updateSubscription', 'args' => [$id, $params]];
        $this->subscriptions[$id] = $params + $this->subscriptions[$id];

        if (!empty($params['cancel_at_period_end'])) {
            $this->subscriptions[$id]['canceled_at'] = time();
        }

        return StripeObject::constructFrom($this->subscriptions[$id]);
    }

    public function retrieveInvoice(string $id, array $expand = []): StripeObject
    {
        $this->calls[] = ['method' => 'retrieveInvoice', 'args' => [$id, $expand]];

        return StripeObject::constructFrom($this->invoices[$id] ?? throw new RuntimeException("No fake invoice $id"));
    }

    public function createProduct(array $params, string $idempotencyKey): StripeObject
    {
        $this->calls[] = ['method' => 'createProduct', 'args' => [$params, $idempotencyKey]];
        $id = 'prod_test_' . ++$this->counter;
        $this->products[$id] = ['id' => $id] + $params;

        return StripeObject::constructFrom($this->products[$id]);
    }

    public function updateProduct(string $id, array $params): StripeObject
    {
        $this->calls[] = ['method' => 'updateProduct', 'args' => [$id, $params]];
        $this->products[$id] = $params + ($this->products[$id] ?? ['id' => $id]);

        return StripeObject::constructFrom($this->products[$id]);
    }

    public function createPortalSession(string $stripeCustomerId, string $returnUrl): StripeObject
    {
        $this->calls[] = ['method' => 'createPortalSession', 'args' => [$stripeCustomerId, $returnUrl]];

        if ($this->failPortal) {
            throw new \Stripe\Exception\ApiConnectionException('network down');
        }

        return StripeObject::constructFrom(['id' => 'bps_test_' . ++$this->counter, 'url' => 'https://billing.stripe.test/session/' . $this->counter]);
    }

    public function createWebhookEndpoint(array $params): StripeObject
    {
        $this->calls[] = ['method' => 'createWebhookEndpoint', 'args' => [$params]];
        $id = 'we_test_' . ++$this->counter;
        $this->webhookEndpoints[$id] = ['id' => $id, 'secret' => 'whsec_created'] + $params;

        return StripeObject::constructFrom($this->webhookEndpoints[$id]);
    }

    public function retrieveWebhookEndpoint(string $id): StripeObject
    {
        $this->calls[] = ['method' => 'retrieveWebhookEndpoint', 'args' => [$id]];

        return StripeObject::constructFrom($this->webhookEndpoints[$id] ?? throw new \Stripe\Exception\InvalidRequestException("No such webhook endpoint: $id"));
    }

    public function deleteWebhookEndpoint(string $id): void
    {
        $this->calls[] = ['method' => 'deleteWebhookEndpoint', 'args' => [$id]];
        unset($this->webhookEndpoints[$id]);
    }

    public function deleteCustomer(string $id): void
    {
        $this->calls[] = ['method' => 'deleteCustomer', 'args' => [$id]];
        $this->deletedCustomers[] = $id;
    }

    public function listEvents(array $params): array
    {
        $this->calls[] = ['method' => 'listEvents', 'args' => [$params]];

        return array_values(array_filter(
            $this->events,
            static fn(\Stripe\Event $event): bool => $event->created >= ($params['created']['gte'] ?? 0) && (!isset($params['type']) || $event->type === $params['type']),
        ));
    }

    /**
     * @return array<int, array<int, mixed>> the arguments of each call to a method
     */
    public function callsTo(string $method): array
    {
        return array_values(array_map(static fn(array $call): array => $call['args'], array_filter($this->calls, static fn(array $call): bool => $call['method'] === $method)));
    }
}
