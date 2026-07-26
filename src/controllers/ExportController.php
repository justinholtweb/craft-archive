<?php

namespace justinholtweb\archive\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\Plugin;
use Throwable;
use yii\web\Response;

/**
 * The export screen.
 */
class ExportController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_EXPORT);
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderIndex(ExportConfig::fromSettings());
    }

    /**
     * Renders the export form, keeping whatever the user had chosen when a run is bounced
     * back to them.
     */
    private function renderIndex(ExportConfig $config): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('archive/export/index', [
            'config' => $config,
            'typeOptions' => $plugin->collectors->options(),
            'formatOptions' => $plugin->writers->options(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'volumes' => Craft::$app->getVolumes()->getAllVolumes(),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Runs an export and sends the user to the finished bundle.
     */
    public function actionRun(): ?Response
    {
        $this->requirePostRequest();

        $config = $this->configFromRequest();

        if (!$config->validate()) {
            Craft::$app->getSession()->setError(Craft::t('archive', 'Couldn’t start the export.'));
            return $this->renderIndex($config);
        }

        // Exports run inline for now, and a big site takes a while.
        App::maxPowerCaptain();

        try {
            $bundle = Plugin::getInstance()->export->run($config);
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError(Craft::t('archive', 'Export failed: {message}', [
                'message' => $e->getMessage(),
            ]));
            return $this->renderIndex($config);
        }

        Craft::$app->getSession()->setNotice(Craft::t('archive', 'Bundle created.'));

        return $this->redirect(UrlHelper::cpUrl('archive/bundles/' . $bundle->id));
    }

    private function configFromRequest(): ExportConfig
    {
        $request = Craft::$app->getRequest();
        $config = ExportConfig::fromSettings();

        $config->types = array_values(array_filter((array)$request->getBodyParam('types', ['entries'])));
        $config->siteHandles = array_values(array_filter((array)$request->getBodyParam('siteHandles', [])));
        $config->sectionHandles = array_values(array_filter((array)$request->getBodyParam('sectionHandles', [])));
        $config->volumeHandles = array_values(array_filter((array)$request->getBodyParam('volumeHandles', [])));
        $config->format = (string)$request->getBodyParam('format', $config->format);
        $config->includeDisabled = (bool)$request->getBodyParam('includeDisabled', false);
        $config->includeAssetFiles = (bool)$request->getBodyParam('includeAssetFiles', $config->includeAssetFiles);
        $config->downloadRemoteAssets = (bool)$request->getBodyParam('downloadRemoteAssets', $config->downloadRemoteAssets);
        $config->includeSchema = (bool)$request->getBodyParam('includeSchema', $config->includeSchema);
        $config->name = $request->getBodyParam('name') ?: null;

        $limit = $request->getBodyParam('limit');
        $config->limit = is_numeric($limit) && (int)$limit > 0 ? (int)$limit : null;

        if (!$config->types) {
            $config->types = ['entries'];
        }

        return $config;
    }
}
