<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\models\ShippingMethod;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\ProductTypeRecord;
use coyshdigital\smallchange\records\ProductTypeSiteRecord;
use coyshdigital\smallchange\services\Orders;
use coyshdigital\smallchange\services\ProductTypes;
use Craft;
use craft\elements\Address;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Lightswitch;
use craft\fields\PlainText;
use craft\fields\Url;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

/**
 * Shared set-up for tests that need a donation product, a fake Stripe and an order.
 */
trait Fixtures
{
    protected FakeStripe $stripe;
    protected FakeEmails $emails;

    protected function useFakes(): void
    {
        $plugin = Plugin::getInstance();

        $this->stripe = new FakeStripe();
        $this->emails = new FakeEmails();
        $plugin->set('stripe', $this->stripe);
        $plugin->set('emails', $this->emails);

        /** @var \coyshdigital\smallchange\models\Settings $settings */
        $settings = $plugin->getSettings();
        $settings->currency = 'GBP';
        $settings->checkoutMode = 'elements';
        $settings->giftAidEnabled = true;
        $settings->charityName = 'Small Church';
        $settings->adminEmail = 'admin@example.test';
    }

    /**
     * @param int[] $suggested
     */
    protected function makeProduct(array $suggested = [500, 1000, 2500], ?int $minimum = 200, bool $custom = true, string $title = 'General fund'): Product
    {
        $record = new ProductTypeRecord();
        $record->name = 'Donation';
        $record->handle = 'donation' . bin2hex(random_bytes(3));
        $record->kind = ProductType::KIND_DONATION;
        $record->skuRequired = false;
        $record->sortOrder = 1;
        $record->save(false);

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $site = new ProductTypeSiteRecord();
            $site->productTypeId = $record->id;
            $site->siteId = $siteId;
            $site->hasUrls = false;
            $site->save(false);
        }

        // The service caches types, so give it a fresh view that includes this one.
        Plugin::getInstance()->set('productTypes', new ProductTypes());

        $product = new Product([
            'typeId' => $record->id,
            'title' => $title,
            'suggestedAmounts' => $suggested,
            'minimumAmount' => $minimum,
            'allowCustomAmount' => $custom,
        ]);

        if (!Craft::$app->getElements()->saveElement($product)) {
            throw new \RuntimeException('Could not save the test product: ' . json_encode($product->getErrors()));
        }

