<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\exceptions\CheckoutException;
use coyshdigital\smallchange\helpers\RateLimit;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\base\Element;
use craft\elements\Address;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

final class DonationsController extends Controller
{
    private const MAX_ATTEMPTS = 30;
    private const WINDOW_SECONDS = 600;

    protected array|bool|int $allowAnonymous = ['start'];

    /**
     * Turns a donation form into a pending order and a Checkout Session, then sends the donor to
     * the payment page. The amount is the donor's choice, so it is validated here; nothing the
     * browser says about totals is trusted.
     */
    public function actionStart(): ?Response
    {
        $this->requirePostRequest();

        if (RateLimit::exceeded('donations', self::MAX_ATTEMPTS, self::WINDOW_SECONDS)) {
            throw new TooManyRequestsHttpException(Craft::t('small-change', 'Too many attempts. Please wait a few minutes and try again.'));
        }

        $plugin = Plugin::getInstance();
        $request = $this->request;

        $product = Product::find()->id((int) $request->getRequiredBodyParam('productId'))->status(null)->one();

        if (!$product) {
            throw new BadRequestHttpException('Unknown donation.');
        }

        $returnUrl = $request->getValidatedBodyParam('returnUrl');
        $paymentUrl = $request->getValidatedBodyParam('paymentUrl') ?: '/';
        $ticked = (bool) $request->getBodyParam('giftAid');

        $order = $plugin->orders->buildDonation(
            $product,
            $this->postedAmount(),
            (string) $request->getBodyParam('email', ''),
            $ticked,
            $ticked ? $this->homeAddress() : null,
            is_string($returnUrl) ? $returnUrl : null,
            (string) $request->getBodyParam('interval', '') ?: null,
        );

        $this->applyDonationFields($order);

        if ($order->hasErrors()) {
            return $this->failure($order, Craft::t('small-change', 'Please check the highlighted details.'));
        }

        if (!Craft::$app->getElements()->saveElement($order)) {
            return $this->failure($order, Craft::t('small-change', "We couldn't save your donation. Please try again."));
        }

        try {
            $hostedUrl = $plugin->checkout->start($order, $paymentUrl);
        } catch (CheckoutException $e) {
            $order->addError('stripe', $e->getMessage());

            return $this->failure($order, $e->getMessage());
        }

        if ($hostedUrl) {
            return $this->asSuccess(Craft::t('small-change', 'Taking you to the payment page.'), ['number' => $order->number, 'paymentUrl' => $hostedUrl], $hostedUrl);
        }

        // "redirect" may contain {number}, which is filled in from the order.
        return $this->asSuccess(Craft::t('small-change', 'Your donation is ready for payment.'), ['number' => $order->number], $this->getPostedRedirectUrl($order));
    }

    /**
     * Fills in the donation fields from `fields[handle]` and checks them as a live page would, so a
     * required one cannot be skipped. Only fields on the donation layout are read; a checkout field
     * posted here is ignored. An order that already has an error is left alone.
     */
    private function applyDonationFields(Order $order): void
    {
        if ($order->hasErrors()) {
            return;
        }

        $posted = $this->request->getBodyParam('fields');
        $posted = is_array($posted) ? $posted : [];

        foreach (Plugin::getInstance()->orders->getDonationFields() as $field) {
            if (array_key_exists($field->handle, $posted)) {
                $order->setFieldValueFromRequest($field->handle, $posted[$field->handle]);
            }
        }

        // beforeSave() names the order after its number; a live page needs the title now.
        $order->title = $order->number;
        $order->setScenario(Element::SCENARIO_LIVE);
        $order->validate(null, false);
    }

    /**
     * A typed amount wins; otherwise the suggested amount the donor picked. Keeping them as two
     * fields lets a plain HTML form (no JavaScript) offer both.
     */
    private function postedAmount(): mixed
    {
        $custom = $this->request->getBodyParam('amount');

        if (is_string($custom) && trim($custom) !== '') {
            return $custom;
        }

        return $this->request->getBodyParam('suggestedAmount');
    }

    /**
     * Hands the order back to the form with its errors, without putting the order itself in a JSON response.
     */
    private function failure(Order $order, string $message): ?Response
    {
        return $this->asFailure($message, ['errors' => $order->getErrors()], ['order' => $order]);
    }

    /**
     * The donor's home address, from `billingAddress[...]`. Only read when they ticked Gift Aid.
     */
    private function homeAddress(): Address
    {
        $posted = (array) $this->request->getBodyParam('billingAddress', []);
        $address = new Address();

        foreach (['firstName', 'lastName', 'addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode'] as $attribute) {
            if (isset($posted[$attribute]) && is_string($posted[$attribute])) {
                $address->$attribute = trim($posted[$attribute]);
            }
        }

        return $address;
    }
}
