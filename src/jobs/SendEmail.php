<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\jobs;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

class SendEmail extends BaseJob implements RetryableJobInterface
{
    public string $key = '';
    public int $orderId = 0;

    /**
     * @var array<string, scalar|null>
     */
    public array $context = [];

    public function execute($queue): void
    {
        $order = Order::find()->id($this->orderId)->one();

        if (!$order) {
            return;
        }

        if (!Plugin::getInstance()->emails->send($this->key, $order, $this->context)) {
            // Throwing hands it back to the queue to retry.
            throw new \RuntimeException('The email could not be sent.');
        }
    }

    public function getTtr(): int
    {
        return 120;
    }

    public function canRetry($attempt, $error): bool // @phpstan-ignore missingType.parameter
    {
        return $attempt < 4;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('small-change', 'Sending Small Change email');
    }
}
