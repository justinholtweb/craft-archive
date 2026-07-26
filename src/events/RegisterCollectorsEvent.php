<?php

namespace justinholtweb\archive\events;

use justinholtweb\archive\collectors\CollectorInterface;
use yii\base\Event;

/**
 * Raised so plugins can teach Archive about their own element types.
 */
class RegisterCollectorsEvent extends Event
{
    /**
     * @var CollectorInterface[]
     */
    public array $collectors = [];
}
