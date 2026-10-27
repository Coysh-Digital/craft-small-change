<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\records;

use coyshdigital\smallchange\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $stripeEventId
 * @property string $type
 * @property string $stripeMode
 * @property bool $livemode
 * @property string|null $apiVersion
 * @property string|null $stripeObjectId
 * @property string|null $stripeCustomerId
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property string $payload
 * @property string|null $redactedAt
 * @property string $dateReceived
 * @property string|null $dateProcessed
 */
class WebhookEventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::WEBHOOK_EVENTS;
    }
}
