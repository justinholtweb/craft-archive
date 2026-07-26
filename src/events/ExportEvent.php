<?php

namespace justinholtweb\archive\events;

use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\records\BundleRecord;
use yii\base\ModelEvent;

/**
 * Raised before and after an export. Handlers on the before-event can cancel the run by
 * setting `$event->isValid = false`, or amend the context once it's populated.
 */
class ExportEvent extends ModelEvent
{
    public ExportConfig $config;

    public ExportContext $context;

    /**
     * The ledger row, on the after-event only.
     */
    public ?BundleRecord $bundle = null;
}
