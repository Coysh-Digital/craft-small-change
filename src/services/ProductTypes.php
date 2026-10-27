<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\fieldlayoutelements\DonationSettingsField;
use coyshdigital\smallchange\fieldlayoutelements\ProductPriceField;
use coyshdigital\smallchange\models\ProductType;
use coyshdigital\smallchange\models\ProductTypeSite;
use coyshdigital\smallchange\records\ProductTypeRecord;
use coyshdigital\smallchange\records\ProductTypeSiteRecord;
use Craft;
use craft\base\Component;
use craft\db\Table as CraftTable;
use craft\events\ConfigEvent;
use craft\events\RebuildConfigEvent;
use craft\fieldlayoutelements\TitleField;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\queue\jobs\ResaveElements;
use Throwable;

/**
 * Product types live in project config under smallChange.productTypes.{uid};
 * the database rows are the applied copy that elements join against.
 */
class ProductTypes extends Component
{
    public const CONFIG_PATH = 'smallChange.productTypes';

    /**
     * @var ProductType[]|null keyed by ID
     */
    private ?array $types = null;

    /**
     * @return ProductType[] sorted by name
     */
    public function getAllProductTypes(): array
    {
        if ($this->types === null) {
            $this->types = [];

            /** @var ProductTypeRecord[] $records */
            $records = ProductTypeRecord::find()->orderBy(['name' => SORT_ASC])->all();

            foreach ($records as $record) {
                $this->types[$record->id] = new ProductType($record->getAttributes(['id', 'uid', 'name', 'handle', 'kind', 'skuRequired', 'sortOrder', 'fieldLayoutId']));
            }
        }

        return array_values($this->types);
    }

    public function getProductTypeById(int $id): ?ProductType
    {
        $this->getAllProductTypes();

        return $this->types[$id] ?? null;
    }

    public function getProductTypeByHandle(string $handle): ?ProductType
    {
        foreach ($this->getAllProductTypes() as $type) {
            if ($type->handle === $handle) {
                return $type;
            }
        }

        return null;
    }

