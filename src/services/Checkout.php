<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\events\BuildCheckoutSessionEvent;
use coyshdigital\smallchange\exceptions\CheckoutException;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\web\Application as WebApplication;
use DateTime;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;

/**
 * Turns a saved order into a Stripe Checkout Session and handles the donor's return.
 * Every payment goes through a session created here from amounts computed on the server.
 */
class Checkout extends Component
{
    public const EVENT_BUILD_CHECKOUT_SESSION = 'buildCheckoutSession';

    private const SECRETS_KEY = 'smallChange.clientSecrets';

    public const OUTCOME_PAID = 'paid';
    public const OUTCOME_PROCESSING = 'processing';
    public const OUTCOME_OPEN = 'open';

    /**
     * Creates the Checkout Session for a saved order and moves it to pending.
     *
     * @param string $paymentUrl where the embedded payment form lives, used to bring the donor back if they abandon
     * @return string|null the Stripe-hosted page to send the donor to, when the site uses hosted checkout
     * @throws CheckoutException when the order cannot be paid or Stripe refuses it
     */
    public function start(Order $order, string $paymentUrl): ?string
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();

        if ($mode === null) {
            throw new CheckoutException(Craft::t('small-change', 'Payments are not set up on this site yet.'));
        }

        if (!$order->id || $order->state === Order::STATE_COMPLETED || $order->state === Order::STATE_CANCELLED) {
            throw new CheckoutException(Craft::t('small-change', 'This order can no longer be paid.'));
        }

        if (!$order->email || !$order->getLineItems()) {
            throw new CheckoutException(Craft::t('small-change', 'This order is not ready to be paid.'));
        }

        // Never trust what was saved: prices, delivery, discount and totals are worked out again every time.
        $notices = $plugin->orders->recalculate($order);

        if ($notices) {
            // The customer must see what changed before they pay, so stop here with the new figures saved.
            Craft::$app->getElements()->saveElement($order, false, false);

            throw new CheckoutException(implode(' ', $notices));
        }

        if ($order->total < 1) {
            throw new CheckoutException(Craft::t('small-change', 'There is nothing to pay.'));
        }

        if ($order->getRequiresShipping() && (!$order->shippingMethodId || !$order->getShippingAddress())) {
            throw new CheckoutException(Craft::t('small-change', 'Choose a delivery address and method before paying.'));
        }

        $this->holdDiscount($order);

        $billing = $order->getBillingAddress();
        $customer = $plugin->customers->findOrCreate((string) $order->email, $mode, $billing ? $plugin->customers->fullName($billing) : null);
        $plugin->customers->syncToStripe($customer, $billing);

        $previousSessionId = $order->stripeCheckoutSessionId;
        $order->customerId = $customer->id;
        $order->stripeMode = $mode;
        $order->state = Order::STATE_PENDING;
        $order->paymentStatus = Order::PAYMENT_UNPAID;
        $order->dateLastActivity = new DateTime();

        $stripeProductId = null;

        if ($order->getIsRecurring()) {
            $line = $order->getLineItems()[0];
            $product = $line->purchasableId ? Product::find()->id($line->purchasableId)->one() : null;

            if (!$product instanceof Product || !$line->recurringInterval) {
                throw new CheckoutException(Craft::t('small-change', 'That recurring donation is not available.'));
            }

            try {
                $stripeProductId = $plugin->stripeProducts->ensure($product, $mode);
            } catch (ApiErrorException $e) {
                Craft::error('Could not create the Stripe product for order ' . $order->number . ': ' . $e->getMessage(), __METHOD__);

                throw new CheckoutException(Craft::t('small-change', "We couldn't start the payment. Please try again in a moment."), 0, $e);
            }
        }

        $params = $this->buildSessionParams($order, $paymentUrl, $customer->stripeCustomerId, $stripeProductId);

