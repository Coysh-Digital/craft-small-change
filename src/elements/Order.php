<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\elements;

use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\elements\db\OrderQuery;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\models\Totals;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\LineItemRecord;
use coyshdigital\smallchange\records\OrderRecord;
use Craft;
use craft\base\Element;
use craft\elements\Address;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use DateTime;
use yii\base\InvalidConfigException;

/**
 * A cart, a payment in progress, a completed order, or a donation.
 * Not localised: it belongs to the site it was placed on.
 */
class Order extends Element
{
    public const STATE_CART = 'cart';
    public const STATE_PENDING = 'pending';
    public const STATE_COMPLETED = 'completed';
    public const STATE_CANCELLED = 'cancelled';

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PROCESSING = 'processing';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_PARTIALLY_REFUNDED = 'partiallyRefunded';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_DISPUTED = 'disputed';

    public const KIND_ONE_OFF = 'oneOff';
    public const KIND_RECURRING = 'recurring';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIALLY_REFUNDED = 'partiallyRefunded';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Unambiguous: no 0/O or 1/I/L. */
    private const NUMBER_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public ?string $number = null;
    /**
     * The site the customer used. The element itself always lives on the primary site (see getSupportedSites()).
     */
    public ?int $orderSiteId = null;
    public string $kind = self::KIND_ONE_OFF;
    public string $state = self::STATE_CART;
    public string $paymentStatus = self::PAYMENT_UNPAID;
    public ?string $email = null;
    public ?int $customerId = null;
    public ?int $userId = null;
    public string $currency = 'GBP';
    public int $itemSubtotal = 0;
    public int $discountAmount = 0;
    public int $shippingAmount = 0;
    public int $total = 0;
    public int $totalPaid = 0;
    public int $totalRefunded = 0;

    /**
     * Null means the option was never offered; true or false is the donor's answer.
     */
    public ?bool $giftAidDeclared = null;
    public int $giftAidEligibleAmount = 0;
    public ?int $discountId = null;
    public ?string $discountCode = null;
    public ?int $shippingMethodId = null;
    public ?string $shippingMethodName = null;
    public ?int $shippingAddressId = null;
    public ?int $billingAddressId = null;
    public ?int $subscriptionId = null;
    public ?string $stripeMode = null;
    public ?string $stripeCheckoutSessionId = null;
    public ?string $stripePaymentIntentId = null;
    /**
     * How many times a started payment has been pulled back (the basket changed, the session ran out). Every
     * Stripe idempotency key includes it, so a fresh attempt is never answered with the session just expired.
     */
    public int $checkoutAttempt = 0;
    public ?string $returnUrl = null;
    public ?string $customerNote = null;
    public ?string $adminNote = null;
    public ?string $lastIp = null;
    public ?DateTime $dateCompleted = null;
    public ?DateTime $dateFulfilled = null;
    public ?DateTime $dateAnonymised = null;
    public ?DateTime $dateLastActivity = null;

    /**
     * @var LineItem[]|null
     */
    private ?array $lineItems = null;
    private bool $lineItemsDirty = false;
    private ?Address $billingAddress = null;
    private bool $billingAddressLoaded = false;
    private ?Address $shippingAddress = null;
    private bool $shippingAddressLoaded = false;
    private ?User $user = null;

    public static function displayName(): string
    {
        return Craft::t('small-change', 'Order');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('small-change', 'order');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('small-change', 'Orders');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('small-change', 'orders');
    }

