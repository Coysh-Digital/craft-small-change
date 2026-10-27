<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use craft\base\Model;
use DateTime;

class Refund extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    /**
     * Statuses in which the money is refunded or on its way, so it counts against what can still be refunded.
     */
    public const COUNTING_STATUSES = [self::STATUS_PENDING, self::STATUS_REQUIRES_ACTION, self::STATUS_SUCCEEDED];

    public const REASONS = ['requested_by_customer', 'duplicate', 'fraudulent'];

    public ?int $id = null;
    public int $paymentId = 0;
    public string $stripeMode = 'test';
    public string $stripeRefundId = '';
    public int $amount = 0;
    public string $status = self::STATUS_PENDING;
    public ?string $reason = null;
    public ?string $note = null;
    public ?int $userId = null;
    public ?string $failureReason = null;
    public ?DateTime $dateCreated = null;
}
