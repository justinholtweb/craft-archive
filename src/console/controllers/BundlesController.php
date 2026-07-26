<?php

namespace justinholtweb\archive\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\archive\Plugin;
use yii\console\ExitCode;

/**
 * Manages bundles from the command line.
 *
 *     php craft archive/bundles          list them
 *     php craft archive/bundles/prune    apply the retention settings
 *     php craft archive/bundles/delete 4 delete one
 */
class BundlesController extends Controller
{
    public $defaultAction = 'list';

    /**
     * Lists the bundles Archive has produced.
     */
    public function actionList(): int
    {
        $plugin = Plugin::getInstance();
        $bundles = $plugin->bundles->getAll();

        if (!$bundles) {
            $this->stdout("No bundles yet.\n");

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-5s %-32s %-8s %-10s %-12s %s\n", 'ID', 'NAME', 'FORMAT', 'STATUS', 'SIZE', 'CREATED'));

        foreach ($bundles as $bundle) {
            $onDisk = $plugin->bundles->exists($bundle);

            $this->stdout(sprintf(
                "%-5s %-32s %-8s %-10s %-12s %s%s\n",
                $bundle->id,
                mb_strimwidth($bundle->name, 0, 32, '…'),
                $bundle->format,
                $bundle->status,
                $bundle->size ? $this->humanSize((int)$bundle->size) : '—',
                $bundle->dateCreated,
                $onDisk ? '' : '  (file missing)'
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Deletes bundles that have aged out, honouring the retention settings.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->export->prune();

        $this->stdout("Pruned $deleted bundle(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Deletes one bundle and its file.
     */
    public function actionDelete(int $id): int
    {
        $bundles = Plugin::getInstance()->bundles;
        $bundle = $bundles->getById($id);

        if ($bundle === null) {
            $this->stderr("No bundle with ID $id.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $name = $bundle->name;
        $bundles->delete($bundle);

        $this->stdout("Deleted $name.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int)floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }
}
