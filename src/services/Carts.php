<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\helpers\Deadlock;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\base\Component;
use craft\elements\Address;
use craft\web\Application as WebApplication;
use DateTime;
use yii\base\Exception;

/**
 * The visitor's basket. It is an ordinary Order in the `cart` state, found through the session, so
 * there is nothing to convert at checkout and nothing anyone else can reach by guessing a number.
 */
class Carts extends Component
{
    private const SESSION_KEY = 'smallChange.cartNumber';
    private const MAX_LINES = 50;
    private const ADDRESS_ATTRIBUTES = ['firstName', 'lastName', 'addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode'];

    /**
     * This visitor's cart, or null if they have none. Pass true to start one.
     */
    public function getCart(bool $create = false): ?Order
    {
        if (!Craft::$app instanceof WebApplication) {
            return null;
        }

        $session = Craft::$app->getSession();
        $number = $session->get(self::SESSION_KEY);
        $cart = is_string($number) && $number !== '' ? Order::find()->number($number)->one() : null;

        // A paid or cancelled order is history, not a basket.
        if ($cart && in_array($cart->state, [Order::STATE_COMPLETED, Order::STATE_CANCELLED], true)) {
            $session->remove(self::SESSION_KEY);
            $cart = null;
        }

        if ($cart === null && $create) {
            $cart = $this->createCart();
        }

        return $cart;
    }

    public function forget(): void
    {
        if (Craft::$app instanceof WebApplication) {
            Craft::$app->getSession()->remove(self::SESSION_KEY);
        }
    }

    /**
     * Adds a product, or changes the amount of a donation already in the cart.
     *
     * @return string|null why it could not be added
     */
    public function addProduct(Order $cart, Product $product, int $quantity = 1, mixed $amountInput = null): ?string
    {
        $plugin = Plugin::getInstance();

        if (!$product->enabled || $product->getStatus() !== Product::STATUS_ENABLED) {
            return Craft::t('small-change', 'That item is not available.');
        }

        $lines = $cart->getLineItems();
        $existing = null;

        foreach ($lines as $line) {
            if ($line->purchasableId === $product->id) {
                $existing = $line;
            }
        }

        if ($existing === null && count($lines) >= self::MAX_LINES) {
            return Craft::t('small-change', 'Your basket is full.');
        }

        if ($product->getIsDonation()) {
            [$amount, $error] = $plugin->orders->checkDonationAmount($product, $amountInput);

            if ($amount === null) {
                return $error;
            }

            $line = $existing ?? $this->newLine($product);
            $line->unitPrice = $amount;
            $line->qty = 1;
        } else {
            if ($product->price === null) {
                return Craft::t('small-change', 'That item is not available.');
            }

            $quantity = max(1, $quantity);
            $total = ($existing->qty ?? 0) + $quantity;

            if ($total > LineItem::MAX_QUANTITY) {
                return Craft::t('small-change', "You can't order more than {max} of one item.", ['max' => LineItem::MAX_QUANTITY]);
            }

            $line = $existing ?? $this->newLine($product);
            $line->unitPrice = $product->price;
            $line->qty = $total;
        }

        $this->begin($cart);

        if ($existing === null) {
            $lines[] = $line;
        }

        $cart->setLineItems($lines);

        return null;
    }

    /**
     * Sets quantities for products already in the cart. Zero removes the line; donations have no quantity.
     *
     * @param array<int|string, mixed> $quantities product ID => quantity
     */
    public function setQuantities(Order $cart, array $quantities): void
    {
        $this->begin($cart);
        $lines = [];

        foreach ($cart->getLineItems() as $line) {
            $requested = $line->purchasableId !== null ? ($quantities[$line->purchasableId] ?? null) : null;

            if ($requested !== null && !$line->getIsDonation()) {
                $quantity = (int) $requested;

                if ($quantity <= 0) {
                    continue;
                }

                $line->qty = min($quantity, LineItem::MAX_QUANTITY);
            }

            $lines[] = $line;
        }

        $cart->setLineItems($lines);
    }

    public function removeProduct(Order $cart, int $productId): void
    {
        $this->begin($cart);
        $cart->setLineItems(array_values(array_filter(
            $cart->getLineItems(),
            static fn(LineItem $line): bool => $line->purchasableId !== $productId,
        )));
    }

    /**
     * @return string|null why the code can't be used
     */
    public function applyDiscount(Order $cart, string $code): ?string
    {
        $plugin = Plugin::getInstance();
        $discount = $plugin->discounts->getDiscountByCode($code);

        if (!$discount instanceof Discount) {
            return Craft::t('small-change', 'That code is not valid.');
        }

        $problem = $plugin->discounts->problemWith($discount, $cart);

        if ($problem !== null) {
            return $problem;
        }

        $this->begin($cart);
        $cart->discountId = $discount->id;
        $cart->discountCode = $discount->code;

        return null;
    }

    public function removeDiscount(Order $cart): void
    {
        $this->begin($cart);
        $cart->discountId = null;
        $cart->discountCode = null;
    }

