<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\jobs\ReconcileSubscriptions;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use Stripe\Exception\ApiErrorException;
use yii\web\Response;

/**
 * The buttons on the Stripe utility. Admin-only, since they act on the Stripe account.
 */
final class StripeController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin(false);
        $this->requirePostRequest();

        return true;
    }

    /**
     * Creates the webhook endpoint at Stripe. The signing secret is shown once and then lives only in
     * the site's environment, so it is put in the flash message rather than stored.
     */
    public function actionRegisterWebhook(): Response
    {
        try {
            $plugin = Plugin::getInstance();
            $endpoint = $plugin->webhooks->registerEndpoint($plugin->webhooks->endpointUrl());
            $this->setSuccessFlash(Craft::t('small-change', "Webhook endpoint created. Copy its signing secret into your environment now, because Stripe won't show it again: {secret}", ['secret' => $endpoint['secret']]));
        } catch (ApiErrorException|\RuntimeException $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }

    public function actionSyncProducts(): Response
    {
        $plugin = Plugin::getInstance();
        $mode = $plugin->stripe->mode();

        if ($mode === null) {
            $this->setFailFlash(Craft::t('small-change', 'Add a Stripe secret key first.'));

            return $this->redirectToPostedUrl();
        }

        $result = $plugin->stripeProducts->syncAll($mode);
        $this->setSuccessFlash(Craft::t('small-change', '{created} created, {updated} updated, {failed} failed.', $result));

        return $this->redirectToPostedUrl();
    }

    public function actionReconcileSubscriptions(): Response
    {
        Queue::push(new ReconcileSubscriptions());
        $this->setSuccessFlash(Craft::t('small-change', 'Checking recurring donations with Stripe in the background.'));

        return $this->redirectToPostedUrl();
    }

    public function actionDeleteTestData(): Response
    {
        try {
            $result = Plugin::getInstance()->privacy->deleteTestData();
            $this->setSuccessFlash(Craft::t('small-change', 'Deleted {orders} orders, {subscriptions} subscriptions, {customers} customers and {events} stored events made in test mode.', $result));
        } catch (\RuntimeException $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }

    public function actionReplayEvents(): Response
    {
        $days = max(1, min(30, (int) $this->request->getBodyParam('days', 3)));

        try {
            $result = Plugin::getInstance()->webhooks->replay(time() - $days * 86400);
            $this->setSuccessFlash(Craft::t('small-change', '{found} events found, {replayed} processed, {failed} failed.', $result));
        } catch (ApiErrorException $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }
}
