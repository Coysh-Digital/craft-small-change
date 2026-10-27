<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\jobs;

use coyshdigital\smallchange\Plugin;
use Craft;
use craft\queue\BaseJob;

class ReconcileSubscriptions extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->subscriptions->reconcileAll();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('small-change', 'Checking recurring donations with Stripe');
    }
}
