<?php

namespace justinholtweb\archive\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\archive\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Listing, downloading and deleting finished bundles.
 */
class BundlesController extends Controller
{
    public function actionIndex(): Response
    {
        $bundles = Plugin::getInstance()->bundles;
        $all = $bundles->getAll();

        $onDisk = [];
        foreach ($all as $bundle) {
            $onDisk[$bundle->id] = $bundles->exists($bundle);
        }

        return $this->renderTemplate('archive/bundles/index', [
            'bundles' => $all,
            'onDisk' => $onDisk,
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    public function actionDetail(int $id): Response
    {
        $bundles = Plugin::getInstance()->bundles;
        $bundle = $bundles->getById($id);

        if ($bundle === null) {
            throw new NotFoundHttpException('Bundle not found.');
        }

        return $this->renderTemplate('archive/bundles/detail', [
            'bundle' => $bundle,
            'exists' => $bundles->exists($bundle),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    public function actionDownload(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $id = (int)Craft::$app->getRequest()->getRequiredParam('id');
        $bundles = Plugin::getInstance()->bundles;
        $bundle = $bundles->getById($id);

        if ($bundle === null) {
            throw new NotFoundHttpException('Bundle not found.');
        }

        $path = $bundles->path($bundle);

        if ($path === null) {
            throw new NotFoundHttpException('That bundle’s file is no longer on disk.');
        }

        return Craft::$app->getResponse()->sendFile($path, $bundle->filename, [
            'mimeType' => 'application/zip',
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $bundles = Plugin::getInstance()->bundles;
        $bundle = $bundles->getById($id);

        if ($bundle === null) {
            throw new NotFoundHttpException('Bundle not found.');
        }

        $bundles->delete($bundle);

        Craft::$app->getSession()->setNotice(Craft::t('archive', 'Bundle deleted.'));

        return $this->redirectToPostedUrl();
    }
}