        return $product;
    }

    /**
     * A physical or non-physical product with a fixed price.
     */
    protected function makeShopProduct(int $price = 1500, string $kind = ProductType::KIND_PHYSICAL, string $title = 'Tea towel', ?string $sku = null): Product
    {
        $record = new ProductTypeRecord();
        $record->name = ucfirst($kind);
        $record->handle = 'shop' . bin2hex(random_bytes(3));
        $record->kind = $kind;
        $record->skuRequired = false;
        $record->sortOrder = 1;
        $record->save(false);

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $site = new ProductTypeSiteRecord();
            $site->productTypeId = $record->id;
            $site->siteId = $siteId;
            $site->hasUrls = false;
            $site->save(false);
        }

        Plugin::getInstance()->set('productTypes', new ProductTypes());

        $product = new Product(['typeId' => $record->id, 'title' => $title, 'price' => $price, 'sku' => $sku]);

        if (!Craft::$app->getElements()->saveElement($product)) {
            throw new \RuntimeException('Could not save the test product: ' . json_encode($product->getErrors()));
        }

        return $product;
    }

    protected function makeShippingMethod(string $name = 'Royal Mail', int $price = 350, ?array $countries = null): ShippingMethod
    {
        $method = new ShippingMethod(['name' => $name, 'handle' => strtolower(preg_replace('/\W+/', '', $name)) . bin2hex(random_bytes(2)), 'price' => $price, 'countryCodes' => $countries]);

        if (!Plugin::getInstance()->shippingMethods->saveMethod($method)) {
            throw new \RuntimeException('Could not save the test shipping method: ' . json_encode($method->getErrors()));
        }

        return $method;
    }

    protected function makeDiscount(string $code = 'SAVE10', string $type = Discount::TYPE_PERCENT, int $value = 1000, ?int $maxUses = null, ?int $minSubtotal = null): Discount
    {
        $discount = new Discount([
            'name' => $code,
            'code' => $code,
            'type' => $type,
            'percent' => $type === Discount::TYPE_PERCENT ? $value : null,
            'amount' => $type === Discount::TYPE_FIXED ? $value : null,
            'maxUses' => $maxUses,
            'minSubtotal' => $minSubtotal,
        ]);

        if (!Plugin::getInstance()->discounts->saveDiscount($discount)) {
            throw new \RuntimeException('Could not save the test discount: ' . json_encode($discount->getErrors()));
        }

        return $discount;
    }

    /**
     * A cart with the given products in it, saved.
     *
     * @param array<int, array{Product, int}> $items product and quantity
     */
    protected function makeCart(array $items): Order
    {
        $carts = Plugin::getInstance()->carts;
        Craft::$app->getSession()->remove('smallChange.cartNumber');
        $cart = $carts->getCart(true);

        foreach ($items as [$product, $quantity]) {
            $error = $carts->addProduct($cart, $product, $quantity, '10');

            if ($error !== null) {
                throw new \RuntimeException('Could not add to the test cart: ' . $error);
            }
        }

        $carts->save($cart);

        return $cart;
    }

    /**
     * Gives a cart everything checkout needs for a delivered order.
     */
    protected function addDeliveryDetails(Order $cart, string $country = 'GB'): void
    {
        Plugin::getInstance()->carts->applyDetails($cart, [
            'email' => 'buyer@example.test',
            'shippingAddress' => ['firstName' => 'Grace', 'lastName' => 'Hopper', 'addressLine1' => '1 High Street', 'locality' => 'London', 'postalCode' => 'SW1A 1AA', 'countryCode' => $country],
        ]);
        Plugin::getInstance()->carts->save($cart);
    }

    protected function homeAddress(): Address
    {
        return new Address([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'addressLine1' => '12 St James Square',
            'locality' => 'London',
            'postalCode' => 'SW1Y 4JH',
            'countryCode' => 'GB',
        ]);
    }

    /**
     * A saved donation order, ready for Checkout::start().
     */
    protected function makeOrder(?Product $product = null, string $amount = '25', bool $giftAid = false, string $email = 'Donor@Example.test'): Order
    {
        $product ??= $this->makeProduct();
        $order = Plugin::getInstance()->orders->buildDonation($product, $amount, $email, $giftAid, $giftAid ? $this->homeAddress() : null, 'donate/thanks');

        if ($order->hasErrors()) {
            throw new \RuntimeException('Test order is invalid: ' . json_encode($order->getErrors()));
        }

        if (!Craft::$app->getElements()->saveElement($order)) {
            throw new \RuntimeException('Could not save the test order: ' . json_encode($order->getErrors()));
        }

        return $order;
    }

    /**
     * A saved order that has been through Checkout::start().
     */
    protected function makePendingOrder(string $amount = '25', bool $giftAid = false): Order
    {
        $order = $this->makeOrder(null, $amount, $giftAid);
        Plugin::getInstance()->checkout->start($order, 'donate/payment');

        return $order;
    }

    /**
     * Saves a layout of custom fields for baskets or for donations, the way the settings page does.
     *
     * @param array<string, bool> $fields handle => required
     * @param string $for Orders::LAYOUT_CHECKOUT or Orders::LAYOUT_DONATION
     */
    protected function giveOrderFields(array $fields, string $for = Orders::LAYOUT_CHECKOUT): void
    {
        $fieldsService = Craft::$app->getFields();
        $elements = [];

        foreach ($fields as $handle => $required) {
            $field = match ($handle) {
                'giftMessage', 'dietary' => new PlainText(['name' => ucfirst($handle), 'handle' => $handle]),
                'wrapped' => new Lightswitch(['name' => 'Wrapped', 'handle' => $handle]),
                default => new Url(['name' => 'Website', 'handle' => $handle]),
            };

            if (!$fieldsService->saveField($field)) {
                throw new \RuntimeException('Could not save field ' . $handle . ': ' . json_encode($field->getErrors()));
            }

            $element = new CustomField($field);
            $element->required = $required;
            $elements[] = $element;
        }

        $layout = new FieldLayout(['type' => Order::class]);
        $tab = new FieldLayoutTab(['name' => 'Checkout', 'layout' => $layout]);
        $tab->setElements($elements);
        $layout->setTabs([$tab]);

        Plugin::getInstance()->orders->saveFieldLayout($layout, $for);
    }
}
