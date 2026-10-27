<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

class Payment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    public ?int $id = null;
    public int $orderId = 0;
    public ?int $subscriptionId = null;
    public ?int $customerId = null;
    public string $stripeMode = 'test';
    public ?string $stripePaymentIntentId = null;
    public ?string $stripeChargeId = null;
    public ?string $stripeInvoiceId = null;
    public ?string $stripeCheckoutSessionId = null;
    public int $amount = 0;
    public int $amountRefunded = 0;
    public string $currency = 'GBP';
    public string $status = self::STATUS_PENDING;
    public ?string $failureCode = null;
    public ?string $failureMessage = null;
    public bool $giftAidDeclared = false;
    public int $giftAidAmount = 0;
    public ?string $stripeDisputeId = null;
    public ?string $disputeStatus = null;
    public ?string $paidAt = null;
    public ?string $receiptSentAt = null;

    /**
     * The date columns come back as UTC strings; these give a date a template can show in the viewer's time zone.
     */
    public function getPaidDate(): ?DateTime
    {
        return $this->toDate($this->paidAt);
    }

    public function getReceiptSentDate(): ?DateTime
    {
        return $this->toDate($this->receiptSentAt);
    }

    private function toDate(?string $value): ?DateTime
    {
        $date = $value ? DateTimeHelper::toDateTime($value) : false;

        return $date ?: null;
    }
}
