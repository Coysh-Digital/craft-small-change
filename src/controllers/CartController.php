<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\elements\Product;
use coyshdigital\smallchange\helpers\RateLimit;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * The basket. Every action changes the visitor's own cart and nothing else; the cart is found through
 * their session, so there is no cart ID to pass or guess.
 */
final class CartController extends Controller
{
    private const MAX_ACTIONS = 200;
    private const WINDOW_SECONDS = 600;

    protected array|bool|int $allowAnonymous = ['add', 'update', 'remove', 'apply-discount', 'remove-discount'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePostRequest();

        if (RateLimit::exceeded('cart', self::MAX_ACTIONS, self::WINDOW_SECONDS)) {
            throw new TooManyRequestsHttpException(Craft::t('small-change', 'Too many requests. Please wait a few minutes and try again.'));
        }

        return true;
    }

    public function actionAdd(): ?Response
    {
        $product = Product::find()->id((int) $this->request->getRequiredBodyParam('productId'))->status(null)->one();

        if (!$product instanceof Product) {
            throw new BadRequestHttpException('Unknown product.');
        }

        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart(true);
        $error = $carts->addProduct($cart, $product, (int) $this->request->getBodyParam('qty', 1), $this->postedAmount());

        if ($error !== null) {
            return $this->failure($error, ['items' => [$error]]);
        }

        return $this->saved($cart, Craft::t('small-change', '{item} was added to your basket.', ['item' => $product->title]));
    }

    public function actionUpdate(): Response
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart(true);
        $post = $this->request->getBodyParams();

        if (isset($post['quantities']) && is_array($post['quantities'])) {
            $carts->setQuantities($cart, $post['quantities']);
        }

        $carts->applyDetails($cart, $post);

        return $this->saved($cart, Craft::t('small-change', 'Your basket was updated.'));
    }

    public function actionRemove(): ?Response
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart();

        if ($cart === null) {
            return $this->asSuccess(Craft::t('small-change', 'Your basket is empty.'));
        }

        $carts->removeProduct($cart, (int) $this->request->getRequiredBodyParam('productId'));

        return $this->saved($cart, Craft::t('small-change', 'The item was removed.'));
    }

    public function actionApplyDiscount(): ?Response
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart();

        if ($cart === null || !$cart->getLineItems()) {
            return $this->failure(Craft::t('small-change', 'Add something to your basket first.'), ['discountCode' => [Craft::t('small-change', 'Add something to your basket first.')]]);
        }

        $error = $carts->applyDiscount($cart, (string) $this->request->getRequiredBodyParam('discountCode'));

        if ($error !== null) {
            return $this->failure($error, ['discountCode' => [$error]]);
        }

        return $this->saved($cart, Craft::t('small-change', 'Your code was applied.'));
    }

    public function actionRemoveDiscount(): ?Response
    {
        $carts = Plugin::getInstance()->carts;
        $cart = $carts->getCart();

        if ($cart === null) {
            return $this->asSuccess(Craft::t('small-change', 'Your basket is empty.'));
        }

        $carts->removeDiscount($cart);

        return $this->saved($cart, Craft::t('small-change', 'The code was removed.'));
    }

    /**
     * @param array<string, string[]> $errors
     */
    private function failure(string $message, array $errors): ?Response
    {
        $cart = Plugin::getInstance()->carts->getCart();

        return $this->asFailure($message, ['errors' => $errors], $cart ? ['cart' => $cart] : []);
    }

    private function saved(Order $cart, string $message): Response
    {
        $notices = Plugin::getInstance()->carts->save($cart);

        return $this->asSuccess($message, [
            'cart' => Plugin::getInstance()->carts->summary($cart),
            'notices' => $notices,
        ], $this->getPostedRedirectUrl($cart));
    }

    /**
     * A typed amount wins over a chosen suggestion, as on the donation form.
     */
    private function postedAmount(): mixed
    {
        $custom = $this->request->getBodyParam('amount');

        return is_string($custom) && trim($custom) !== '' ? $custom : $this->request->getBodyParam('suggestedAmount');
    }
}
