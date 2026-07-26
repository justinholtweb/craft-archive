<?php

namespace justinholtweb\archive\services;

use justinholtweb\archive\collectors\CollectorInterface;
use justinholtweb\archive\collectors\EntryCollector;
use justinholtweb\archive\events\RegisterCollectorsEvent;
use yii\base\Component;

/**
 * Knows which element types Archive can export.
 */
class CollectorRegistry extends Component
{
    /**
     * @event RegisterCollectorsEvent Raised when collectors are being loaded.
     */
    public const EVENT_REGISTER_COLLECTORS = 'registerCollectors';

    /**
     * @var array<string, CollectorInterface>|null
     */
    private ?array $collectors = null;

    /**
     * Every available collector, keyed by its bundle key.
     *
     * @return array<string, CollectorInterface>
     */
    public function all(): array
    {
        if ($this->collectors === null) {
            $this->collectors = $this->load();
        }

        return $this->collectors;
    }

    public function get(string $key): ?CollectorInterface
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * @return array<string, string> Key => label, for building a form.
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $key => $collector) {
            $options[$key] = $collector::label();
        }

        return $options;
    }

    /**
     * @return array<string, CollectorInterface>
     */
    private function load(): array
    {
        $event = new RegisterCollectorsEvent([
            'collectors' => [
                new EntryCollector(),
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_COLLECTORS, $event);

        $collectors = [];

        foreach ($event->collectors as $collector) {
            if ($collector->isAvailable()) {
                $collectors[$collector::key()] = $collector;
            }
        }

        return $collectors;
    }
}