        try {
            if ($couponId = $order->getStripeCouponId()) {
                $plugin->stripe->createCoupon([
                    'id' => $couponId,
                    'amount_off' => $order->discountAmount,
                    'currency' => strtolower($order->currency),
                    'duration' => 'once',
                    'max_redemptions' => 1,
                    'name' => mb_substr('Discount ' . $order->discountCode, 0, 40),
                ], 'coupon-' . $couponId);
            }

            // A plain double submit repeats the key and gets the same session back; a pulled-back checkout is a new attempt.
            $session = $plugin->stripe->createCheckoutSession($params, 'order-' . $order->number . '-' . $order->checkoutAttempt . '-' . substr(hash('sha256', (string) json_encode($params)), 0, 16));
        } catch (ApiErrorException $e) {
            Craft::error('Stripe refused the Checkout Session for order ' . $order->number . ': ' . $e->getMessage(), __METHOD__);
            $plugin->discounts->release($order);
            $order->state = Order::STATE_CART;
            Craft::$app->getElements()->saveElement($order, false, false);

            throw new CheckoutException(Craft::t('small-change', "We couldn't start the payment. Please try again in a moment."), 0, $e);
        }

        // The amounts changed, so the donor must not be able to pay the old session.
        if ($previousSessionId && $previousSessionId !== $session->id) {
            $plugin->stripe->expireCheckoutSession($previousSessionId);
        }

        $order->stripeCheckoutSessionId = $session->id;

        if (!Craft::$app->getElements()->saveElement($order, false, false)) {
            throw new CheckoutException(Craft::t('small-change', "We couldn't save your order. Please try again."));
        }

        $plugin->orders->addToSession((string) $order->number);
        $this->rememberSecret((string) $order->number, $session->client_secret ?? null);

