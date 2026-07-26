<?php

namespace justinholtweb\archive\services;

use justinholtweb\archive\events\RegisterWritersEvent;
use justinholtweb\archive\writers\CsvWriter;
use justinholtweb\archive\writers\JsonWriter;
use justinholtweb\archive\writers\NdjsonWriter;
use justinholtweb\archive\writers\WriterInterface;
use justinholtweb\archive\writers\XmlWriter;
use justinholtweb\archive\writers\YamlWriter;
use yii\base\Component;

/**
 * Knows which output formats Archive can produce.
 */
class WriterRegistry extends Component
{
    /**
     * @event RegisterWritersEvent Raised when writers are being loaded.
     */
    public const EVENT_REGISTER_WRITERS = 'registerWriters';

    /**
     * @var array<string, WriterInterface>|null
     */
    private ?array $writers = null;

    /**
     * @return array<string, WriterInterface>
     */
    public function all(): array
    {
        if ($this->writers === null) {
            $this->writers = $this->load();
        }

        return $this->writers;
    }

    public function get(string $format): ?WriterInterface
    {
        return $this->all()[$format] ?? null;
    }

    public function has(string $format): bool
    {
        return isset($this->all()[$format]);
    }

    /**
     * @return array<string, string> Format => label, for building a form.
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $format => $writer) {
            $options[$format] = $writer::label();
        }

        return $options;
    }

    /**
     * @return array<string, WriterInterface>
     */
    private function load(): array
    {
        $event = new RegisterWritersEvent([
            'writers' => [
                new JsonWriter(),
                new NdjsonWriter(),
                new XmlWriter(),
                new YamlWriter(),
                new CsvWriter(),
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_WRITERS, $event);

        $writers = [];

        foreach ($event->writers as $writer) {
            $writers[$writer::format()] = $writer;
        }

        return $writers;
    }
}
