<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\utilities;

use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Stripe;
use coyshdigital\smallchange\services\Webhooks;
use Craft;
use craft\base\Utility;

/**
 * Shows which Stripe mode the install is in, whether the keys are usable, where Stripe should
 * send webhooks, and what happened to the latest ones.
 */
final class StripeUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('small-change', 'Stripe');
    }

    public static function id(): string
    {
        return 'small-change-stripe';
    }

    public static function icon(): string
    {
        return 'credit-card';
    }

    public static function contentHtml(): string
    {
        $webhookUrl = Plugin::getInstance()->webhooks->endpointUrl();

        return Craft::$app->getView()->renderTemplate('small-change/_utility', [
            'status' => Plugin::getInstance()->stripe->status(),
            'webhookUrl' => $webhookUrl,
            'webhookPublic' => Webhooks::isPublicUrl($webhookUrl),
            'enabledEvents' => Webhooks::ENABLED_EVENTS,
            'apiVersion' => Stripe::API_VERSION,
            'events' => Plugin::getInstance()->webhooks->recentEvents(50),
            'hasTestData' => Plugin::getInstance()->stripe->mode() === Stripe::MODE_LIVE && Plugin::getInstance()->privacy->hasTestData(),
        ]);
    }
}