        return $this->isHosted() ? (string) ($session->url ?? '') : null;
    }

    /**
     * The parameters for `checkout.sessions.create`.
     *
     * @return array<string, mixed>
     */
    public function buildSessionParams(Order $order, string $paymentUrl, ?string $stripeCustomerId, ?string $stripeProductId = null): array
    {
        $plugin = Plugin::getInstance();
        $currency = strtolower($order->currency);
        $lineItems = [];

        foreach ($order->getLineItems() as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $item->unitPrice,
                    'product_data' => ['name' => mb_substr($item->description, 0, 250)],
                ],
                'quantity' => $item->qty,
            ];
        }

        // Delivery is its own line so Stripe's total matches ours without asking Stripe to collect an address.
        if ($order->shippingAmount > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $order->shippingAmount,
                    'product_data' => ['name' => mb_substr(Craft::t('small-change', 'Delivery') . ': ' . $order->shippingMethodName, 0, 250)],
                ],
                'quantity' => 1,
            ];
        }

        $metadata = ['smallChangeOrderNumber' => (string) $order->number];
        $returnUrl = $this->returnUrl($order, $paymentUrl);

        $recurring = $order->getIsRecurring();
        $params = [
            'mode' => $recurring ? 'subscription' : 'payment',
            // The site is the merchant, sends its own receipts and handles Gift Aid. Stripe's Managed Payments
            // (Stripe as merchant of record) can be on by default for an account and rejects ui_mode=elements,
            // so every session says no explicitly rather than depending on an account setting.
            'managed_payments' => ['enabled' => false],
            'line_items' => $recurring ? $this->recurringLineItems($order, $currency, $stripeProductId) : $lineItems,
            'client_reference_id' => $order->number,
            'metadata' => $metadata,
        ];

        if ($recurring) {
            // Stripe shows the subscription's metadata on every invoice (parent.subscription_details.metadata), so the Gift Aid answer travels with each payment.
            $line = $order->getLineItems()[0];
            $params['subscription_data'] = [
                'metadata' => $metadata + ['smallChangeProductId' => (string) $line->purchasableId] + $plugin->giftAid->metadata($order),
                'description' => $this->description($order),
            ];
        } else {
            $params['payment_intent_data'] = [
                'metadata' => $metadata + $plugin->giftAid->metadata($order),
                'description' => $this->description($order),
            ];
        }

        // The discount was computed here; Stripe only needs to show it, as a one-use fixed-amount coupon.
        if ($couponId = $order->getStripeCouponId()) {
            $params['discounts'] = [['coupon' => $couponId]];
        }

        if ($stripeCustomerId) {
            $params['customer'] = $stripeCustomerId;
        } else {
            $params['customer_email'] = $order->email;

            // Stripe makes the customer for a subscription anyway, and refuses this parameter there.
            if (!$recurring) {
                $params['customer_creation'] = 'always';
            }
        }

        if ($this->isHosted()) {
            $params['ui_mode'] = 'hosted_page';
            $params['success_url'] = $returnUrl;
            // Backing out of Stripe's page lands on the payment page, which can offer the way back in.
            $params['cancel_url'] = UrlHelper::urlWithParams(UrlHelper::siteUrl($paymentUrl), ['number' => (string) $order->number]);
            // submit_type is not allowed with ui_mode=elements, so only the hosted page gets it.
            if (!$recurring) {
                $params['submit_type'] = $order->getHasDonation() && count($order->getLineItems()) === 1 ? 'donate' : 'pay';
            }
        } else {
            $params['ui_mode'] = 'elements';
            $params['return_url'] = $returnUrl;
        }

        $protected = array_filter(['line_items' => $params['line_items'], 'mode' => $params['mode'], 'discounts' => $params['discounts'] ?? null]);

        $event = new BuildCheckoutSessionEvent(['order' => $order, 'params' => $params]);
        $this->trigger(self::EVENT_BUILD_CHECKOUT_SESSION, $event);

        // Listeners may add metadata or tweak presentation, never the money or the mode.
        return array_merge($event->params, $protected);
    }

    /**
     * The one recurring price, built inline against the campaign's Stripe Product.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recurringLineItems(Order $order, string $currency, ?string $stripeProductId): array
    {
        $line = $order->getLineItems()[0];

        return [[
            'price_data' => [
                'currency' => $currency,
                'product' => $stripeProductId,
                'unit_amount' => $line->unitPrice,
                'recurring' => ['interval' => $line->recurringInterval],
            ],
            'quantity' => 1,
        ]];
    }

    /**
     * The secret the browser needs to mount the payment form. Only handed to the session that placed the order.
     */
    public function clientSecret(Order $order): ?string
    {
        $plugin = Plugin::getInstance();

        if (!$order->stripeCheckoutSessionId || !$plugin->orders->isInSession((string) $order->number) || $order->state !== Order::STATE_PENDING) {
            return null;
        }

        try {
            $session = $plugin->stripe->retrieveCheckoutSession($order->stripeCheckoutSessionId);
        } catch (ApiErrorException $e) {
            Craft::error('Could not retrieve the Checkout Session for order ' . $order->number . ': ' . $e->getMessage(), __METHOD__);

            return null;
        }

        if ($session->status !== 'open') {
            return null;
        }

        // Stripe returns the secret on create; if it is ever withheld on retrieve, the one this browser was given still works.
        return ($session->client_secret ?? null) ?: $this->recalledSecret((string) $order->number);
    }

    /**
     * Where to send a donor to pay on Stripe's own page, for sites using hosted checkout. Only handed
     * to the session that placed the order, like the client secret.
     */
    public function hostedUrl(Order $order): ?string
    {
        $plugin = Plugin::getInstance();

        if (!$order->stripeCheckoutSessionId || !$plugin->orders->isInSession((string) $order->number) || $order->state !== Order::STATE_PENDING) {
            return null;
        }

        try {
            $session = $plugin->stripe->retrieveCheckoutSession($order->stripeCheckoutSessionId);
        } catch (ApiErrorException $e) {
            Craft::error('Could not retrieve the Checkout Session for order ' . $order->number . ': ' . $e->getMessage(), __METHOD__);

            return null;
        }

        return $session->status === 'open' && ($session->url ?? null) ? (string) $session->url : null;
    }

    /**
     * Handles the donor coming back from Stripe. This only speeds things up: the webhook completes
     * the same order through the same idempotent routine.
     *
     * @return array{order: Order, outcome: string, paymentUrl: string}
     * @throws CheckoutException when the link is invalid, expired or for a different session
     */
    public function handleReturn(string $token, string $sessionId): array
    {
        $plugin = Plugin::getInstance();
        $parsed = $plugin->orders->parseAccessToken($token);

        if ($parsed === null) {
            throw new CheckoutException(Craft::t('small-change', 'This link has expired.'));
        }

        $order = Order::find()->number($parsed['number'])->one();

        if (!$order || !$order->stripeCheckoutSessionId || $order->stripeCheckoutSessionId !== $sessionId) {
            throw new CheckoutException(Craft::t('small-change', "We couldn't match that payment to an order."));
        }

        try {
            $session = $plugin->stripe->retrieveCheckoutSession($sessionId);
        } catch (ApiErrorException $e) {
            Craft::error('Could not retrieve Checkout Session ' . $sessionId . ': ' . $e->getMessage(), __METHOD__);

            throw new CheckoutException(Craft::t('small-change', "We couldn't confirm your payment just now. If you were charged, you'll get a receipt by email."), 0, $e);
        }

        $mode = $plugin->stripe->mode();

        if ($session->client_reference_id !== $order->number || ($session->livemode === true) !== ($mode === Stripe::MODE_LIVE)) {
            throw new CheckoutException(Craft::t('small-change', "We couldn't match that payment to an order."));
        }

        // The signed link proves this browser was sent here by us, so it may see the order from now on.
        $plugin->orders->addToSession((string) $order->number);

        if ($session->payment_status === 'paid') {
            $plugin->orders->completeOrder($order, $session);
            $outcome = self::OUTCOME_PAID;
        } elseif ($session->status === 'complete') {
            $plugin->orders->markProcessing($order);
            $outcome = self::OUTCOME_PROCESSING;
        } else {
            $outcome = self::OUTCOME_OPEN;
        }

        return [
            'order' => Order::find()->id($order->id)->one() ?? $order,
            'outcome' => $outcome,
            'paymentUrl' => $parsed['paymentUrl'],
        ];
    }

    /**
     * Finds the order a Checkout Session belongs to.
     */
    public function orderForSession(StripeObject $session): ?Order
    {
        $number = $session->client_reference_id ?? ($session->metadata->smallChangeOrderNumber ?? null);

        if (is_string($number) && $number !== '') {
            $order = Order::find()->number($number)->one();

            if ($order) {
                return $order;
            }
        }

        return $session->id ? Order::find()->stripeCheckoutSessionId($session->id)->one() : null;
    }

    /**
     * Takes one use of the order's discount code, or removes the code if the last use just went.
     *
     * @throws CheckoutException
     */
    private function holdDiscount(Order $order): void
    {
        if (!$order->discountId) {
            return;
        }

        $plugin = Plugin::getInstance();
        $discount = $plugin->discounts->getDiscountById($order->discountId);

        if ($discount && $plugin->discounts->reserve($discount, $order)) {
            return;
        }

        $order->discountId = null;
        $order->discountCode = null;
        $plugin->orders->recalculate($order);
        Craft::$app->getElements()->saveElement($order, false, false);

        throw new CheckoutException(Craft::t('small-change', "That discount code has just been fully used, so we've removed it. Your total has changed."));
    }

    private function rememberSecret(string $number, mixed $secret): void
    {
        if (!is_string($secret) || $secret === '' || !Craft::$app instanceof WebApplication) {
            return;
        }

        $session = Craft::$app->getSession();
        $secrets = array_slice((array) $session->get(self::SECRETS_KEY, []), -9, null, true);
        $secrets[$number] = $secret;
        $session->set(self::SECRETS_KEY, $secrets);
    }

    private function recalledSecret(string $number): ?string
    {
        if (!Craft::$app instanceof WebApplication) {
            return null;
        }

        $secret = (array) Craft::$app->getSession()->get(self::SECRETS_KEY, []);

        return is_string($secret[$number] ?? null) ? $secret[$number] : null;
    }

    private function returnUrl(Order $order, string $paymentUrl): string
    {
        $base = UrlHelper::actionUrl('small-change/checkout/complete');
        $token = Plugin::getInstance()->orders->createAccessToken($order, $paymentUrl);

        // The braces must stay literal for Stripe to substitute the session ID, so no URL helper encodes them.
        return $base . (str_contains($base, '?') ? '&' : '?') . 't=' . rawurlencode($token) . '&session_id={CHECKOUT_SESSION_ID}';
    }

    private function description(Order $order): string
    {
        $site = Craft::$app->getSites()->getSiteById((int) $order->orderSiteId)?->getName() ?? Craft::$app->getSystemName();

        return ($order->getHasDonation() ? 'Donation' : 'Order') . ' ' . $order->number . ' on ' . $site;
    }

    private function isHosted(): bool
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings->checkoutMode === Settings::CHECKOUT_MODE_HOSTED;
    }
}