    public function getProductTypeByUid(string $uid): ?ProductType
    {
        foreach ($this->getAllProductTypes() as $type) {
            if ($type->uid === $uid) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return ProductTypeSite[] keyed by site ID
     */
    public function getSiteSettingsByTypeId(int $typeId): array
    {
        /** @var ProductTypeSiteRecord[] $records */
        $records = ProductTypeSiteRecord::find()->where(['productTypeId' => $typeId])->all();
        $settings = [];

        foreach ($records as $record) {
            $settings[$record->siteId] = new ProductTypeSite($record->getAttributes(['id', 'productTypeId', 'siteId', 'hasUrls', 'uriFormat', 'template']));
        }

        return $settings;
    }

    public function saveProductType(ProductType $type, bool $runValidation = true): bool
    {
        $isNew = !$type->id;

        $this->ensureFieldLayout($type);

        if ($runValidation && !$type->validate()) {
            Craft::info('Product type not saved due to validation error.', __METHOD__);
            return false;
        }

        if ($isNew) {
            $type->uid = StringHelper::UUID();
            $type->sortOrder = $type->sortOrder ?: count($this->getAllProductTypes()) + 1;
        } elseif (!$type->uid) {
            $type->uid = Db::uidById(ProductTypeRecord::tableName(), $type->id);
        }

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            if (!isset($type->getSiteSettings()[$siteId])) {
                throw new \yii\base\Exception('Tried to save a product type that is missing site settings');
            }
        }

        Craft::$app->getProjectConfig()->set(self::CONFIG_PATH . '.' . $type->uid, $type->getConfig(), "Save product type '{$type->handle}'");

        if ($isNew) {
            $type->id = Db::idByUid(ProductTypeRecord::tableName(), $type->uid);
        }

        $this->types = null;

        return true;
    }

    public function deleteProductType(ProductType $type): bool
    {
        Craft::$app->getProjectConfig()->remove(self::CONFIG_PATH . '.' . $type->uid, "Delete product type '{$type->handle}'");

        return true;
    }

    public function handleChangedProductType(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record = ProductTypeRecord::findOne(['uid' => $uid]) ?? new ProductTypeRecord();
            $isNew = $record->getIsNewRecord();

            $record->uid = $uid;
            $record->name = $data['name'];
            $record->handle = $data['handle'];
            $record->kind = $data['kind'] ?? ProductType::KIND_DONATION;
            $record->skuRequired = (bool) ($data['skuRequired'] ?? false);
            $record->sortOrder = (int) ($data['sortOrder'] ?? 0);

            if (!empty($data['fieldLayouts'])) {
                $layout = FieldLayout::createFromConfig(reset($data['fieldLayouts']));
                $layout->id = $record->fieldLayoutId;
                $layout->type = Product::class;
                $layout->uid = key($data['fieldLayouts']);
                Craft::$app->getFields()->saveLayout($layout, false);
                $record->fieldLayoutId = $layout->id;
            } elseif ($record->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($record->fieldLayoutId);
                $record->fieldLayoutId = null;
            }

            $record->save(false);

            $siteData = $data['siteSettings'] ?? [];
            $siteIdMap = Db::idsByUids(CraftTable::SITES, array_keys($siteData));
            $existing = $isNew ? [] : ProductTypeSiteRecord::find()->where(['productTypeId' => $record->id])->indexBy('siteId')->all();
            $urisChanged = false;

            foreach ($siteData as $siteUid => $siteSettings) {
                $siteId = $siteIdMap[$siteUid];

                /** @var ProductTypeSiteRecord $siteRecord */
                $siteRecord = $existing[$siteId] ?? new ProductTypeSiteRecord(['productTypeId' => $record->id, 'siteId' => $siteId]);

                $siteRecord->hasUrls = (bool) $siteSettings['hasUrls'];
                $siteRecord->uriFormat = $siteRecord->hasUrls ? $siteSettings['uriFormat'] : null;
                $siteRecord->template = $siteRecord->hasUrls ? $siteSettings['template'] : null;

                if (!$siteRecord->getIsNewRecord() && ($siteRecord->isAttributeChanged('hasUrls', false) || $siteRecord->isAttributeChanged('uriFormat', false))) {
                    $urisChanged = true;
                }

                $siteRecord->save(false);
            }

            foreach ($existing as $siteId => $siteRecord) {
                if (!in_array($siteId, $siteIdMap, true)) {
                    $siteRecord->delete();
                }
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->types = null;

        if ($urisChanged) {
            Queue::push(new ResaveElements([
                'description' => Craft::t('small-change', 'Resaving {name} products', ['name' => $data['name']]),
                'elementType' => Product::class,
                'criteria' => ['typeId' => $record->id, 'siteId' => '*', 'unique' => true, 'status' => null],
                'updateSearchIndex' => false,
            ]));
        }
    }

    public function handleDeletedProductType(ConfigEvent $event): void
    {
        $record = ProductTypeRecord::findOne(['uid' => $event->tokenMatches[0]]);

        if (!$record) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $elements = Craft::$app->getElements();

            foreach (Product::find()->typeId($record->id)->status(null)->all() as $product) {
                $elements->deleteElement($product, true);
            }

            if ($record->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($record->fieldLayoutId);
            }

            $record->delete();
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->types = null;
    }

    public function rebuildProjectConfig(RebuildConfigEvent $event): void
    {
        $config = [];

        foreach ($this->getAllProductTypes() as $type) {
            $config[$type->uid] = $type->getConfig();
        }

        $event->config['smallChange']['productTypes'] = $config;
    }

    /**
     * Makes sure the layout has a title and, for donation types, the donation settings, since
     * a donation product without them cannot be sold.
     */
    private function ensureFieldLayout(ProductType $type): void
    {
        $layout = $type->getFieldLayout();
        $tabs = $layout->getTabs();

        if (!$tabs) {
            $tab = new FieldLayoutTab(['name' => Craft::t('small-change', 'Content'), 'layout' => $layout]);
            $tab->setElements([]);
            $tabs = [$tab];
        }

        $hasTitle = false;
        $hasDonation = false;
        $hasPrice = false;

        foreach ($tabs as $tab) {
            foreach ($tab->getElements() as $element) {
                $hasTitle = $hasTitle || $element instanceof TitleField;
                $hasDonation = $hasDonation || $element instanceof DonationSettingsField;
                $hasPrice = $hasPrice || $element instanceof ProductPriceField;
            }
        }

        $first = $tabs[0];
        $elements = $first->getElements();

        if (!$hasDonation && $type->kind === ProductType::KIND_DONATION) {
            array_unshift($elements, new DonationSettingsField());
        }

        // A product that is sold at a fixed price cannot be saved without one, so the layout must offer it.
        if (!$hasPrice && $type->kind !== ProductType::KIND_DONATION) {
            array_unshift($elements, new ProductPriceField());
        }

        if (!$hasTitle) {
            array_unshift($elements, new TitleField());
        }

        $first->setElements($elements);
        $layout->setTabs($tabs);
        $type->setFieldLayout($layout);
    }
}
