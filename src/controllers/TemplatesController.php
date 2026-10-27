<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\controllers;

use coyshdigital\smallchange\models\InstallResult;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Templates;
use Craft;
use craft\web\Controller;
use InvalidArgumentException;
use yii\web\Response;

/**
 * Installs the example templates into the site's templates folder. Admin-only, and only where admin changes are
 * allowed, since it writes files a developer would normally add through version control.
 */
final class TemplatesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $templates = Plugin::getInstance()->templates;
        $layout = $templates->suggestLayout();

        return $this->page([
            'sets' => ['donations'],
            'layoutMode' => $layout ? 'own' : 'simple',
            'layout' => $layout,
            'block' => Templates::DEFAULT_BLOCK,
            'folder' => '',
            'overwrite' => false,
        ]);
    }

    public function actionInstall(): Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $values = [
            'sets' => array_values(array_filter((array) $request->getBodyParam('sets', []), 'is_string')),
            'layoutMode' => $request->getBodyParam('layoutMode') === 'simple' ? 'simple' : 'own',
            'layout' => trim((string) $request->getBodyParam('layout', '')),
            'block' => trim((string) $request->getBodyParam('block', '')) ?: Templates::DEFAULT_BLOCK,
            'folder' => trim((string) $request->getBodyParam('folder', '')),
            'overwrite' => (bool) $request->getBodyParam('overwrite'),
        ];

        try {
            if ($values['layoutMode'] === 'own' && $values['layout'] === '') {
                throw new InvalidArgumentException(Craft::t('small-change', 'Enter the template path of your layout, or choose the plain layout.'));
            }

            $result = Plugin::getInstance()->templates->install(
                $values['sets'],
                $values['layoutMode'] === 'own' ? $values['layout'] : null,
                $values['block'],
                $values['overwrite'],
                null,
                $values['folder'],
            );
        } catch (InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());

            return $this->page($values);
        }

        $this->setSuccessFlash(Craft::t('small-change', 'Templates installed: {count} written, {skipped} left as they were.', [
            'count' => $result->written(),
            'skipped' => count($result->skipped),
        ]));

        return $this->page($values, $result);
    }

    /**
     * @param array<string, mixed> $values what the form shows
     */
    private function page(array $values, ?InstallResult $result = null): Response
    {
        $templates = Plugin::getInstance()->templates;
        $statuses = [];

        foreach ($templates->setHandles() as $handle) {
            try {
                $statuses[$handle] = $templates->status($handle, null, (string) ($values['folder'] ?? ''));
            } catch (InvalidArgumentException) {
                // A bad folder was already reported when the install was refused.
                $statuses[$handle] = $templates->status($handle);
            }
        }

        return $this->renderTemplate('small-change/templates/index', [
            'tabs' => SettingsController::tabs(false, false),
            'selectedTab' => 'templates',
            'sets' => $templates->sets(),
            'statuses' => $statuses,
            'values' => $values,
            'result' => $result,
            'templatesFolder' => Craft::$app->getPath()->getSiteTemplatesPath(),
        ]);
    }
}
