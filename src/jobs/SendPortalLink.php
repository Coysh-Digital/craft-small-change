<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\jobs;

use coyshdigital\smallchange\helpers\PortalLink;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

/**
 * Emails a donor their link to the billing portal. It runs from the queue so the answer to the form takes
 * the same time whether or not the address has a recurring donation, and the link is signed when it is sent
 * rather than stored in the queue.
 */
class SendPortalLink extends BaseJob implements RetryableJobInterface
{
    public int $customerId = 0;
    public ?string $returnUrl = null;
    public ?int $siteId = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $customer = $plugin->customers->getById($this->customerId);

        if ($customer === null || (string) $customer->email === '') {
            return;
        }

        $sites = Craft::$app->getSites();
        $previousSite = $sites->getCurrentSite();

        if ($this->siteId !== null && ($site = $sites->getSiteById($this->siteId)) !== null) {
            $sites->setCurrentSite($site);
        }

        try {
            $sent = $plugin->emails->sendPortalLink((string) $customer->email, PortalLink::make((int) $customer->id, $this->returnUrl));
        } finally {
            $sites->setCurrentSite($previousSite);
        }

        if (!$sent) {
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
