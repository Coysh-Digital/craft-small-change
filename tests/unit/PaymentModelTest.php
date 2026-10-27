<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\models\Payment;
use DateTime;
use DateTimeZone;

final class PaymentModelTest extends Unit
{
    public function testStoredDatesAreReadAsUtcSoTheyCanBeShownInTheViewersTimeZone(): void
    {
        $paid = new DateTime('-2 hours', new DateTimeZone('UTC'));
        $sent = new DateTime('-1 hour', new DateTimeZone('UTC'));
        $payment = new Payment([
            'paidAt' => $paid->format('Y-m-d H:i:s'),
            'receiptSentAt' => $sent->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame($paid->getTimestamp(), $payment->getPaidDate()?->getTimestamp());
        $this->assertSame($sent->getTimestamp(), $payment->getReceiptSentDate()?->getTimestamp());
    }

    public function testNoDateMeansNoDate(): void
    {
        $payment = new Payment();

        $this->assertNull($payment->getPaidDate());
        $this->assertNull($payment->getReceiptSentDate());
    }
}