    /**
     * Applies whichever checkout details were posted. Anything not posted is left alone, so the cart
     * page and the details page can each send only their own part.
     *
     * @param array<string, mixed> $post
     */
    public function applyDetails(Order $cart, array $post): void
    {
        $this->begin($cart);

        if (array_key_exists('email', $post)) {
            $email = Customers::normaliseEmail((string) $post['email']);
            $cart->email = $email !== '' ? mb_substr($email, 0, 255) : null;
        }

        if (array_key_exists('customerNote', $post)) {
            $note = trim((string) $post['customerNote']);
            $cart->customerNote = $note !== '' ? mb_substr($note, 0, 1000) : null;
        }

        if (array_key_exists('giftAid', $post)) {
            $cart->giftAidDeclared = filter_var($post['giftAid'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($post['shippingAddress']) && is_array($post['shippingAddress'])) {
            $cart->setShippingAddress($this->applyAddress($cart->getShippingAddress(), $post['shippingAddress'], Craft::t('small-change', 'Delivery address')));
        }

        if (isset($post['billingAddress']) && is_array($post['billingAddress'])) {
            $cart->setBillingAddress($this->applyAddress($cart->getBillingAddress(), $post['billingAddress'], Craft::t('small-change', 'Home address')));
        }

        if (array_key_exists('shippingMethod', $post)) {
            $method = Plugin::getInstance()->shippingMethods->getMethodById((int) $post['shippingMethod']);
            $cart->shippingMethodId = $method && $method->enabled ? $method->id : null;
        }

        if (isset($post['fields']) && is_array($post['fields'])) {
            // A basket started before the checkout fields existed picks them up now.
            Plugin::getInstance()->orders->useFieldLayout($cart, Orders::LAYOUT_CHECKOUT);

            foreach (Plugin::getInstance()->orders->getCheckoutFields() as $field) {
                if (array_key_exists($field->handle, $post['fields'])) {
                    $cart->setFieldValueFromRequest($field->handle, $post['fields'][$field->handle]);
                }
            }
        }
    }

    /**
     * Works the cart out again and saves it. Every change goes through here.
     *
     * @return array<string, string> notices for the customer
     * @throws Exception
     */
    public function save(Order $cart): array
    {
        $notices = Plugin::getInstance()->orders->recalculate($cart);

        // Only someone who gave up their home address by ticking the box has one kept.
        if ($cart->giftAidDeclared !== true) {
            $cart->setBillingAddress(null);
        }

        $user = Craft::$app->getUser()->getIdentity();
        $cart->userId ??= $user?->id;
        $cart->email ??= $user?->email;
        $cart->dateLastActivity = new DateTime();

        $wasNew = $cart->id === null;

        Deadlock::retry(function() use ($cart, $wasNew): void {
            // A new cart that lost a deadlock was rolled back whole, so it starts again as new.
            if ($wasNew) {
                $cart->id = null;
            }

            if (!Craft::$app->getElements()->saveElement($cart, false, false)) {
                throw new Exception('Could not save the cart: ' . implode(' ', $cart->getFirstErrors()));
            }
        });

        if (Craft::$app instanceof WebApplication) {
            Craft::$app->getSession()->set(self::SESSION_KEY, $cart->number);
        }

        return $notices;
    }

    /**
     * What a template or script needs to draw the basket. Never includes the customer's details.
     *
     * @return array<string, mixed>
     */
    public function summary(Order $cart): array
    {
        return [
            'number' => $cart->number,
            'currency' => $cart->currency,
            'count' => array_sum(array_map(static fn(LineItem $line): int => $line->getIsDonation() ? 1 : $line->qty, $cart->getLineItems())),
            'lines' => array_map(static fn(LineItem $line): array => [
                'productId' => $line->purchasableId,
                'description' => $line->description,
                'sku' => $line->sku,
                'kind' => $line->kind,
                'qty' => $line->qty,
                'unitPrice' => $line->unitPrice,
                'subtotal' => $line->subtotal,
            ], $cart->getLineItems()),
            'itemSubtotal' => $cart->itemSubtotal,
            'discountCode' => $cart->discountCode,
            'discountAmount' => $cart->discountAmount,
            'shippingMethod' => $cart->shippingMethodName,
            'shippingAmount' => $cart->shippingAmount,
            'total' => $cart->total,
            'requiresShipping' => $cart->getRequiresShipping(),
        ];
    }

    /**
     * A started payment can't survive a change to the basket: the old Checkout Session would still
     * take the old amount. Pull it back to a cart before touching anything.
     */
    private function begin(Order $cart): void
    {
        Plugin::getInstance()->orders->cancelStartedCheckout($cart);
    }

    private function createCart(): Order
    {
        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();

        $cart = new Order();
        $cart->orderSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $cart->number = Order::generateNumber();
        $cart->currency = $plugin->getSettings()->currency;
        $cart->kind = Order::KIND_ONE_OFF;
        $cart->state = Order::STATE_CART;
        $cart->stripeMode = $plugin->stripe->mode();
        $cart->userId = $user?->id;
        $cart->email = $user?->email;
        $cart->lastIp = Craft::$app->getRequest()->getUserIP();
        $plugin->orders->useFieldLayout($cart, Orders::LAYOUT_CHECKOUT);

        return $cart;
    }

    private function newLine(Product $product): LineItem
    {
        return new LineItem([
            'kind' => $product->getLineItemKind(),
            'description' => $product->getDescription(),
            'sku' => $product->getSku(),
            'purchasableId' => $product->getPurchasableId(),
            'unitPrice' => (int) $product->price,
            'qty' => 1,
            // Only a donation can be Gift Aided; payments for goods never qualify.
            'isGiftAidEligible' => $product->getIsDonation(),
            'snapshot' => ['productId' => $product->id, 'title' => $product->title, 'type' => $product->getType()->handle],
        ]);
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function applyAddress(?Address $existing, array $posted, string $label): Address
    {
        $address = $existing ?? new Address();

        foreach (self::ADDRESS_ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $posted) && is_string($posted[$attribute])) {
                $address->$attribute = mb_substr(trim($posted[$attribute]), 0, 255);
            }
        }

        // GB is the default because most sites using this are UK charities.
        $address->countryCode = strtoupper((string) $address->countryCode) ?: 'GB';
        $address->title = $label;

        return $address;
    }
}
