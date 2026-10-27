<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\db\Table;
use craft\base\Component;
use craft\helpers\Db;
use yii\db\Query;

/**
 * Small facts that belong to one environment and Stripe mode, so they can't live in project config:
 * which webhook endpoint this install registered, for example.
 */
class State extends Component
{
    public function get(string $name, string $mode): ?string
    {
        $value = (new Query())->select('value')->from(Table::STATE)->where(['name' => $name, 'stripeMode' => $mode])->scalar();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * One statement, keyed on the (name, mode) unique index, so two webhook deliveries landing at once
     * can't both decide the row is missing and race to insert it.
     */
    public function set(string $name, string $mode, ?string $value): void
    {
        Db::upsert(Table::STATE, ['name' => $name, 'stripeMode' => $mode, 'value' => $value], ['value' => $value]);
    }
}
