<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\migrations;

use coyshdigital\smallchange\db\Table;
use craft\db\Migration;

/**
 * Adds the column that limits a discount code to chosen products. Existing codes get null, which still means
 * every product. A fresh install already has it, so the migration checks first.
 */
class m261113_120000_add_discount_product_ids extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists(Table::DISCOUNTS, 'productIds')) {
            return true;
        }

        // Yii accepts the builder; its type hint only says string.
        $this->addColumn(Table::DISCOUNTS, 'productIds', (string) $this->json()->after('minSubtotal'));

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261113_120000_add_discount_product_ids cannot be reverted.\n";

        return false;
    }
}
