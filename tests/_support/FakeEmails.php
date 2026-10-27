<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\helpers\PortalLink;
use coyshdigital\smallchange\models\Customer;
use coyshdigital\smallchange\services\Emails;

/**
 * Records what would have been queued, instead of pushing jobs.
 */
class FakeEmails extends Emails
{
    /**
     * @var array<int, array{string, string}> key and order number
     */
    public array $queued = [];

    /**
     * @var array<int, array<string, scalar|null>>
     */
    public array $contexts = [];

    /**
     * @var array<int, array{string, string}> address and link
     */
    public array $portalLinks = [];

    public function sendPortalLink(string $email, string $url): bool
    {
        $this->portalLinks[] = [$email, $url];

        return true;
    }

    public function queuePortalLink(Customer $customer, ?string $returnUrl): void
    {
        // The queue would sign the link when it runs; here that happens straight away so a test can follow it.
        $this->portalLinks[] = [$customer->email, PortalLink::make((int) $customer->id, $returnUrl)];
    }

    public function queue(string $key, Order $order, array $context = []): void
    {
        $this->queued[] = [$key, (string) $order->number];
        $this->contexts[] = $context;
    }

    /**
     * @return string[] the keys queued for an order
     */
    public function keysFor(string $number): array
    {
        return array_values(array_map(static fn(array $q): string => $q[0], array_filter($this->queued, static fn(array $q): bool => $q[1] === $number)));
    }
}
