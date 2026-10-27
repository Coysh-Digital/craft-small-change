<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\events\OrderEvent;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\models\Payment;
use coyshdigital\smallchange\models\Settings;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\DiscountRecord;
use coyshdigital\smallchange\records\ShippingMethodRecord;
use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\db\Table as CraftTable;
use craft\elements\Address;
use craft\events\ConfigEvent;
use craft\events\RebuildConfigEvent;
use craft\fields\Checkboxes;
use craft\fields\Country;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Lightswitch;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Time;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\web\Application as WebApplication;
use DateTime;
use Stripe\StripeObject;
use yii\base\Exception;
use yii\db\Query;

/**
 * Orders: building them, proving who may see them, and completing them exactly once.
 */
class Orders extends Component
{
    public const EVENT_BEFORE_COMPLETE_ORDER = 'beforeCompleteOrder';
    public const EVENT_AFTER_COMPLETE_ORDER = 'afterCompleteOrder';

    private const SESSION_KEY = 'smallChange.recentOrders';
    private const SESSION_LIMIT = 20;

    /**
     * How long the link back from Stripe stays valid. Rounded up to ten minutes so that repeating a
     * request within that window builds identical Checkout Session parameters.
     */
    private const TOKEN_TTL = 7200;
    private const TOKEN_BUCKET = 600;

    /**
     * Builds an unsaved one-off donation order. Anything wrong is added to the order as an error
     * so a form can show it beside the field.
     */
    public function buildDonation(Product $product, mixed $amountInput, string $email, bool $giftAidTicked, ?Address $homeAddress, ?string $returnUrl, ?string $interval = null): Order
    {
        $settings = $this->settings();
        $plugin = Plugin::getInstance();

        $order = new Order();
        $order->orderSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $order->currency = $settings->currency;
        $order->kind = Order::KIND_ONE_OFF;
        $order->state = Order::STATE_CART;
        $order->stripeMode = $plugin->stripe->mode();
        $order->email = Customers::normaliseEmail($email);
        $order->returnUrl = $returnUrl;
        $order->lastIp = Craft::$app->getRequest()->getIsConsoleRequest() ? null : Craft::$app->getRequest()->getUserIP();
        $order->dateLastActivity = new DateTime();
        $order->number = Order::generateNumber();

        // Same as a basket: a signed-in donor's gift is theirs to see on their account.
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $order->userId = Craft::$app->getUser()->getIdentity()?->id;
        }

        $this->useFieldLayout($order, self::LAYOUT_DONATION);

        if (!$product->getIsDonation() || !$product->enabled || $product->getStatus() !== Product::STATUS_ENABLED) {
            $order->addError('productId', Craft::t('small-change', 'That donation is not available.'));

            return $order;
        }

        if ($order->email === '' || !filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            $order->addError('email', Craft::t('small-change', 'Enter a valid email address so we can send your receipt.'));
        }

        $interval = in_array($interval, [null, '', 'once'], true) ? null : $interval;

        if ($interval !== null) {
            if (!$product->allowRecurring || !in_array($interval, $product->getAllowedIntervals(), true)) {
                $order->addError('interval', Craft::t('small-change', "That donation can't be made {interval}.", ['interval' => $interval === 'year' ? Craft::t('small-change', 'yearly') : Craft::t('small-change', 'monthly')]));

                return $order;
            }

            $order->kind = Order::KIND_RECURRING;
        }

        [$amount, $amountError] = $this->checkDonationAmount($product, $amountInput);

        if ($amountError !== null) {
            $order->addError('amount', $amountError);
        }

        if ($amount === null || $order->hasErrors()) {
            return $order;
        }

        $line = new LineItem([
            'kind' => LineItem::KIND_DONATION,
            'description' => $product->getDescription(),
            'sku' => $product->getSku(),
            'purchasableId' => $product->getPurchasableId(),
            'unitPrice' => $amount,
            'qty' => 1,
            'recurringInterval' => $interval,
            'isGiftAidEligible' => true,
            'snapshot' => ['productId' => $product->id, 'title' => $product->title, 'type' => $product->getType()->handle],
        ]);

        $order->setLineItems([$line]);
        $totals = $plugin->totals->calculate($order->getLineItems());
        $order->setTotals($totals);

        $plugin->giftAid->apply($order, $giftAidTicked, $totals);

        if ($order->giftAidDeclared === true) {
            $this->validateGiftAidAddress($order, $homeAddress);

            // Only a donor who says yes has their home address kept.
            if (!$order->hasErrors()) {
                $order->setBillingAddress($homeAddress);
            }
        }

