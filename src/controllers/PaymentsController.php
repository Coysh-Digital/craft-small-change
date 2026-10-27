<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\exceptions\RefundException;
use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class PaymentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        // Refunding is nested under viewing orders in the permissions list, so both are needed, as on the order screen.
        $this->requirePermission(Plugin::PERMISSION_VIEW_ORDERS);
        $this->requirePermission(Plugin::PERMISSION_REFUND_PAYMENTS);

        return true;
    }

    /**
     * Refunds some or all of a payment. The amount is typed in the store currency and checked against
     * what is still refundable on the server, never trusted from the form.
     */
    public function actionRefund(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $payment = $plugin->payments->getPaymentById((int) $this->request->getRequiredBodyParam('paymentId'));

        if (!$payment) {
            throw new NotFoundHttpException('Payment not found');
        }

        $amount = Money::parse($this->request->getBodyParam('amount'), $payment->currency);
        $redirect = 'small-change/orders/' . $payment->orderId . '#order-payments';

        if ($amount === null) {
            $this->setFailFlash(Craft::t('small-change', 'Enter the amount to refund, such as 5 or 2.50.'));

            return $this->redirect($redirect);
        }

        try {
            $refund = $plugin->payments->refund(
                $payment,
                $amount,
                (string) $this->request->getBodyParam('reason') ?: null,
                (string) $this->request->getBodyParam('note') ?: null,
                Craft::$app->getUser()->getId(),
            );
        } catch (RefundException $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirect($redirect);
        }

        $this->setSuccessFlash($refund->status === 'succeeded'
            ? Craft::t('small-change', 'Refunded {amount}.', ['amount' => Money::format($refund->amount, $payment->currency)])
            : Craft::t('small-change', 'Refund of {amount} started. Its status is {status}.', ['amount' => Money::format($refund->amount, $payment->currency), 'status' => $refund->status]));

        return $this->redirect($redirect);
    }
}
