<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\models\ShippingMethod;
use coyshdigital\smallchange\records\ShippingMethodRecord;
use craft\base\Component;

/**
 * Flat-rate delivery methods, optionally limited to some countries. No carriers, no weights.
 */
class ShippingMethods extends Component
{
    /**
     * @return ShippingMethod[] in the order they are offered
     */
    public function getAllMethods(bool $enabledOnly = false): array
    {
        $query = ShippingMethodRecord::find()->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC]);

        if ($enabledOnly) {
            $query->where(['enabled' => true]);
        }

        /** @var ShippingMethodRecord[] $records */
        $records = $query->all();

        return array_map(fn(ShippingMethodRecord $record): ShippingMethod => $this->toModel($record), $records);
    }

    public function getMethodById(int $id): ?ShippingMethod
    {
        $record = ShippingMethodRecord::findOne($id);

        return $record ? $this->toModel($record) : null;
    }

    /**
     * What a customer delivering to this country may choose from.
     *
     * @return ShippingMethod[]
     */
    public function getApplicableMethods(?string $countryCode): array
    {
        return array_values(array_filter(
            $this->getAllMethods(true),
            static fn(ShippingMethod $method): bool => $method->appliesTo($countryCode),
        ));
    }

    public function saveMethod(ShippingMethod $method, bool $runValidation = true): bool
    {
        if ($runValidation && !$method->validate()) {
            return false;
        }

        $record = $method->id ? ShippingMethodRecord::findOne($method->id) : new ShippingMethodRecord();

        if (!$record) {
            return false;
        }

        if (!$method->id) {
            $method->sortOrder = (int) ShippingMethodRecord::find()->max('[[sortOrder]]') + 1;
        }

        $record->name = $method->name;
        $record->handle = $method->handle;
        $record->price = $method->price;
        $record->countryCodes = $method->countryCodes ?: null;
        $record->enabled = $method->enabled;
        $record->sortOrder = $method->sortOrder;
        $record->save(false);

        $method->id = $record->id;

        return true;
    }

    /**
     * Orders keep the method's name and price, so deleting one never changes an old order.
     */
    public function deleteMethod(ShippingMethod $method): bool
    {
        return (bool) ShippingMethodRecord::deleteAll(['id' => $method->id]);
    }

    /**
     * @param int[] $ids in the new order
     */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $position => $id) {
            ShippingMethodRecord::updateAll(['sortOrder' => $position + 1], ['id' => (int) $id]);
        }
    }

    private function toModel(ShippingMethodRecord $record): ShippingMethod
    {
        return new ShippingMethod($record->getAttributes(['id', 'name', 'handle', 'price', 'countryCodes', 'enabled', 'sortOrder']));
    }
}
