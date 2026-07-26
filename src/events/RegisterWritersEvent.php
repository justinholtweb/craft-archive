<?php

namespace justinholtweb\archive\events;

use justinholtweb\archive\writers\WriterInterface;
use yii\base\Event;

/**
 * Raised so plugins can add their own output formats.
 */
class RegisterWritersEvent extends Event
{
    /**
     * @var WriterInterface[]
     */
    public array $writers = [];
}
