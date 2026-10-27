<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\Plugin;
use craft\web\Controller;
use yii\web\Response;

final class ProductsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_PRODUCTS);

        return $this->renderTemplate('small-change/products/index', [
            'productTypes' => Plugin::getInstance()->productTypes->getAllProductTypes(),
        ]);
    }
}