        return $order;
    }

    /**
     * Checks what a donor typed against what the product allows. The browser picks the amount, so
     * "only the suggested amounts" and the minimum are enforced here, never in the form.
     *
     * @return array{0: ?int, 1: ?string} the amount in minor units, and a message when it is not acceptable
     */
    public function checkDonationAmount(Product $product, mixed $input): array
    {
        $currency = $this->settings()->currency;
        $amount = Money::parse($input, $currency);
        $minimum = $product->getEffectiveMinimum();

        if ($amount === null) {
            return [null, Craft::t('small-change', 'Enter the amount you would like to give.')];
        }

        if ($amount < $minimum) {
            return [null, Craft::t('small-change', 'The smallest gift we can take is {amount}.', ['amount' => Money::format($minimum, $currency, true)])];
        }

        if (!$product->allowCustomAmount && !in_array($amount, $product->suggestedAmounts ?? [], true)) {
            return [null, Craft::t('small-change', 'Please choose one of the suggested amounts.')];
        }

        return [$amount, null];
    }

    /**
     * Adds an error for each thing a Gift Aid declaration still needs from the donor's home address.
     */
    public function validateGiftAidAddress(Order $order, ?Address $homeAddress): void
    {
        $giftAid = Plugin::getInstance()->giftAid;

        foreach ($giftAid->missingAddressFields($homeAddress) as $attribute => $label) {
            $order->addError('billingAddress.' . $attribute, Craft::t('small-change', '{field} is required for a Gift Aid declaration.', ['field' => $label]));
        }

        if ($homeAddress === null) {
            return;
        }

        $homeAddress->countryCode = $homeAddress->countryCode ?: 'GB';
        // Craft addresses need a label; this one is only ever the donor's home.
        $homeAddress->title = $homeAddress->title ?: Craft::t('small-change', 'Home address');

        if (!$homeAddress->validate()) {
            foreach ($homeAddress->getErrors() as $attribute => $messages) {
                foreach ($messages as $message) {
                    $order->addError('billingAddress.' . $attribute, $message);
                }
            }
        }
    }

    /**
     * The one place an order's money is worked out. It refreshes product lines from the live products,
     * picks the delivery method, checks the discount and sets every total. Call it after any change and
     * again before taking payment: nothing the browser said about prices is ever used.
     *
     * @return array<string, string> notices for the customer, keyed by what changed (`items`, `discount`, `shipping`)
     */
    public function recalculate(Order $order): array
    {
        $plugin = Plugin::getInstance();
        $notices = [];
        $products = $this->liveProductsFor($order->getLineItems());

        $lines = [];
        foreach ($order->getLineItems() as $item) {
            $product = $item->purchasableId !== null ? ($products[$item->purchasableId] ?? null) : null;

            if (!$product instanceof Product) {
                $notices['items'] = Craft::t('small-change', '{item} is no longer available and was removed.', ['item' => $item->description]);
                continue;
            }

            if (!$item->getIsDonation()) {
                if ($product->price === null) {
                    $notices['items'] = Craft::t('small-change', '{item} is no longer available and was removed.', ['item' => $item->description]);
                    continue;
                }

                if ($product->price !== $item->unitPrice) {
                    $notices['items'] = Craft::t('small-change', 'The price of {item} has changed.', ['item' => $product->getDescription()]);
                }

                $item->unitPrice = $product->price;
                $item->description = $product->getDescription();
                $item->sku = $product->getSku();
            }

            $item->qty = max(1, min(LineItem::MAX_QUANTITY, $item->qty));
            $lines[] = $item;
        }

        $order->setLineItems($lines);

        $this->chooseShippingMethod($order);

        $discountSpec = null;
        if ($order->discountId) {
            $discount = $plugin->discounts->getDiscountById($order->discountId);
            $problem = $discount ? $plugin->discounts->problemWith($discount, $order) : Craft::t('small-change', 'That code is not valid.');

            if ($discount && $problem === null) {
                $discountSpec = $discount->toSpec();
            } else {
                $notices['discount'] = Craft::t('small-change', 'Your discount code was removed: {reason}', ['reason' => $problem]);
                $order->discountId = null;
                $order->discountCode = null;
            }
        }

        $totals = $plugin->totals->calculate($order->getLineItems(), $discountSpec, $order->shippingAmount);
        $order->setTotals($totals);

        $plugin->giftAid->apply($order, $order->giftAidDeclared === true, $totals);

        return $notices;
    }

    /**
     * The enabled products behind a set of lines, in one query rather than one per line.
     *
     * @param LineItem[] $lineItems
     * @return array<int, Product> keyed by product ID
     */
    private function liveProductsFor(array $lineItems): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn(LineItem $item): ?int => $item->purchasableId, $lineItems))));

        if ($ids === []) {
            // An empty id list would mean "any product" to the query, so it is never asked.
            return [];
        }

        /** @var array<int, Product> $products */
        $products = Product::find()->id($ids)->status(Product::STATUS_ENABLED)->indexBy('id')->all();

        return $products;
    }

    /**
     * Delivery is only charged for orders with something to deliver. A method that no longer serves the
     * address is dropped, and when exactly one method serves it there is nothing for the customer to choose.
     */
    private function chooseShippingMethod(Order $order): void
    {
        if (!$order->getRequiresShipping()) {
            $order->shippingMethodId = null;
            $order->shippingMethodName = null;
            $order->shippingAmount = 0;

            return;
        }

        $country = $order->getShippingCountryCode();
        $methods = $country !== null ? Plugin::getInstance()->shippingMethods->getApplicableMethods($country) : [];
        $chosen = null;

        foreach ($methods as $method) {
            if ($method->id === $order->shippingMethodId) {
                $chosen = $method;
            }
        }

        if ($chosen === null && count($methods) === 1) {
            $chosen = $methods[0];
        }

        $order->shippingMethodId = $chosen ? $chosen->id : null;
        $order->shippingMethodName = $chosen ? $chosen->name : null;
        $order->shippingAmount = $chosen ? $chosen->price : 0;
    }

    /**
     * Everything that must be true before the customer is sent to pay, as errors on the order.
     */
    public function validateForCheckout(Order $order): void
    {
        if (!$order->getLineItems()) {
            $order->addError('items', Craft::t('small-change', 'Your basket is empty.'));
        }

        if ($order->email === null || $order->email === '' || !filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            $order->addError('email', Craft::t('small-change', 'Enter a valid email address so we can send your receipt.'));
        }

        if ($order->getRequiresShipping()) {
            $address = $order->getShippingAddress();

            if ($address === null) {
                $order->addError('shippingAddress', Craft::t('small-change', 'Enter a delivery address.'));
            } else {
                $address->countryCode = $address->countryCode ?: 'GB';
                $address->title = $address->title ?: Craft::t('small-change', 'Delivery address');

                $missing = array_filter(
                    ['firstName' => 'First name', 'lastName' => 'Last name', 'addressLine1' => 'Address', 'postalCode' => 'Postcode'],
                    static fn(string $label, string $attribute): bool => trim((string) $address->$attribute) === '',
                    ARRAY_FILTER_USE_BOTH,
                );

                foreach ($missing as $attribute => $label) {
                    $order->addError('shippingAddress.' . $attribute, Craft::t('small-change', '{field} is required for delivery.', ['field' => Craft::t('small-change', $label)]));
                }

                if (!$missing && !$address->validate()) {
                    foreach ($address->getErrors() as $attribute => $messages) {
                        foreach ($messages as $message) {
                            $order->addError('shippingAddress.' . $attribute, $message);
                        }
                    }
                }
            }

            if ($order->shippingMethodId === null && $address !== null && !$order->hasErrors('shippingAddress')) {
                $order->addError('shippingMethod', Plugin::getInstance()->shippingMethods->getApplicableMethods($order->getShippingCountryCode())
                    ? Craft::t('small-change', 'Choose a delivery method.')
                    : Craft::t('small-change', "We don't deliver to that country."));
            }
        }

        if ($order->giftAidDeclared === true) {
            $this->validateGiftAidAddress($order, $order->getBillingAddress());
        }
    }

    // Checkout and donation fields
    // =========================================================================

    public const LAYOUT_CHECKOUT = 'checkout';
    public const LAYOUT_DONATION = 'donation';

    public const FIELD_LAYOUT_PATH = 'smallChange.orders.fieldLayouts';
    public const DONATION_FIELD_LAYOUT_PATH = 'smallChange.orders.donationFieldLayouts';

    /**
     * The only field types a visitor may fill in at checkout. The rest (relations, assets, rich content)
     * need controls and permissions that don't belong on a public form. Range and Button Group arrived
     * after Craft 5.0, so they are named as strings and simply never match on an install without them.
     */
    public const CHECKOUT_FIELD_TYPES = [
        PlainText::class,
        Number::class,
        'craft\fields\Range',
        Dropdown::class,
        RadioButtons::class,
        Checkboxes::class,
        MultiSelect::class,
        'craft\fields\ButtonGroup',
        Lightswitch::class,
        Date::class,
        Time::class,
        Email::class,
        Country::class,
    ];

    /** @var array<string, FieldLayout> */
    private array $fieldLayouts = [];

    public static function layoutPath(string $for): string
    {
        return $for === self::LAYOUT_DONATION ? self::DONATION_FIELD_LAYOUT_PATH : self::FIELD_LAYOUT_PATH;
    }

    /**
     * The layout of the custom fields an order carries, such as "gift message" or "dietary needs".
     * Checkout fields are asked in the basket; donation fields on the donation form. Both are layouts
     * of the order element, told apart by which project config path holds their uid.
     */
    public function getFieldLayout(string $for = self::LAYOUT_CHECKOUT): FieldLayout
    {
        return $this->fieldLayouts[$for] ??= $this->loadFieldLayout($for);
    }

    private function loadFieldLayout(string $for): FieldLayout
    {
        $uid = array_key_first(Craft::$app->getProjectConfig()->get(self::layoutPath($for)) ?? []);
        $layout = $uid !== null ? Craft::$app->getFields()->getLayoutByUid((string) $uid) : null;

        return $layout ?? new FieldLayout(['type' => Order::class]);
    }

    /**
     * Gives a new order the fields of the form it came from. Without a layout yet there is nothing
     * to ask, and the order stays without one rather than borrowing the other form's fields.
     */
    public function useFieldLayout(Order $order, string $for): void
    {
        $layout = $this->getFieldLayout($for);

        if ($order->fieldLayoutId === null && $layout->id) {
            $order->fieldLayoutId = $layout->id;
        }
    }

    /**
     * Fields a visitor may fill in at checkout.
     *
     * @return FieldInterface[]
     */
    public function getCheckoutFields(): array
    {
        return $this->supportedFields(self::LAYOUT_CHECKOUT);
    }

    /**
     * Fields a donor may fill in on the donation form.
     *
     * @return FieldInterface[]
     */
    public function getDonationFields(): array
    {
        return $this->supportedFields(self::LAYOUT_DONATION);
    }

    /**
     * Fields in a layout that the public form will ignore, so the settings page can warn about them.
     *
     * @return FieldInterface[]
     */
    public function getUnsupportedFields(string $for = self::LAYOUT_CHECKOUT): array
    {
        return array_values(array_filter(
            $this->getFieldLayout($for)->getCustomFields(),
            static fn(FieldInterface $field): bool => !in_array($field::class, self::CHECKOUT_FIELD_TYPES, true),
        ));
    }

    /**
     * @return FieldInterface[]
     */
    private function supportedFields(string $for): array
    {
        return array_values(array_filter(
            $this->getFieldLayout($for)->getCustomFields(),
            static fn(FieldInterface $field): bool => in_array($field::class, self::CHECKOUT_FIELD_TYPES, true),
        ));
    }

    public function saveFieldLayout(FieldLayout $layout, string $for = self::LAYOUT_CHECKOUT): void
    {
        $layout->type = Order::class;
        $existing = $this->getFieldLayout($for);
        $layout->uid = $existing->uid ?: StringHelper::UUID();
        $config = $layout->getConfig();
        $projectConfig = Craft::$app->getProjectConfig();
        $path = self::layoutPath($for);

        if ($config) {
            $projectConfig->set($path . '.' . $layout->uid, $config, 'Save the ' . $for . ' field layout');
        } elseif ($existing->id) {
            $projectConfig->remove($path . '.' . $existing->uid, 'Remove the ' . $for . ' field layout');
        }

        $this->fieldLayouts = [];
    }

    public function handleChangedFieldLayout(ConfigEvent $event): void
    {
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $layout = FieldLayout::createFromConfig($event->newValue);
        $layout->type = Order::class;
        $layout->uid = $event->tokenMatches[0];
        $layout->id = Db::idByUid(CraftTable::FIELDLAYOUTS, $layout->uid);

        Craft::$app->getFields()->saveLayout($layout, false);
        $this->fieldLayouts = [];
    }

    public function handleDeletedFieldLayout(ConfigEvent $event): void
    {
        // By uid, so removing one set of fields never takes the other with it.
        $id = Db::idByUid(CraftTable::FIELDLAYOUTS, $event->tokenMatches[0]);

        if ($id) {
            Craft::$app->getFields()->deleteLayoutById($id);
        }

        $this->fieldLayouts = [];
    }

    public function rebuildProjectConfig(RebuildConfigEvent $event): void
    {
        foreach ([self::LAYOUT_CHECKOUT => 'fieldLayouts', self::LAYOUT_DONATION => 'donationFieldLayouts'] as $for => $key) {
            $layout = $this->getFieldLayout($for);
            $config = $layout->getConfig();

            if ($config && $layout->uid) {
                $event->config['smallChange']['orders'][$key][$layout->uid] = $config;
            }
        }
    }

    // Who may see an order
    // =========================================================================

    public function addToSession(string $number): void
    {
        if (!Craft::$app instanceof WebApplication) {
            return;
        }

        $session = Craft::$app->getSession();
        $numbers = array_values(array_diff((array) $session->get(self::SESSION_KEY, []), [$number]));
        $numbers[] = $number;
        $session->set(self::SESSION_KEY, array_slice($numbers, -self::SESSION_LIMIT));
    }

    public function isInSession(string $number): bool
    {
        if (!Craft::$app instanceof WebApplication) {
            return false;
        }

        return in_array($number, (array) Craft::$app->getSession()->get(self::SESSION_KEY, []), true);
    }

    /**
     * The order, but only if this browser session placed it. An order number on its own proves nothing:
     * it appears in receipts and in Stripe.
     */
    public function recentOrder(?string $number): ?Order
    {
        if ($number === null || $number === '' || !$this->isInSession($number)) {
            return null;
        }

        return Order::find()->number($number)->one();
    }

    public function createAccessToken(Order $order, string $paymentUrl): string
    {
        $expires = (int) (ceil((time() + self::TOKEN_TTL) / self::TOKEN_BUCKET) * self::TOKEN_BUCKET);
        $payload = StringHelper::base64UrlEncode((string) json_encode(['n' => $order->number, 'e' => $expires, 'p' => $paymentUrl]));

        return Craft::$app->getSecurity()->hashData($payload);
    }

    /**
     * @return array{number: string, paymentUrl: string}|null null when the token is forged, malformed or expired
     */
    public function parseAccessToken(string $token): ?array
    {
        $payload = Craft::$app->getSecurity()->validateData($token);

        if (!is_string($payload)) {
            return null;
        }

        $data = json_decode(StringHelper::base64UrlDecode($payload), true);

        if (!is_array($data) || !is_string($data['n'] ?? null) || !is_int($data['e'] ?? null) || $data['e'] < time()) {
            return null;
        }

        return ['number' => $data['n'], 'paymentUrl' => is_string($data['p'] ?? null) ? $data['p'] : ''];
    }

    /**
     * Payments are real money in the currency they were taken in, so the currency is fixed once a live order exists.
     * Before that it can change: prices keep their numbers and test orders keep the currency they were placed in.
     */
    public function currencyIsLocked(): bool
    {
        return ($this->currencyHolders()['liveOrders'] ?? 0) > 0;
    }

    /**
     * What holds an amount in the current currency, with how many of each, so the settings page can say what a
     * change affects. Kinds with none are left out.
     *
     * @return array<string, int> keyed products, shippingMethods, discountCodes, liveOrders, testOrders
     */
    public function currencyHolders(): array
    {
        $placed = fn() => Order::find()->state([Order::STATE_PENDING, Order::STATE_COMPLETED, Order::STATE_CANCELLED])->status(null);
        $testOrders = (int) $placed()->stripeMode('test')->count();

        return array_filter([
            'products' => (int) Product::find()->status(null)->count(),
            'shippingMethods' => (int) ShippingMethodRecord::find()->count(),
            'discountCodes' => (int) DiscountRecord::find()->where(['type' => Discount::TYPE_FIXED])->count(),
            // Everything that isn't a test order, so an order with no recorded mode still counts.
            'liveOrders' => (int) $placed()->count() - $testOrders,
            'testOrders' => $testOrders,
        ]);
    }

    /**
     * Why the currency can't change from one to the other, or null when it can.
     */
    public function currencyChangeError(string $from, string $to): ?string
    {
        if (strtoupper($from) === strtoupper($to)) {
            return null;
        }

        $holders = $this->currencyHolders();

        if (($holders['liveOrders'] ?? 0) > 0) {
            return Craft::t('small-change', 'The currency cannot be changed once a live order exists.');
        }

        // A price of 500 is £5.00 but ¥500, so a change of decimal places would quietly reprice everything by a factor of 100.
        $priced = array_intersect_key($holders, array_flip(['products', 'shippingMethods', 'discountCodes']));

        if ($priced !== [] && Money::exponent($from) !== Money::exponent($to)) {
            return Craft::t('small-change', '{to} has a different number of decimal places to {from}, so every price would change. Delete your products, delivery methods and fixed-amount discount codes first, or choose a currency with the same number of decimal places.', [
                'from' => strtoupper($from),
                'to' => strtoupper($to),
            ]);
        }

        return null;
    }

    /**
     * Baskets are repriced before every checkout, so open ones simply carry on in the new currency.
     */
    public function moveCartsToCurrency(string $currency): int
    {
        return Db::update(Table::ORDERS, ['currency' => strtoupper($currency)], ['state' => Order::STATE_CART], updateTimestamp: false);
    }

    /**
     * What has been given to a donation product, in minor units, for campaign counters. It counts gifts
     * received: the donation line of each completed one-off order, and each recurring payment that
     * succeeded, less refunds on recurring payments. A one-off order that has been fully refunded is
     * left out; a partial refund on one is not deducted, since it can't be traced to the donation line.
     */
    public function donationTotal(int $productId, ?DateTime $from = null, ?DateTime $to = null): int
    {
        $counted = [Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY_REFUNDED, Order::PAYMENT_DISPUTED];
        // Gifts made with the other kind of key (test donations on a live site) are not part of any total.
        $mode = Plugin::getInstance()->stripe->mode();

        $oneOff = (new Query())
            ->select('SUM([[li.subtotal]])')
            ->from(['li' => Table::LINE_ITEMS])
            ->innerJoin(['o' => Table::ORDERS], '[[o.id]] = [[li.orderId]]')
            ->where(['li.purchasableId' => $productId, 'li.kind' => LineItem::KIND_DONATION, 'o.state' => Order::STATE_COMPLETED, 'o.kind' => Order::KIND_ONE_OFF])
            ->andWhere(['o.paymentStatus' => $counted]);

        $recurring = (new Query())
            ->select('SUM([[p.amount]] - [[p.amountRefunded]])')
            ->from(['p' => Table::PAYMENTS])
            ->innerJoin(['o' => Table::ORDERS], '[[o.id]] = [[p.orderId]]')
            ->innerJoin(['li' => Table::LINE_ITEMS], '[[li.orderId]] = [[o.id]]')
            ->where(['li.purchasableId' => $productId, 'li.kind' => LineItem::KIND_DONATION, 'o.kind' => Order::KIND_RECURRING, 'p.status' => Payment::STATUS_SUCCEEDED]);

        if ($mode !== null) {
            $oneOff->andWhere(['o.stripeMode' => $mode]);
            $recurring->andWhere(['p.stripeMode' => $mode]);
        }

        foreach ([[$oneOff, 'o.dateCompleted'], [$recurring, 'p.paidAt']] as [$query, $column]) {
            if ($from !== null) {
                $query->andWhere(['>=', $column, Db::prepareDateForDb($from)]);
            }

            if ($to !== null) {
                $query->andWhere(['<', $column, Db::prepareDateForDb($to)]);
            }
        }

        return (int) $oneOff->scalar() + (int) $recurring->scalar();
    }

    // State changes
    // =========================================================================

    /**
     * Marks an order paid and records the payment. Safe to call any number of times, from the
     * return trip and from webhooks at once: the first caller does the work and the rest see a
     * completed order. Returns whether this call completed it.
     *
     * @throws Exception when the order cannot be locked
     */
    public function completeOrder(Order $order, ?StripeObject $session = null): bool
    {
        $mutex = Craft::$app->getMutex();
        $lock = 'smallchange:order:' . $order->id;

        if (!$mutex->acquire($lock, 15)) {
            throw new Exception('Could not lock order ' . $order->number . ' to complete it.');
        }

        try {
            return $this->completeLocked((int) $order->id, $session);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Works out an order's paid / refunded / disputed state from its payments. Called whenever a refund or
     * dispute changes, so the order never disagrees with the payments beneath it.
     */
    public function syncPaymentStatus(Order $order): void
    {
        if ($order->state !== Order::STATE_COMPLETED) {
            return;
        }

        $paid = 0;
        $refunded = 0;
        $disputed = false;

        foreach (Plugin::getInstance()->payments->getPaymentsByOrderId((int) $order->id) as $payment) {
            if ($payment->status !== Payment::STATUS_SUCCEEDED) {
                continue;
            }

            $paid += $payment->amount;
            $refunded += $payment->amountRefunded;
            $disputed = $disputed || in_array($payment->disputeStatus, Payments::OPEN_DISPUTE_STATUSES, true);
        }

        $order->totalPaid = $paid;
        $order->totalRefunded = $refunded;
        $order->paymentStatus = match (true) {
            $disputed => Order::PAYMENT_DISPUTED,
            $paid > 0 && $refunded >= $paid => Order::PAYMENT_REFUNDED,
            $refunded > 0 => Order::PAYMENT_PARTIALLY_REFUNDED,
            default => Order::PAYMENT_PAID,
        };

        $this->touchAndSave($order);
    }

    /**
     * Cancels an order that was started but never paid, giving back the discount use and ending the
     * Stripe session so it can't be paid afterwards.
     */
    public function cancelOrder(Order $order): void
    {
        if (!in_array($order->state, [Order::STATE_CART, Order::STATE_PENDING], true)) {
            return;
        }

        $this->cancelStartedCheckout($order);
        $order->state = Order::STATE_CANCELLED;
        $order->paymentStatus = Order::PAYMENT_UNPAID;
        $this->touchAndSave($order);
    }

    public function markProcessing(Order $order): void
    {
        if ($order->state === Order::STATE_COMPLETED || $order->paymentStatus === Order::PAYMENT_PROCESSING) {
            return;
        }

        $order->state = Order::STATE_PENDING;
        $order->paymentStatus = Order::PAYMENT_PROCESSING;
        $this->touchAndSave($order);
    }

    public function markFailed(Order $order): void
    {
        if ($order->state === Order::STATE_COMPLETED) {
            return;
        }

        $order->paymentStatus = Order::PAYMENT_FAILED;
        $this->touchAndSave($order);
    }

    /**
     * A Checkout Session ran out without payment: the order goes back to being a cart.
     */
    public function releaseExpired(Order $order, string $sessionId): void
    {
        if ($order->state !== Order::STATE_PENDING || $order->stripeCheckoutSessionId !== $sessionId) {
            return;
        }

        $this->releaseCheckoutResources($order);

        // A shop basket can be paid for later; a recurring donation was a one-off form, so it simply ends.
        $order->state = $order->getIsRecurring() ? Order::STATE_CANCELLED : Order::STATE_CART;
        $order->paymentStatus = Order::PAYMENT_UNPAID;
        $order->stripeCheckoutSessionId = null;
        $order->checkoutAttempt++;
        $this->touchAndSave($order);
    }

    /**
     * Gives back what a started checkout was holding: the discount use and the Stripe coupon.
     */
    public function releaseCheckoutResources(Order $order): void
    {
        $plugin = Plugin::getInstance();
        $plugin->discounts->release($order);

        if ($couponId = $order->getStripeCouponId()) {
            $plugin->stripe->deleteCoupon($couponId);
        }
    }

    /**
     * Pulls an order back from a started payment because it is about to change: the old Checkout Session
     * can no longer be paid, the held discount use is returned, and the order is a cart again.
     */
    public function cancelStartedCheckout(Order $order): void
    {
        if ($order->state !== Order::STATE_PENDING) {
            return;
        }

        if ($order->stripeCheckoutSessionId) {
            Plugin::getInstance()->stripe->expireCheckoutSession($order->stripeCheckoutSessionId);
        }

        $this->releaseCheckoutResources($order);
        $order->state = Order::STATE_CART;
        $order->paymentStatus = Order::PAYMENT_UNPAID;
        $order->stripeCheckoutSessionId = null;
        // The next start is a new attempt, so it can't be answered with the session just expired.
        $order->checkoutAttempt++;
    }

    private function completeLocked(int $orderId, ?StripeObject $session): bool
    {
        $plugin = Plugin::getInstance();
        $stripe = $plugin->stripe;

        // Reload under the lock: another request may have finished while this one waited.
        $order = Order::find()->id($orderId)->one();

        if (!$order || $order->state === Order::STATE_COMPLETED || $order->state === Order::STATE_CANCELLED) {
            return false;
        }

        if ($session === null) {
            if (!$order->stripeCheckoutSessionId) {
                return false;
            }

            $session = $stripe->retrieveCheckoutSession($order->stripeCheckoutSessionId);
        }

        $mode = $stripe->mode();

        if ($mode === null || ($session->livemode === true) !== ($mode === Stripe::MODE_LIVE)) {
            Craft::warning('Not completing order ' . $order->number . ': the session is from a different Stripe mode.', __METHOD__);

            return false;
        }

        if ($session->payment_status !== 'paid') {
            if ($session->status === 'complete') {
                $this->markProcessing($order);
            }

            return false;
        }

        $invoiceId = null;
        $stripeSub = null;

        if (($session->mode ?? 'payment') === 'subscription') {
            // A recurring donation has no PaymentIntent of its own on the session: the first one belongs to the first invoice.
            $subRef = $session->subscription ?? null;
            $subId = $subRef instanceof StripeObject ? $subRef->id : $subRef;

            if (!is_string($subId) || $subId === '') {
                return false;
            }

            $stripeSub = $stripe->retrieveSubscription($subId, ['latest_invoice.payments']);
            $invoice = $stripeSub->latest_invoice ?? null;
            $invoiceId = $invoice instanceof StripeObject ? $invoice->id : $invoice;
            $intentId = $invoice instanceof StripeObject ? $plugin->payments->intentIdFromInvoice($invoice) : null;
        } else {
            $intentRef = $session->payment_intent ?? null;
            $intentId = $intentRef instanceof StripeObject ? $intentRef->id : $intentRef;
        }

        if (!is_string($intentId) || $intentId === '') {
            return false;
        }

        $intent = $stripe->retrievePaymentIntent($intentId, ['latest_charge']);

        $event = new OrderEvent([
            'order' => $order,
            'giftAidDeclared' => $order->giftAidDeclared,
            'giftAidEligibleAmount' => $order->giftAidDeclared ? $order->giftAidEligibleAmount : 0,
            'giftAidDeclarationText' => $order->giftAidDeclared !== null ? $plugin->giftAid->declarationText() : null,
            'billingAddress' => $order->getBillingAddress(),
        ]);
        $this->trigger(self::EVENT_BEFORE_COMPLETE_ORDER, $event);

        if (!$event->isValid) {
            return false;
        }

        $subscription = $stripeSub ? $plugin->subscriptions->syncFromStripe($stripeSub, null, $order) : null;
        $payment = $plugin->payments->recordPaymentIntent($order, $intent, $mode, null, is_string($invoiceId) ? $invoiceId : null, $subscription?->id);

        if ($subscription) {
            $order->subscriptionId = $subscription->id;
        }

        if ($payment->amount !== $order->total) {
            Craft::warning("Order {$order->number} total is {$order->total} but Stripe took {$payment->amount}.", __METHOD__);
        }

        $stripeCustomerId = $session->customer ?? null;
        $stripeCustomerId = $stripeCustomerId instanceof StripeObject ? $stripeCustomerId->id : $stripeCustomerId;

        if (is_string($stripeCustomerId) && $stripeCustomerId !== '' && $order->email) {
            $customer = $plugin->customers->findOrCreate($order->email, $mode);
            $plugin->customers->setStripeCustomerId($customer, $stripeCustomerId);
            $plugin->customers->syncToStripe($customer, $order->getBillingAddress());
            $order->customerId = $customer->id;
        }

        $now = new DateTime();
        $order->state = Order::STATE_COMPLETED;
        $order->paymentStatus = Order::PAYMENT_PAID;
        $order->dateCompleted = $now;
        $order->totalPaid = $payment->amount;
        $order->stripePaymentIntentId = $intentId;
        $this->touchAndSave($order);

        // The held use of the code is now a real one, and the coupon has done its job.
        $plugin->discounts->consume($order);

        if ($couponId = $order->getStripeCouponId()) {
            $plugin->stripe->deleteCoupon($couponId);
        }

        $this->trigger(self::EVENT_AFTER_COMPLETE_ORDER, new OrderEvent([
            'order' => $order,
            'giftAidDeclared' => $order->giftAidDeclared,
            'giftAidEligibleAmount' => $order->giftAidDeclared ? $order->giftAidEligibleAmount : 0,
            'giftAidDeclarationText' => $order->giftAidDeclared !== null ? $plugin->giftAid->declarationText() : null,
            'billingAddress' => $order->getBillingAddress(),
        ]));

        $plugin->emails->queue(Emails::KEY_RECEIPT, $order, ['paymentId' => $payment->id]);
        $plugin->emails->queue(Emails::KEY_ADMIN_NEW_ORDER, $order);

        return true;
    }

    private function touchAndSave(Order $order): void
    {
        $order->dateLastActivity = new DateTime();

        if (!Craft::$app->getElements()->saveElement($order, false, false)) {
            throw new Exception('Could not save order ' . $order->number . ': ' . implode(' ', $order->getFirstErrors()));
        }
    }

    private function settings(): Settings
    {
        /** @var Settings */
        return Plugin::getInstance()->getSettings();
    }
}
