<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

final class OverviewController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        return $this->renderTemplate('small-change/index', [
            'gettingStarted' => $this->gettingStarted(),
        ]);
    }

    /**
     * Having a signing secret is only half of it. The step is done once Stripe has actually sent this site an event.
     *
     * @return array{label: string, done: bool, url: string, hint?: string}
     */
    private function webhookStep(bool $secretSet, bool $received): array
    {
        $step = [
            'label' => Craft::t('small-change', 'Connect the Stripe webhook'),
            'done' => $secretSet && $received,
            'url' => 'utilities/small-change-stripe',
        ];

        if ($secretSet && !$received) {
            $step['hint'] = Craft::t('small-change', "The signing secret is set, but Stripe hasn't sent this site an event yet. Register the endpoint or forward events with the Stripe CLI, then make a test payment.");
        }

        return $step;
    }

    /**
     * The first-run steps, for admins only, until they are all done.
     *
     * @return list<array{label: string, done: bool, url: string, hint?: string}>
     */
    private function gettingStarted(): array
    {
        $general = Craft::$app->getConfig()->getGeneral();

        if (!Craft::$app->getUser()->getIsAdmin() || !$general->allowAdminChanges) {
            return [];
        }

        $plugin = Plugin::getInstance();
        $stripe = $plugin->stripe->status();
        $templates = $plugin->templates;

        $installed = false;

        foreach ($templates->setHandles() as $handle) {
            $installed = $installed || $templates->status($handle) !== 'none';
        }

        $steps = [
            [
                'label' => Craft::t('small-change', 'Add your Stripe keys'),
                'done' => $stripe['secretKey'] === 'set' && $stripe['publishableKey'] === 'set',
                'url' => 'small-change/settings/stripe',
            ],
            $this->webhookStep($stripe['webhookSecret'] === 'set', $plugin->webhooks->hasReceivedDelivery()),
            [
                'label' => Craft::t('small-change', 'Create a donation or a product'),
                'done' => Product::find()->status(null)->exists(),
                'url' => 'small-change/products',
            ],
            [
                'label' => Craft::t('small-change', 'Install the page templates'),
                'done' => $installed,
                'url' => 'small-change/settings/templates',
            ],
        ];

        // Nothing to show once everything is done.
        return array_filter($steps, static fn(array $step): bool => !$step['done']) === [] ? [] : $steps;
    }
}
