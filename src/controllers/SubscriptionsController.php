<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\exceptions\RefundException;
use coyshdigital\smallchange\models\Subscription;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class SubscriptionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_SUBSCRIPTIONS);

        return true;
    }

    public function actionIndex(): Response
    {
        $status = (string) $this->request->getQueryParam('status', '');
        $subscriptions = Plugin::getInstance()->subscriptions->getSubscriptions($status !== '' ? $status : null);

        return $this->renderTemplate('small-change/subscriptions/index', [
            'subscriptions' => $subscriptions,
            'status' => $status,
            'orders' => $this->ordersFor($subscriptions),
        ]);
    }

    public function actionView(int $subscriptionId): Response
    {
        $plugin = Plugin::getInstance();
        $subscription = $plugin->subscriptions->getSubscriptionById($subscriptionId);

        if (!$subscription) {
            throw new NotFoundHttpException('Recurring donation not found');
        }

        $order = $subscription->orderId ? Order::find()->id($subscription->orderId)->one() : null;

        return $this->renderTemplate('small-change/subscriptions/view', [
            'subscription' => $subscription,
            'order' => $order,
            'payments' => $order ? array_values(array_filter(
                $plugin->payments->getPaymentsByOrderId((int) $order->id),
                static fn($payment): bool => $payment->subscriptionId === $subscription->id,
            )) : [],
            'title' => $order ? $order->number : $subscription->stripeSubscriptionId,
        ]);
    }

    public function actionCancel(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $subscription = $plugin->subscriptions->getSubscriptionById((int) $this->request->getRequiredBodyParam('subscriptionId'));

        if (!$subscription) {
            throw new NotFoundHttpException('Recurring donation not found');
        }

        try {
            $plugin->subscriptions->cancel($subscription, (bool) $this->request->getBodyParam('atPeriodEnd', true));
            $this->setSuccessFlash(Craft::t('small-change', 'Recurring donation updated.'));
        } catch (RefundException $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }

    public function actionRefresh(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $subscription = $plugin->subscriptions->getSubscriptionById((int) $this->request->getRequiredBodyParam('subscriptionId'));

        if (!$subscription) {
            throw new NotFoundHttpException('Recurring donation not found');
        }

        $plugin->subscriptions->reconcile($subscription)
            ? $this->setSuccessFlash(Craft::t('small-change', 'Checked with Stripe.'))
            : $this->setFailFlash(Craft::t('small-change', 'Stripe could not be reached.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @param Subscription[] $subscriptions
     * @return array<int, Order> keyed by order ID
     */
    private function ordersFor(array $subscriptions): array
    {
        $ids = array_filter(array_map(static fn(Subscription $s): ?int => $s->orderId, $subscriptions));

        if (!$ids) {
            return [];
        }

        $orders = [];

        foreach (Order::find()->id(array_values($ids))->all() as $order) {
            $orders[(int) $order->id] = $order;
        }

        return $orders;
    }
}
