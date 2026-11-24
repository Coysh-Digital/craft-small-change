<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\helpers;

use Craft;

/**
 * How a subscription is described next to its price: "a month, for 10 payments".
 */
final class SubscriptionTerms
{
    public static function describe(?string $interval, ?int $payments): ?string
    {
        if ($interval === null) {
            return null;
        }

        $every = $interval === 'year' ? Craft::t('small-change', 'a year') : Craft::t('small-change', 'a month');

        if ($payments === null) {
            return Craft::t('small-change', '{every}, until cancelled', ['every' => $every]);
        }

        return Craft::t('small-change', '{every}, for {count, plural, one{# payment} other{# payments}}', ['every' => $every, 'count' => $payments]);
    }
}