    public static function refHandle(): ?string
    {
        return 'order';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => ['label' => Craft::t('small-change', 'Awaiting payment'), 'color' => 'orange'],
            self::STATUS_COMPLETED => ['label' => Craft::t('small-change', 'Completed'), 'color' => 'green'],
            self::STATUS_PARTIALLY_REFUNDED => ['label' => Craft::t('small-change', 'Partially refunded'), 'color' => 'yellow'],
            self::STATUS_REFUNDED => ['label' => Craft::t('small-change', 'Refunded'), 'color' => 'blue'],
            self::STATUS_DISPUTED => ['label' => Craft::t('small-change', 'Disputed'), 'color' => 'red'],
            self::STATUS_CANCELLED => ['label' => Craft::t('small-change', 'Cancelled'), 'color' => 'grey'],
        ];
    }

    public static function find(): OrderQuery
    {
        return new OrderQuery(static::class);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function defineSources(string $context): array
    {
        // Craft's element index serves any source it is given to anyone who can reach the control panel; the
        // permission check has to live here, where the sources are declared.
        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW_ORDERS)) {
            return [];
        }

        $notCart = [self::STATE_PENDING, self::STATE_COMPLETED, self::STATE_CANCELLED];

        return [
            [
                'key' => '*',
                'label' => Craft::t('small-change', 'All orders'),
                'criteria' => ['state' => $notCart],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            [
                'key' => 'needsDispatch',
                'label' => Craft::t('small-change', 'Needs dispatch'),
                'criteria' => ['needsDispatch' => true],
                'defaultSort' => ['dateCompleted', 'asc'],
            ],
            [
                'key' => 'donations',
                'label' => Craft::t('small-change', 'Donations'),
                'criteria' => ['state' => $notCart, 'hasDonation' => true],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            [
                'key' => 'giftAid',
                'label' => Craft::t('small-change', 'Gift Aid declared'),
                'criteria' => ['state' => $notCart, 'giftAidDeclared' => true],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            [
                'key' => 'test',
                'label' => Craft::t('small-change', 'Test orders'),
                'criteria' => ['state' => $notCart, 'stripeMode' => 'test'],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];
    }

    /**
     * Orders hold donors' details and leave through the plugin's own screens, never a bulk export.
     *
     * @return array<int, mixed>
     */
    protected static function defineExporters(string $source): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('small-change', 'Number')],
            'email' => ['label' => Craft::t('small-change', 'Email')],
            'user' => ['label' => Craft::t('small-change', 'User')],
            'total' => ['label' => Craft::t('small-change', 'Total')],
            'orderStatus' => ['label' => Craft::t('app', 'Status')],
            'stripeMode' => ['label' => Craft::t('small-change', 'Mode')],
            'giftAid' => ['label' => Craft::t('small-change', 'Gift Aid')],
            'dateFulfilled' => ['label' => Craft::t('small-change', 'Dispatched')],
            'dateCompleted' => ['label' => Craft::t('small-change', 'Completed')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    /**
     * @return string[]
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['email', 'total', 'orderStatus', 'stripeMode', 'giftAid', 'dateCompleted'];
    }

    /**
     * @return array<string, string>
     */
    protected static function defineSortOptions(): array
    {
        return [
            'number' => Craft::t('small-change', 'Number'),
            'total' => Craft::t('small-change', 'Total'),
            'dateCompleted' => Craft::t('small-change', 'Completed'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function defineSearchableAttributes(): array
    {
        return ['number', 'email'];
    }

    /**
     * The donor's account is loaded for the whole index page at once, not one query per row.
     *
     * @param static[] $sourceElements
     * @return array<string, mixed>|null|false
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        if ($handle !== 'user') {
            return parent::eagerLoadingMap($sourceElements, $handle);
        }

        $map = [];

        foreach ($sourceElements as $order) {
            if ($order->userId !== null) {
                $map[] = ['source' => $order->id, 'target' => $order->userId];
            }
        }

        return ['elementType' => User::class, 'map' => $map, 'criteria' => ['status' => null]];
    }

    protected static function prepElementQueryForTableAttribute(ElementQueryInterface $elementQuery, string $attribute): void
    {
        if ($attribute === 'user') {
            $elementQuery->andWith('user');

            return;
        }

        parent::prepElementQueryForTableAttribute($elementQuery, $attribute);
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'user' => ($user = $this->getUser()) ? Cp::elementChipHtml($user, ['showStatus' => false]) : '',
            'total' => Html::encode(Money::format($this->total, $this->currency)),
            'orderStatus' => $this->getStatus() ? Html::tag('span', '', ['class' => ['status', $this->getStatus()]]) . ' ' . Html::encode(self::statuses()[$this->getStatus()]['label'] ?? '') : '',
            // Only test orders are marked, so a live site's index stays quiet and a stray test order stands out.
            'stripeMode' => $this->stripeMode === 'test' ? Html::tag('span', Craft::t('small-change', 'Test'), ['class' => 'light']) : '',
            'giftAid' => match ($this->giftAidDeclared) {
                true => Craft::t('app', 'Yes'),
                false => Craft::t('app', 'No'),
                default => '',
            },
            default => parent::attributeHtml($attribute),
        };
    }

    public function getStatus(): ?string
    {
        if ($this->state === self::STATE_CANCELLED) {
            return self::STATUS_CANCELLED;
        }

        if ($this->paymentStatus === self::PAYMENT_DISPUTED) {
            return self::STATUS_DISPUTED;
        }

        if ($this->paymentStatus === self::PAYMENT_REFUNDED) {
            return self::STATUS_REFUNDED;
        }

        if ($this->paymentStatus === self::PAYMENT_PARTIALLY_REFUNDED) {
            return self::STATUS_PARTIALLY_REFUNDED;
        }

        if ($this->state === self::STATE_COMPLETED) {
            return self::STATUS_COMPLETED;
        }

        // A cart has no status of its own: it is not an order yet.
        return $this->state === self::STATE_PENDING ? self::STATUS_PENDING : null;
    }

    /**
     * Orders are not localised, and Craft only ever looks for those on the primary site, so that is the one site
     * an order is saved to. Which site the customer used is kept separately, in `orderSiteId`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSupportedSites(): array
    {
        $sites = Craft::$app->getSites();
        $this->siteId = $sites->getPrimarySite()->id;
        $this->orderSiteId ??= $sites->getCurrentSite()->id;

        // Propagating to the one site it supports is what makes Craft accept the save; there is nowhere else to copy it.
        return [['siteId' => $this->siteId, 'propagate' => true, 'enabledByDefault' => true]];
    }

    protected function uiLabel(): ?string
    {
        return $this->number;
    }

    protected function cpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('small-change/orders/' . $this->id) : null;
    }

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW_ORDERS);
    }

    /**
     * Never through Craft's generic element save: it would accept any attribute with a validation rule, money and
     * payment state included. Everything staff may change goes through the plugin's own order actions.
     */
    public function canSave(User $user): bool
    {
        return false;
    }

    public function canDelete(User $user): bool
    {
        return false;
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    /**
     * The layout the order was given when it was created: the donation fields for a donation form,
     * the checkout fields for a basket. An order made before its layout existed has no fields, and
     * changing one set never reshapes orders already placed.
     *
     * @see \coyshdigital\smallchange\services\Orders::useFieldLayout()
     */
    public function getFieldLayout(): ?FieldLayout
    {
        $layout = $this->fieldLayoutId ? Craft::$app->getFields()->getLayoutById($this->fieldLayoutId) : null;

        return $layout ?? new FieldLayout(['type' => self::class]);
    }

    /**
     * The Craft user this order belongs to, when it was placed while signed in.
     */
    public function getUser(): ?User
    {
        if ($this->userId === null) {
            return null;
        }

        if ($this->user?->id !== $this->userId) {
            $eagerLoaded = $this->getEagerLoadedElements('user');
            /** @var User|null $user */
            $user = $eagerLoaded !== null ? $eagerLoaded->first() : Craft::$app->getUsers()->getUserById($this->userId);
            $this->user = $user;
        }

        return $this->user;
    }

    public function getIsCompleted(): bool
    {
        return $this->state === self::STATE_COMPLETED;
    }

    public function getIsPaid(): bool
    {
        return in_array($this->paymentStatus, [self::PAYMENT_PAID, self::PAYMENT_PARTIALLY_REFUNDED, self::PAYMENT_REFUNDED], true);
    }

    /**
     * Whether anything in the order has to be delivered, which is what asks for an address and a method.
     */
    public function getRequiresShipping(): bool
    {
        foreach ($this->getLineItems() as $item) {
            if ($item->getIsPhysical()) {
                return true;
            }
        }

        return false;
    }

    public function getShippingCountryCode(): ?string
    {
        $country = $this->getShippingAddress()?->countryCode;

        return $country ? strtoupper($country) : null;
    }

    /**
     * The id of the one-use Stripe coupon that carries this order's discount into Checkout.
     */
    public function getStripeCouponId(): ?string
    {
        // The attempt is part of the id: once a coupon has been deleted, Stripe's idempotency window would answer a
        // second create under the same id with the old response, so each attempt uses an id of its own.
        return $this->discountAmount > 0 && $this->number ? 'sc_' . $this->number . '_' . $this->checkoutAttempt . '_' . $this->discountAmount : null;
    }

    public function getIsRecurring(): bool
    {
        return $this->kind === self::KIND_RECURRING;
    }

    /**
     * Whether any line is a donation, which is what makes the order eligible for Gift Aid.
     */
    public function getHasDonation(): bool
    {
        foreach ($this->getLineItems() as $item) {
            if ($item->getIsDonation()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return LineItem[]
     */
    public function getLineItems(): array
    {
        if ($this->lineItems === null) {
            $this->lineItems = [];

            if ($this->id) {
                /** @var LineItemRecord[] $records */
                $records = LineItemRecord::find()->where(['orderId' => $this->id])->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])->all();

                foreach ($records as $record) {
                    $this->lineItems[] = new LineItem($record->getAttributes(['id', 'orderId', 'purchasableId', 'kind', 'description', 'sku', 'unitPrice', 'qty', 'subtotal', 'recurringInterval', 'isGiftAidEligible', 'snapshot', 'sortOrder']));
                }
            }
        }

        return $this->lineItems;
    }

    /**
     * @param LineItem[] $lineItems
     */
    public function setLineItems(array $lineItems): void
    {
        $sortOrder = 0;

        foreach ($lineItems as $item) {
            $item->recalculate();
            $item->sortOrder = $sortOrder++;
        }

        $this->lineItems = array_values($lineItems);
        $this->lineItemsDirty = true;
    }

    public function setTotals(Totals $totals): void
    {
        $this->itemSubtotal = $totals->itemSubtotal;
        $this->discountAmount = $totals->discountAmount;
        $this->shippingAmount = $totals->shippingAmount;
        $this->total = $totals->total;
    }

    public function getBillingAddress(): ?Address
    {
        if (!$this->billingAddressLoaded) {
            $this->billingAddress = $this->billingAddressId ? Address::find()->id($this->billingAddressId)->one() : null;
            $this->billingAddressLoaded = true;
        }

        return $this->billingAddress;
    }

    public function setBillingAddress(?Address $address): void
    {
        $this->billingAddress = $address;
        $this->billingAddressLoaded = true;
    }

    public function getShippingAddress(): ?Address
    {
        if (!$this->shippingAddressLoaded) {
            $this->shippingAddress = $this->shippingAddressId ? Address::find()->id($this->shippingAddressId)->one() : null;
            $this->shippingAddressLoaded = true;
        }

        return $this->shippingAddress;
    }

    public function setShippingAddress(?Address $address): void
    {
        $this->shippingAddress = $address;
        $this->shippingAddressLoaded = true;
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['email'], 'trim'];
        $rules[] = [['email'], 'email', 'skipOnEmpty' => true];
        $rules[] = [['email'], 'string', 'max' => 255];
        $rules[] = [['currency'], 'required'];
        $rules[] = [['kind'], 'in', 'range' => [self::KIND_ONE_OFF, self::KIND_RECURRING]];
        $rules[] = [['state'], 'in', 'range' => [self::STATE_CART, self::STATE_PENDING, self::STATE_COMPLETED, self::STATE_CANCELLED]];
        $rules[] = [['paymentStatus'], 'in', 'range' => [self::PAYMENT_UNPAID, self::PAYMENT_PROCESSING, self::PAYMENT_PAID, self::PAYMENT_PARTIALLY_REFUNDED, self::PAYMENT_REFUNDED, self::PAYMENT_FAILED, self::PAYMENT_DISPUTED]];
        $rules[] = [['itemSubtotal', 'discountAmount', 'shippingAmount', 'total', 'totalPaid', 'totalRefunded', 'giftAidEligibleAmount'], 'integer', 'min' => 0, 'max' => 9_999_999_999];

        return $rules;
    }

    public function beforeValidate(): bool
    {
        $this->number ??= self::generateNumber();

        return parent::beforeValidate();
    }

    public function beforeSave(bool $isNew): bool
    {
        $this->number ??= self::generateNumber();
        $this->title = $this->number;
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $this->orderSiteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        return parent::beforeSave($isNew);
    }

    public function beforeDelete(): bool
    {
        // The addresses are this order's own and are separate elements, so they go with it rather than lingering unowned.
        if ($this->hardDelete) {
            foreach ([$this->billingAddressId, $this->shippingAddressId] as $addressId) {
                if ($addressId) {
                    Craft::$app->getElements()->deleteElementById((int) $addressId, Address::class, null, true);
                }
            }
        }

        return parent::beforeDelete();
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new OrderRecord() : OrderRecord::findOne($this->id);

            if (!$record) {
                throw new InvalidConfigException('Invalid order ID: ' . $this->id);
            }

            $record->id = (int) $this->id;
            $record->orderSiteId = $this->orderSiteId;

            foreach ([
                'number', 'kind', 'state', 'paymentStatus', 'email', 'customerId', 'userId', 'currency', 'itemSubtotal',
                'discountAmount', 'shippingAmount', 'total', 'totalPaid', 'totalRefunded', 'giftAidDeclared',
                'giftAidEligibleAmount', 'discountId', 'discountCode', 'shippingMethodId', 'shippingMethodName',
                'subscriptionId', 'stripeMode', 'stripeCheckoutSessionId', 'stripePaymentIntentId', 'checkoutAttempt',
                'returnUrl', 'customerNote', 'adminNote', 'lastIp',
            ] as $attribute) {
                $record->$attribute = $this->$attribute;
            }

            foreach (['dateCompleted', 'dateFulfilled', 'dateAnonymised', 'dateLastActivity'] as $attribute) {
                $record->$attribute = $this->$attribute ? Db::prepareDateForDb($this->$attribute) : null;
            }

            $this->saveAddress($this->billingAddress, $this->billingAddressLoaded, 'billingAddressId');
            $this->saveAddress($this->shippingAddress, $this->shippingAddressLoaded, 'shippingAddressId');
            $record->billingAddressId = $this->billingAddressId;
            $record->shippingAddressId = $this->shippingAddressId;

            $record->save(false);

            if ($this->lineItemsDirty) {
                $this->persistLineItems($isNew);
            }
        }

        parent::afterSave($isNew);
    }

    /**
     * 10 characters from an alphabet without look-alikes, so a number read over the phone survives.
     */
    public static function generateNumber(): string
    {
        $max = strlen(self::NUMBER_ALPHABET) - 1;
        $number = '';

        for ($i = 0; $i < 10; $i++) {
            $number .= self::NUMBER_ALPHABET[random_int(0, $max)];
        }

        return $number;
    }

    private function saveAddress(?Address $address, bool $loaded, string $idAttribute): void
    {
        if (!$loaded) {
            return;
        }

        if ($address === null) {
            // Not just unlinked: an address nobody is entitled to keep is deleted.
            if ($this->$idAttribute) {
                Craft::$app->getElements()->deleteElementById((int) $this->$idAttribute, Address::class, null, true);
            }

            $this->$idAttribute = null;

            return;
        }

        $address->setOwner($this);
        $address->setPrimaryOwner($this);

        if (!Craft::$app->getElements()->saveElement($address, false, false)) {
            throw new InvalidConfigException('Could not save the order address: ' . implode(' ', $address->getFirstErrors()));
        }

        $this->$idAttribute = $address->id;
    }

    /**
     * Writes the lines without ever deleting by order: existing lines are updated in place, new ones
     * inserted and only lines that are gone are removed, by primary key. A `DELETE ... WHERE orderId`
     * that matches nothing still takes a gap lock, and two carts created at once then deadlock.
     */
    private function persistLineItems(bool $isNew): void
    {
        /** @var array<int, LineItemRecord> $existing */
        $existing = $isNew ? [] : LineItemRecord::find()->where(['orderId' => $this->id])->indexBy('id')->all();
        $kept = [];

        foreach ($this->lineItems ?? [] as $item) {
            $record = ($item->id !== null && isset($existing[$item->id])) ? $existing[$item->id] : new LineItemRecord();
            $record->orderId = (int) $this->id;
            $record->purchasableId = $item->purchasableId;
            $record->kind = $item->kind;
            $record->description = $item->description;
            $record->sku = $item->sku;
            $record->unitPrice = $item->unitPrice;
            $record->qty = $item->qty;
            $record->subtotal = $item->subtotal;
            $record->recurringInterval = $item->recurringInterval;
            $record->isGiftAidEligible = $item->isGiftAidEligible;
            $record->snapshot = $item->snapshot;
            $record->sortOrder = $item->sortOrder;
            $record->save(false);
            $item->id = $record->id;
            $item->orderId = (int) $this->id;
            $kept[] = $record->id;
        }

        $gone = array_diff(array_keys($existing), $kept);

        if ($gone) {
            Db::delete(Table::LINE_ITEMS, ['id' => array_values($gone)]);
        }

        $this->lineItemsDirty = false;
    }
}
