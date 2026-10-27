<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\elements;

use coyshdigital\smallchange\base\PurchasableInterface;
use coyshdigital\smallchange\elements\db\ProductQuery;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\models\LineItem;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\ProductRecord;
use Craft;
use craft\base\Element;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use yii\base\InvalidConfigException;

/**
 * Anything that can be bought or donated to. The product type decides which.
 *
 * @property-read ProductType $type
 */
class Product extends Element implements PurchasableInterface
{
    public ?int $typeId = null;
    public ?int $price = null;
    public ?string $sku = null;
    public ?int $minimumAmount = null;

    /**
     * @var int[]|null suggested donation amounts in minor units
     */
    public ?array $suggestedAmounts = null;
    public bool $allowCustomAmount = true;
    public bool $allowRecurring = false;

    /**
     * @var string[]|null
     */
    public ?array $recurringIntervals = null;

    public static function displayName(): string
    {
        return Craft::t('small-change', 'Product');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('small-change', 'product');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('small-change', 'Products');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('small-change', 'products');
    }

    public static function refHandle(): ?string
    {
        return 'product';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function find(): ProductQuery
    {
        return new ProductQuery(static::class);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function defineSources(string $context): array
    {
        // The element index itself doesn't check who may see a type, so the sources do. The picker behind a
        // Products field is a modal, and an editor filling in a page needs it without managing products.
        if ($context !== 'modal' && !Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_PRODUCTS)) {
            return [];
        }

        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('small-change', 'All products'),
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];

        $sources[] = ['heading' => Craft::t('small-change', 'Product types')];

        foreach (Plugin::getInstance()->productTypes->getAllProductTypes() as $type) {
            $sources[] = [
                'key' => 'type:' . $type->uid,
                'label' => Craft::t('site', $type->name),
                'data' => ['handle' => $type->handle],
                'criteria' => ['typeId' => $type->id],
                'defaultSort' => ['dateCreated', 'desc'],
            ];
        }

        return $sources;
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'type' => ['label' => Craft::t('app', 'Type')],
            'sku' => ['label' => Craft::t('small-change', 'SKU')],
            'price' => ['label' => Craft::t('small-change', 'Price')],
            'suggested' => ['label' => Craft::t('small-change', 'Suggested amounts')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    /**
     * @return string[]
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['type', 'price', 'suggested', 'dateCreated'];
    }

    /**
     * @return array<string, string>
     */
    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'dateCreated' => Craft::t('app', 'Date Created'),
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        $currency = Plugin::getInstance()->getSettings()->currency;

        return match ($attribute) {
            'type' => Html::encode($this->getType()->name),
            'price' => $this->price !== null ? Html::encode(Money::format($this->price, $currency)) : '',
            'suggested' => Html::encode(implode(', ', array_map(static fn(int $amount): string => Money::format($amount, $currency, true), $this->suggestedAmounts ?? []))),
            default => parent::attributeHtml($attribute),
        };
    }

    /**
     * @throws InvalidConfigException
     */
    public function getType(): ProductType
    {
        if ($this->typeId === null) {
            throw new InvalidConfigException('Product is missing its type ID');
        }

        $type = Plugin::getInstance()->productTypes->getProductTypeById($this->typeId);

        if (!$type) {
            throw new InvalidConfigException('Invalid product type ID: ' . $this->typeId);
        }

        return $type;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->typeId !== null ? $this->getType()->getFieldLayout() : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSupportedSites(): array
    {
        $sites = [];

        foreach (array_keys($this->getType()->getSiteSettings()) as $siteId) {
            $sites[] = [
                'siteId' => $siteId,
                'propagate' => true,
                'enabledByDefault' => true,
            ];
        }

        return $sites;
    }

    protected function uiLabel(): ?string
    {
        return (string) ($this->title ?: Craft::t('small-change', 'Untitled product'));
    }

    protected function cpEditUrl(): ?string
    {
        if (!$this->id) {
            return null;
        }

        $path = 'small-change/products/' . $this->getType()->handle . '/' . $this->id;

        if ($this->slug && !str_starts_with($this->slug, '__')) {
            $path .= '-' . $this->slug;
        }

        return UrlHelper::cpUrl($path);
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('small-change/products');
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function crumbs(): array
    {
        return [
            ['label' => Craft::t('small-change', 'Products'), 'url' => UrlHelper::cpUrl('small-change/products')],
            ['label' => Craft::t('site', $this->getType()->name), 'url' => UrlHelper::cpUrl('small-change/products', ['source' => 'type:' . $this->getType()->uid])],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function previewTargets(): array
    {
        return [];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    protected function route(): array|string|null
    {
        $settings = $this->getType()->getSiteSettings()[$this->siteId] ?? null;

        if (!$settings || !$settings->hasUrls || !$settings->template) {
            return null;
        }

        return [
            'templates/render',
            ['template' => $settings->template, 'variables' => ['product' => $this]],
        ];
    }

    protected function cacheTags(): array
    {
        return ['product:' . $this->typeId];
    }

    public function getUriFormat(): ?string
    {
        return $this->getType()->getSiteSettings()[$this->siteId]->uriFormat ?? null;
    }

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_PRODUCTS);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE_PRODUCTS);
    }

    public function canDuplicate(User $user): bool
    {
        return $this->canSave($user);
    }

    public function canDelete(User $user): bool
    {
        return $this->canSave($user);
    }

    public function canCreateDrafts(User $user): bool
    {
        return $this->canSave($user);
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    /**
     * The control panel posts amounts as decimal text ("10, 25.50"); the element holds minor units.
     *
     * @param array<string, mixed> $values
     */
    public function setAttributesFromRequest(array $values): void
    {
        $currency = Plugin::getInstance()->getSettings()->currency;

        if (array_key_exists('donationMinimum', $values)) {
            $minimum = trim((string) $values['donationMinimum']);
            $this->minimumAmount = $minimum === '' ? null : Money::parse($minimum, $currency);

            if ($minimum !== '' && $this->minimumAmount === null) {
                $this->addError('minimumAmount', Craft::t('small-change', 'Enter the minimum as an amount such as 5 or 2.50.'));
            }
        }

        if (array_key_exists('donationSuggested', $values)) {
            $amounts = [];

            foreach (array_filter(array_map('trim', explode(',', (string) $values['donationSuggested'])), static fn(string $part): bool => $part !== '') as $part) {
                $amount = Money::parse($part, $currency);

                if ($amount === null) {
                    $this->addError('suggestedAmounts', Craft::t('small-change', '"{value}" is not a valid amount.', ['value' => $part]));
                    continue;
                }

                $amounts[] = $amount;
            }

            $this->suggestedAmounts = array_values(array_unique($amounts));
            sort($this->suggestedAmounts);
        }

        if (array_key_exists('allowCustomAmount', $values)) {
            $this->allowCustomAmount = (bool) $values['allowCustomAmount'];
        }

        if (array_key_exists('allowRecurring', $values)) {
            $this->allowRecurring = (bool) $values['allowRecurring'];
        }

        if (array_key_exists('recurringIntervals', $values)) {
            $this->recurringIntervals = array_values(array_intersect(array_map('strval', (array) $values['recurringIntervals']), ['month', 'year'])) ?: null;
        }

        if (array_key_exists('priceInput', $values)) {
            $input = trim((string) $values['priceInput']);
            $this->price = $input === '' ? null : Money::parse($input, $currency);

            if ($input !== '' && $this->price === null) {
                $this->addError('price', Craft::t('small-change', 'Enter the price as an amount such as 12 or 12.50.'));
            }
        }

        unset($values['donationMinimum'], $values['donationSuggested'], $values['allowCustomAmount'], $values['allowRecurring'], $values['recurringIntervals'], $values['priceInput']);

        parent::setAttributesFromRequest($values);
    }

    /**
     * The intervals a donor may give at. Defaults to both when the product allows recurring gifts but names none.
     *
     * @return string[]
     */
    public function getAllowedIntervals(): array
    {
        $intervals = array_values(array_intersect($this->recurringIntervals ?? [], ['month', 'year']));

        return $intervals ?: ['month', 'year'];
    }

    /**
     * The smallest gift this product accepts: the configured minimum, or one whole currency unit.
     */
    public function getEffectiveMinimum(): int
    {
        return $this->minimumAmount ?? 10 ** Money::exponent(Plugin::getInstance()->getSettings()->currency);
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['typeId'], 'required'];
        $rules[] = [['minimumAmount'], 'integer', 'min' => 1, 'max' => Money::MAX_MINOR];
        $rules[] = [['suggestedAmounts'], 'validateSuggestedAmounts'];
        $rules[] = [['price'], 'validatePrice', 'skipOnEmpty' => false];
        $rules[] = [['sku'], 'string', 'max' => 100];
        $rules[] = [['sku'], 'validateSku', 'skipOnEmpty' => false];

        return $rules;
    }

    /**
     * Products sold at a fixed price must have one. Donations must not, because the donor sets it.
     */
    public function validatePrice(): void
    {
        if ($this->typeId === null || $this->getIsDonation()) {
            return;
        }

        if ($this->price === null && $this->getScenario() === self::SCENARIO_LIVE) {
            $this->addError('price', Craft::t('yii', '{attribute} cannot be blank.', ['attribute' => Craft::t('small-change', 'Price')]));
        }

        if ($this->price !== null && ($this->price < 1 || $this->price > Money::MAX_MINOR)) {
            $this->addError('price', Craft::t('small-change', 'The price must be between the smallest unit of {currency} and {max}.', [
                'currency' => Plugin::getInstance()->getSettings()->currency,
                'max' => Money::format(Money::MAX_MINOR, Plugin::getInstance()->getSettings()->currency),
            ]));
        }
    }

    /**
     * SKUs identify a product, so they are unique across all of them; types can also make them mandatory.
     */
    public function validateSku(): void
    {
        $sku = trim((string) $this->sku);

        if ($sku === '') {
            $this->sku = null;

            if ($this->typeId !== null && $this->getType()->skuRequired && $this->getScenario() === self::SCENARIO_LIVE) {
                $this->addError('sku', Craft::t('yii', '{attribute} cannot be blank.', ['attribute' => Craft::t('small-change', 'SKU')]));
            }

            return;
        }

        $this->sku = $sku;
        // Drafts and revisions of this product share its SKU; only other live products count.
        $ownId = $this->getCanonicalId() ?? $this->id;
        $taken = ProductRecord::find()
            ->alias('p')
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[p.id]]')
            ->where(['p.sku' => $sku, 'e.draftId' => null, 'e.revisionId' => null, 'e.dateDeleted' => null]);

        if ($ownId) {
            $taken->andWhere(['not', ['p.id' => $ownId]]);
        }

        if ($taken->exists()) {
            $this->addError('sku', Craft::t('yii', '{attribute} "{value}" has already been taken.', ['attribute' => Craft::t('small-change', 'SKU'), 'value' => $sku]));
        }
    }

    public function validateSuggestedAmounts(): void
    {
        $amounts = $this->suggestedAmounts ?? [];

        if (count($amounts) > 8) {
            $this->addError('suggestedAmounts', Craft::t('small-change', 'Offer no more than eight suggested amounts.'));
        }

        foreach ($amounts as $amount) {
            if ($amount < $this->getEffectiveMinimum()) {
                $this->addError('suggestedAmounts', Craft::t('small-change', 'Suggested amounts cannot be below the minimum.'));
                break;
            }
        }

        // Without suggestions or a free choice there would be nothing to give.
        if (!$this->allowCustomAmount && !$amounts && $this->getScenario() === self::SCENARIO_LIVE) {
            $this->addError('suggestedAmounts', Craft::t('small-change', 'Add suggested amounts, or let donors enter their own.'));
        }
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new ProductRecord() : ProductRecord::findOne($this->id);

            if (!$record) {
                throw new InvalidConfigException('Invalid product ID: ' . $this->id);
            }

            $record->id = (int) $this->id;
            $record->typeId = (int) $this->typeId;
            $record->price = $this->price;
            $record->sku = $this->sku ?: null;
            $record->minimumAmount = $this->minimumAmount;
            $record->suggestedAmounts = $this->suggestedAmounts;
            $record->allowCustomAmount = $this->allowCustomAmount;
            $record->allowRecurring = $this->allowRecurring;
            $record->recurringIntervals = $this->recurringIntervals;
            $record->save(false);
        }

        parent::afterSave($isNew);
    }

    public function getPurchasableId(): ?int
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return (string) $this->title;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function getLineItemKind(): string
    {
        return match ($this->getType()->kind) {
            ProductType::KIND_PHYSICAL => LineItem::KIND_PHYSICAL,
            ProductType::KIND_NON_PHYSICAL => LineItem::KIND_NON_PHYSICAL,
            default => LineItem::KIND_DONATION,
        };
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function getIsDonation(): bool
    {
        return $this->getType()->kind === ProductType::KIND_DONATION;
    }
}
