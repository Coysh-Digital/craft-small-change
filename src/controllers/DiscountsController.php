<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\helpers\Money;
use coyshdigital\smallchange\models\Discount;
use coyshdigital\smallchange\Plugin;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class DiscountsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_DISCOUNTS);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('small-change/discounts/index', [
            'discounts' => Plugin::getInstance()->discounts->getAllDiscounts(),
        ]);
    }

    public function actionEdit(?int $discountId = null, ?Discount $discount = null): Response
    {
        if ($discount === null) {
            if ($discountId !== null) {
                $discount = Plugin::getInstance()->discounts->getDiscountById($discountId);

                if (!$discount) {
                    throw new NotFoundHttpException('Discount not found');
                }
            } else {
                $discount = new Discount();
            }
        }

        $currency = Plugin::getInstance()->getSettings()->currency;

        return $this->renderTemplate('small-change/discounts/_edit', [
            'discount' => $discount,
            'currency' => $currency,
            'title' => $discount->id ? $discount->name : Craft::t('small-change', 'New discount code'),
            // Form values in the units people type: percent, and money in the store currency.
            'percentValue' => $discount->percent !== null ? rtrim(rtrim(number_format($discount->percent / 100, 2, '.', ''), '0'), '.') : '',
            'amountValue' => $discount->amount !== null ? Money::toDecimal($discount->amount, $currency) : '',
            'minSubtotalValue' => $discount->minSubtotal !== null ? Money::toDecimal($discount->minSubtotal, $currency) : '',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $service = Plugin::getInstance()->discounts;
        $currency = Plugin::getInstance()->getSettings()->currency;
        $id = $this->request->getBodyParam('discountId');
        $discount = $id ? $service->getDiscountById((int) $id) : new Discount();

        if (!$discount) {
            throw new BadRequestHttpException("Invalid discount ID: $id");
        }

        $parseErrors = [];

        $discount->name = trim((string) $this->request->getBodyParam('name'));
        $discount->code = (string) $this->request->getBodyParam('code');
        $discount->type = (string) $this->request->getBodyParam('type', Discount::TYPE_PERCENT);
        $discount->enabled = (bool) $this->request->getBodyParam('enabled');

        $percent = trim((string) $this->request->getBodyParam('percent'));
        $discount->percent = null;
        if ($percent !== '') {
            if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $percent) === 1) {
                $discount->percent = (int) round((float) $percent * 100);
            } else {
                $parseErrors['percent'] = Craft::t('small-change', 'Enter a percentage such as 10 or 12.5.');
            }
        }

        foreach (['amount' => 'amount', 'minSubtotal' => 'minSubtotal'] as $param => $attribute) {
            $value = trim((string) $this->request->getBodyParam($param));
            $discount->$attribute = null;

            if ($value === '') {
                continue;
            }

            $minor = Money::parse($value, $currency);

            if ($minor === null) {
                $parseErrors[$attribute] = Craft::t('small-change', 'Enter an amount such as 5 or 2.50.');
            } else {
                $discount->$attribute = $minor;
            }
        }

        $maxUses = trim((string) $this->request->getBodyParam('maxUses'));
        $discount->maxUses = $maxUses === '' ? null : (ctype_digit($maxUses) ? (int) $maxUses : 0);

        foreach (['dateFrom', 'dateTo'] as $attribute) {
            $date = $this->request->getBodyParam($attribute);
            $discount->$attribute = $date ? DateTimeHelper::toDateTime($date) ?: null : null;
        }

        $valid = $discount->validate();

        foreach ($parseErrors as $attribute => $message) {
            $discount->addError($attribute, $message);
            $valid = false;
        }

        if (!$valid || !$service->saveDiscount($discount, false)) {
            return $this->asModelFailure($discount, Craft::t('small-change', "Couldn't save the discount."), 'discount');
        }

        $this->setSuccessFlash(Craft::t('small-change', 'Discount saved.'));

        return $this->redirectToPostedUrl($discount);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $service = Plugin::getInstance()->discounts;
        $discount = $service->getDiscountById((int) $this->request->getRequiredBodyParam('id'));

        if (!$discount) {
            throw new BadRequestHttpException('Invalid discount ID');
        }

        $service->deleteDiscount($discount);

        return $this->asSuccess();
    }
}
