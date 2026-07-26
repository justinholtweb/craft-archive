<?php

namespace justinholtweb\archive\events;

use justinholtweb\archive\fields\ValueSerializerInterface;
use yii\base\Event;

/**
 * Raised so plugins can teach Archive how to make their own field types portable.
 */
class RegisterValueSerializersEvent extends Event
{
    /**
     * @var ValueSerializerInterface[]
     */
    public array $serializers = [];
}
