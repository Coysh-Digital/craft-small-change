<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Emails;
use Craft;
use craft\elements\User;
use craft\web\Controller;
use DateTime;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class OrdersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW_ORDERS);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('small-change/orders/index');
    }

    public function actionView(int $orderId): Response
    {
        $order = Order::find()->id($orderId)->one();

        if (!$order) {
            throw new NotFoundHttpException('Order not found');
        }

        $plugin = Plugin::getInstance();
        $payments = $plugin->payments->getPaymentsByOrderId($orderId);
        $refunds = $plugin->payments->getRefundsByOrderId($orderId);
        $customer = $order->customerId ? $plugin->customers->getById((int) $order->customerId) : null;

        // The staff who made each refund, in one query.
        $refundUserIds = array_values(array_unique(array_filter(array_map(static fn($refund): ?int => $refund->userId, $refunds))));
        $refundUsers = $refundUserIds ? User::find()->id($refundUserIds)->status(null)->indexBy('id')->all() : [];

        return $this->renderTemplate('small-change/orders/view', [
            'order' => $order,
            'customer' => $customer,
            'customerName' => $this->customerName($order, $customer?->name),
            'payments' => $payments,
            'refunds' => $refunds,
            'refundUsers' => $refundUsers,
            'refundable' => array_column(array_map(
                fn($payment): array => ['id' => $payment->id, 'amount' => $plugin->payments->refundableAmount($payment)],
                $payments,
            ), 'amount', 'id'),
            'canAnonymise' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_ANONYMISE_CUSTOMERS),
            'subscription' => $plugin->subscriptions->getSubscriptionByOrderId($orderId),
            'canRefund' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REFUND_PAYMENTS),
            'receiptsEnabled' => $plugin->getSettings()->emailEnabled(Emails::KEY_RECEIPT),
            'title' => $order->number,
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_ORDERS),
        ]);
    }

    /**
     * The orders have no name of their own, so it comes from the address the donor gave, else from their customer record.
     */
    private function customerName(Order $order, ?string $customerName): string
    {
        $customers = Plugin::getInstance()->customers;

        foreach ([$order->getShippingAddress(), $order->getBillingAddress()] as $address) {
            if ($address && ($name = $customers->fullName($address)) !== '') {
                return $name;
            }
        }

        return trim((string) $customerName);
    }

    /**
     * Records that the order has been posted, or (with `undo`) that it has not.
     */
    public function actionMarkFulfilled(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_ORDERS);

        $order = $this->orderFromRequest();

        if ($order->state !== Order::STATE_COMPLETED) {
            throw new BadRequestHttpException('Only a completed order can be dispatched.');
        }

        $order->dateFulfilled = $this->request->getBodyParam('undo') ? null : new DateTime();
        $this->save($order);

        $this->setSuccessFlash($order->dateFulfilled
            ? Craft::t('small-change', 'Marked as dispatched.')
            : Craft::t('small-change', 'Dispatch undone.'));

        return $this->redirectToPostedUrl($order);
    }

    /**
     * Sends the donor another copy of their receipt, for when the first went astray.
     */
    public function actionResendReceipt(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_ORDERS);

        $order = $this->orderFromRequest();

        if ($order->state !== Order::STATE_COMPLETED || !$order->email) {
            throw new BadRequestHttpException('Only a completed order with an email address has a receipt.');
        }

        if (!Plugin::getInstance()->getSettings()->emailEnabled(Emails::KEY_RECEIPT_RESEND)) {
            $this->setFailFlash(Craft::t('small-change', 'Receipt emails are switched off in the settings.'));

            return $this->redirectToPostedUrl($order);
        }

        Plugin::getInstance()->emails->queue(Emails::KEY_RECEIPT_RESEND, $order);
        $this->setSuccessFlash(Craft::t('small-change', 'Receipt queued for {email}.', ['email' => $order->email]));

        return $this->redirectToPostedUrl($order);
    }

    /**
     * Cancels an order that is still waiting for payment. A paid order is refunded, not cancelled.
     */
    public function actionCancel(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_ORDERS);

        $order = $this->orderFromRequest();

        if ($order->state !== Order::STATE_PENDING) {
            throw new BadRequestHttpException('Only an order awaiting payment can be cancelled.');
        }

        Plugin::getInstance()->orders->cancelOrder($order);
        $this->setSuccessFlash(Craft::t('small-change', 'Order cancelled.'));

        return $this->redirectToPostedUrl($order);
    }

    /**
     * Erases the personal data of the order's donor: this order and every other placed with the same address.
     */
    public function actionAnonymise(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_ANONYMISE_CUSTOMERS);

        $order = $this->orderFromRequest();

        if ($order->dateAnonymised !== null || !$order->email) {
            throw new BadRequestHttpException('There is no personal data left on this order.');
        }

        $result = Plugin::getInstance()->privacy->anonymiseDonor((string) $order->email);

        if ($result['skipped']) {
            $this->setFailFlash(Craft::t('small-change', 'Some orders were left alone: {reasons}', ['reasons' => implode(' ', $result['skipped'])]));
        } else {
            $this->setSuccessFlash(Craft::t('small-change', 'Personal data erased from {count, plural, =1{1 order} other{# orders}}.', ['count' => $result['orders']]));
        }

        return $this->redirectToPostedUrl($order);
    }

    public function actionSaveNote(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_ORDERS);

        $order = $this->orderFromRequest();
        $note = trim((string) $this->request->getBodyParam('adminNote'));
        $order->adminNote = $note !== '' ? mb_substr($note, 0, 5000) : null;
        $this->save($order);

        $this->setSuccessFlash(Craft::t('small-change', 'Note saved.'));

        return $this->redirectToPostedUrl($order);
    }

    private function orderFromRequest(): Order
    {
        $order = Order::find()->id((int) $this->request->getRequiredBodyParam('orderId'))->one();

        if (!$order) {
            throw new NotFoundHttpException('Order not found');
        }

        return $order;
    }

    private function save(Order $order): void
    {
        if (!Craft::$app->getElements()->saveElement($order, false, false)) {
            throw new \RuntimeException('Could not save the order: ' . implode(' ', $order->getFirstErrors()));
        }
    }
}
