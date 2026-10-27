<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\variables;

use coyshdigital\smallchange\elements\db\OrderQuery;
use coyshdigital\smallchange\elements\db\ProductQuery;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\helpers\Caching;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\models\ShippingMethod;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Stripe;
use coyshdigital\smallchange\web\assets\frontend\FrontEndAsset;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\View;

/**
 * craft.smallChange in templates.
 */
class SmallChangeVariable
{
    /**
     * @param array<string, mixed> $criteria
     */
    public function products(array $criteria = []): ProductQuery
    {
        $query = Product::find();

        // Criteria like `type` are methods on the query, so they go through the same door as Twig's chained form.
        foreach ($criteria as $name => $value) {
            $query->$name($value);
        }

        return $query;
    }

    /**
     * Orders. On the site this only ever returns the signed-in user's own completed orders (and nothing for a
     * guest), whatever the criteria say, so a template can't be coaxed into listing other people's. In the
     * control panel and on the command line it is unrestricted.
     *
     * @param array<string, mixed> $criteria
     */
    public function orders(array $criteria = []): OrderQuery
    {
        $query = Order::find()->isCompleted();

        foreach ($criteria as $name => $value) {
            $query->$name($value);
        }

        $request = Craft::$app->getRequest();

        if (!$request->getIsSiteRequest() || $request->getIsConsoleRequest()) {
            return $query;
        }

        // A list of one person's orders must never be served to the next visitor.
        Caching::keepPrivate();

        $user = Craft::$app->getUser()->getIdentity();

        // Applied last so a `userId` or `isCompleted` in the criteria can't widen it.
        return $user ? $query->isCompleted()->userId($user->id) : $query->id(false);
    }

    /**
     * The total given to a donation product, in minor units, for a "raised so far" counter. Can be limited to a
     * period. It returns a number and nothing about donors.
     */
    public function donationTotal(Product|int $product, mixed $from = null, mixed $to = null): int
    {
        return Plugin::getInstance()->orders->donationTotal(
            $product instanceof Product ? (int) $product->id : $product,
            $from ? DateTimeHelper::toDateTime($from) ?: null : null,
            $to ? DateTimeHelper::toDateTime($to) ?: null : null,
        );
    }

    /**
     * The visitor's basket, or null if they haven't started one. A page that reads it is personal to the
     * visitor, so it is kept out of static caches (see docs/caching.md).
     */
    public function getCart(bool $create = false): ?Order
    {
        Caching::keepPrivate();

        return Plugin::getInstance()->carts->getCart($create);
    }

    /**
     * What a script needs to draw the basket (never the customer's details), or null without a basket.
     *
     * @return array<string, mixed>|null
     */
    public function cartSummary(): ?array
    {
        Caching::keepPrivate();

        $cart = Plugin::getInstance()->carts->getCart();

        return $cart ? Plugin::getInstance()->carts->summary($cart) : null;
    }

    /**
     * Delivery methods this order can use, given where it is going. Before an address is known,
     * the methods that serve every country.
     *
     * @return ShippingMethod[]
     */
    public function shippingMethods(?Order $order = null): array
    {
        $country = $order?->getShippingCountryCode();

        return $country !== null
            ? Plugin::getInstance()->shippingMethods->getApplicableMethods($country)
            : array_values(array_filter(Plugin::getInstance()->shippingMethods->getAllMethods(true), static fn(ShippingMethod $method): bool => $method->appliesTo(null)));
    }

    /**
     * The custom fields a customer fills in at checkout, in layout order.
     *
     * @return \craft\base\FieldInterface[]
     */
    public function checkoutFields(): array
    {
        return Plugin::getInstance()->orders->getCheckoutFields();
    }

    /**
     * The custom fields a donor fills in on the donation form, in layout order.
     *
     * @return \craft\base\FieldInterface[]
     */
    public function donationFields(): array
    {
        return Plugin::getInstance()->orders->getDonationFields();
    }

    /**
     * The order, but only when this browser session placed it. Use this on thank-you and payment
     * pages; an order number alone never grants access.
     */
    public function recentOrder(?string $number): ?Order
    {
        Caching::keepPrivate();

        return Plugin::getInstance()->orders->recentOrder($number);
    }

    /**
     * The secret the payment form needs for an order this session placed, or null.
     */
    public function checkoutClientSecret(?Order $order): ?string
    {
        Caching::keepPrivate();

        return $order ? Plugin::getInstance()->checkout->clientSecret($order) : null;
    }

    /**
     * Stripe's own payment page for an order, when the site uses hosted checkout; null otherwise.
     */
    public function checkoutUrl(?Order $order): ?string
    {
        Caching::keepPrivate();

        return $order ? Plugin::getInstance()->checkout->hostedUrl($order) : null;
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings->withoutSecrets();
    }

    public function getCurrency(): string
    {
        return $this->getSettings()->currency;
    }

    public function formatMoney(int $amount, ?string $currency = null, bool $stripZeros = false): string
    {
        return Money::format($amount, $currency ?? $this->getCurrency(), $stripZeros);
    }

    /**
     * A plain decimal for form values: 1050 becomes "10.50".
     */
    public function decimal(int $amount, ?string $currency = null): string
    {
        return Money::toDecimal($amount, $currency ?? $this->getCurrency());
    }

    public function getIsTestMode(): bool
    {
        return Plugin::getInstance()->stripe->isTestMode();
    }

    /**
     * Printed into the payment page, so it is only ever a publishable key: a secret key pasted into the wrong
     * setting must not reach the browser. The Stripe utility reports the mistake.
     */
    public function getPublishableKey(): ?string
    {
        $key = Plugin::getInstance()->stripe->publishableKey();

        return $key !== null && str_starts_with($key, 'pk_') ? $key : null;
    }

    public function getStripeJsUrl(): string
    {
        return Stripe::STRIPE_JS_URL;
    }

    /**
     * Adds Small Change's front-end script to the page, through the layout's endBody(). Pass true on a page that
     * shows the payment form, which also loads Stripe.js first (it has to come from Stripe, never a copy).
     */
    public function registerScripts(bool $payment = false): void
    {
        $view = Craft::$app->getView();

        if ($payment) {
            $view->registerJsFile(Stripe::STRIPE_JS_URL, ['position' => View::POS_END], 'small-change-stripe-js');
        }

        $view->registerAssetBundle(FrontEndAsset::class);
    }

    public function getGiftAid(): GiftAidVariable
    {
        return new GiftAidVariable(Plugin::getInstance()->giftAid);
    }

    public function getCheckoutMode(): string
    {
        return $this->getSettings()->checkoutMode;
    }
}
