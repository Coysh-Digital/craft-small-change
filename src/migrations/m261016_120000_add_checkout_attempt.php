<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\migrations;

use coyshdigital\smallchange\db\Table;
use craft\db\Migration;

/**
 * Adds the column that counts pulled-back payments to orders created by an earlier build.
 * A fresh install already has it, so the migration checks first.
 */
class m261016_120000_add_checkout_attempt extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists(Table::ORDERS, 'checkoutAttempt')) {
            return true;
        }

        // Yii accepts the builder; its type hint only says string.
        $this->addColumn(Table::ORDERS, 'checkoutAttempt', (string) $this->smallInteger()->unsigned()->notNull()->defaultValue(0)->after('stripePaymentIntentId'));

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261016_120000_add_checkout_attempt cannot be reverted.\n";

        return false;
    }
}
