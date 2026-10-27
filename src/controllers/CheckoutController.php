<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\exceptions\CheckoutException;
use coyshdigital\smallchange\helpers\RateLimit;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Checkout;
use Craft;
use craft\base\Element;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

final class CheckoutController extends Controller
{
    private const MAX_STARTS = 30;
    private const WINDOW_SECONDS = 600;

    protected array|bool|int $allowAnonymous = ['start', 'complete', 'client-secret'];

    /**
     * Takes the details form, checks everything again on the server and sends the customer to pay.
     * Nothing about prices comes from the form.
     */
    public function actionStart(): ?Response
    {
        $this->requirePostRequest();

        if (RateLimit::exceeded('checkout', self::MAX_STARTS, self::WINDOW_SECONDS)) {
            throw new TooManyRequestsHttpException(Craft::t('small-change', 'Too many attempts. Please wait a few minutes and try again.'));
        }

        $plugin = Plugin::getInstance();
        $cart = $plugin->carts->getCart();

        if ($cart === null || !$cart->getLineItems()) {
            return $this->asFailure(Craft::t('small-change', 'Your basket is empty.'), ['errors' => ['items' => [Craft::t('small-change', 'Your basket is empty.')]]]);
        }

        $returnUrl = $this->request->getValidatedBodyParam('returnUrl');
        $paymentUrl = $this->request->getValidatedBodyParam('paymentUrl') ?: '/';

        $plugin->carts->applyDetails($cart, $this->request->getBodyParams());
        $cart->returnUrl = is_string($returnUrl) ? $returnUrl : null;
        $notices = $plugin->carts->save($cart);

        if ($notices) {
            return $this->asFailure(implode(' ', $notices), ['errors' => array_map(static fn(string $notice): array => [$notice], $notices), 'cart' => $plugin->carts->summary($cart)], ['cart' => $cart]);
        }

        // Custom fields validate as they would on a live page; then the shop's own rules.
        $cart->setScenario(Element::SCENARIO_LIVE);
        $cart->validate();
        $plugin->orders->validateForCheckout($cart);

        if ($cart->hasErrors()) {
            return $this->asFailure(Craft::t('small-change', 'Please check the highlighted details.'), ['errors' => $cart->getErrors()], ['cart' => $cart]);
        }

        try {
            $hostedUrl = $plugin->checkout->start($cart, $paymentUrl);
        } catch (CheckoutException $e) {
            return $this->asFailure($e->getMessage(), ['errors' => ['checkout' => [$e->getMessage()]], 'cart' => $plugin->carts->summary($cart)], ['cart' => $cart]);
        }

        if ($hostedUrl) {
            return $this->asSuccess(Craft::t('small-change', 'Taking you to the payment page.'), ['number' => $cart->number, 'paymentUrl' => $hostedUrl], $hostedUrl);
        }

        return $this->asSuccess(Craft::t('small-change', 'Your order is ready for payment.'), ['number' => $cart->number], $this->getPostedRedirectUrl($cart));
    }

    /**
     * Where Stripe sends the donor back to. It only speeds up what the webhook does anyway.
     */
    public function actionComplete(): Response
    {
        $token = (string) $this->request->getQueryParam('t', '');
        $sessionId = (string) $this->request->getQueryParam('session_id', '');

        try {
            $result = Plugin::getInstance()->checkout->handleReturn($token, $sessionId);
        } catch (CheckoutException $e) {
            $this->setFailFlash($e->getMessage());

            return $this->redirect(UrlHelper::siteUrl());
        }

        $order = $result['order'];

        if ($result['outcome'] === Checkout::OUTCOME_OPEN) {
            // Not paid: back to the payment form with the order still in this session.
            $this->setFailFlash(Craft::t('small-change', "Your payment wasn't completed, so you haven't been charged."));

            return $this->redirect(UrlHelper::urlWithParams(UrlHelper::siteUrl($result['paymentUrl'] ?: ''), ['number' => $order->number]));
        }

        $destination = $order->returnUrl ?: '';

        // Only the number travels in the URL; the page proves access with the session.
        return $this->redirect(UrlHelper::urlWithParams(UrlHelper::siteUrl($destination), ['number' => $order->number]));
    }

    /**
     * The payment form's client secret, for pages that fetch it rather than render it.
     */
    public function actionClientSecret(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $order = $plugin->orders->recentOrder((string) $this->request->getRequiredBodyParam('number'));
        $secret = $order ? $plugin->checkout->clientSecret($order) : null;

        if ($secret === null) {
            return $this->asFailure(Craft::t('small-change', 'This payment is no longer available.'));
        }

        return $this->asJson(['clientSecret' => $secret]);
    }
}
