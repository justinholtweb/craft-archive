<?php

namespace justinholtweb\archive\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\archive\Plugin;
use yii\web\Response;

/**
 * The settings screen.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin();
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('archive/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
            'formatOptions' => Plugin::getInstance()->writers->options(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = Craft::$app->getRequest()->getBodyParam('settings', []);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('archive', 'Couldn’t save settings.'));

            return $this->renderTemplate('archive/settings/index', [
                'settings' => $plugin->getSettings(),
                'formatOptions' => $plugin->writers->options(),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('archive', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
