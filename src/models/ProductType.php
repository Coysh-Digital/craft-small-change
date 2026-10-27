<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\records\ProductTypeRecord;
use Craft;
use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\db\Table as CraftTable;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use yii\base\InvalidConfigException;

/**
 * @mixin FieldLayoutBehavior
 */
class ProductType extends Model
{
    public const KIND_PHYSICAL = 'physical';
    public const KIND_NON_PHYSICAL = 'nonPhysical';
    public const KIND_DONATION = 'donation';

    /**
     * Donations take an amount from the donor; physical products are shipped; non-physical ones
     * (tickets, downloads sold elsewhere, memberships) are paid for but not delivered.
     */
    public const AVAILABLE_KINDS = [self::KIND_DONATION, self::KIND_PHYSICAL, self::KIND_NON_PHYSICAL];

    /**
     * @return array<string, string> kind => label
     */
    public static function kindLabels(): array
    {
        return [
            self::KIND_DONATION => Craft::t('small-change', 'Donation'),
            self::KIND_PHYSICAL => Craft::t('small-change', 'Physical product (delivered)'),
            self::KIND_NON_PHYSICAL => Craft::t('small-change', 'Non-physical product (no delivery)'),
        ];
    }

    public ?int $id = null;
    public ?string $uid = null;
    public ?string $name = null;
    public ?string $handle = null;
    public string $kind = self::KIND_DONATION;
    public bool $skuRequired = false;
    public int $sortOrder = 0;
    public ?int $fieldLayoutId = null;

    /**
     * @var ProductTypeSite[]|null
     */
    private ?array $siteSettings = null;

    public function __toString(): string
    {
        return (string) $this->handle;
    }

    /**
     * @return array<string, mixed>
     */
    public function behaviors(): array
    {
        return [
            'fieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Product::class,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'handle' => Craft::t('app', 'Handle'),
            'name' => Craft::t('app', 'Name'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['id', 'fieldLayoutId'], 'number', 'integerOnly' => true],
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['kind'], 'in', 'range' => self::AVAILABLE_KINDS],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']],
            [['handle'], UniqueValidator::class, 'targetClass' => ProductTypeRecord::class],
            [['siteSettings'], 'validateSiteSettings'],
        ];
    }

    public function validateSiteSettings(): void
    {
        foreach ($this->getSiteSettings() as $settings) {
            if (!$settings->validate()) {
                $this->addModelErrors($settings, 'siteSettings');
            }
        }
    }

    /**
     * @return ProductTypeSite[] keyed by site ID
     */
    public function getSiteSettings(): array
    {
        if ($this->siteSettings !== null) {
            return $this->siteSettings;
        }

        if (!$this->id) {
            return [];
        }

        // Late-loaded so a model built from a form doesn't touch the database.
        $this->siteSettings = Plugin::getInstance()->productTypes->getSiteSettingsByTypeId($this->id);

        return $this->siteSettings;
    }

    /**
     * @param ProductTypeSite[] $siteSettings
     */
    public function setSiteSettings(array $siteSettings): void
    {
        $this->siteSettings = ArrayHelper::index($siteSettings, 'siteId');

        foreach ($this->siteSettings as $settings) {
            $settings->productTypeId = $this->id;
        }
    }

    /**
     * The project config representation.
     *
     * @return array<string, mixed>
     * @throws InvalidConfigException
     */
    public function getConfig(): array
    {
        $config = [
            'name' => $this->name,
            'handle' => $this->handle,
            'kind' => $this->kind,
            'skuRequired' => $this->skuRequired,
            'sortOrder' => $this->sortOrder,
            'siteSettings' => [],
        ];

        $fieldLayout = $this->getFieldLayout();
        $fieldLayoutConfig = $fieldLayout->getConfig();

        if ($fieldLayoutConfig) {
            if (!$fieldLayout->uid) {
                $fieldLayout->uid = $this->fieldLayoutId
                    ? Db::uidById(CraftTable::FIELDLAYOUTS, $this->fieldLayoutId)
                    : StringHelper::UUID();
            }
            $config['fieldLayouts'] = [$fieldLayout->uid => $fieldLayoutConfig];
        }

        foreach ($this->getSiteSettings() as $siteId => $settings) {
            $siteUid = Db::uidById(CraftTable::SITES, $siteId);
            $config['siteSettings'][$siteUid] = [
                'hasUrls' => $settings->hasUrls,
                'uriFormat' => $settings->hasUrls ? $settings->uriFormat : null,
                'template' => $settings->hasUrls ? $settings->template : null,
            ];
        }

        return $config;
    }
}
