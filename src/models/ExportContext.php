<?php

namespace justinholtweb\archive\models;

use craft\elements\Asset;

/**
 * The mutable state of one export run: the records collected so far, the asset files
 * queued for bundling, and anything Archive couldn't fully represent.
 *
 * Collectors, the field serializer and the writers all share this.
 */
class ExportContext
{
    /**
     * @var array<string, list<array>> Records keyed by collector key.
     */
    public array $records = [];

    /**
     * @var array<string, mixed> Site structure, when schema export is enabled.
     */
    public array $schema = [];

    /**
     * @var array<string, mixed> Bundle metadata — generator, source site, format. Set by
     *      the export service before the writers run, and repeated in manifest.json.
     */
    public array $meta = [];

    /**
     * @var string[] Anything that couldn't be represented losslessly.
     */
    public array $warnings = [];

    /**
     * @var array<string, array{asset: Asset, path: string}> Files to copy into the bundle,
     *      keyed by asset UID so an asset referenced ten times is only copied once.
     */
    public array $queuedFiles = [];

    /**
     * @var array{included: int, referenced: int, skipped: int, bytes: int}
     */
    public array $assetStats = [
        'included' => 0,
        'referenced' => 0,
        'skipped' => 0,
        'bytes' => 0,
    ];

    public function __construct(
        public ExportConfig $config,
    ) {
    }

    public function addRecord(string $type, array $record): void
    {
        $this->records[$type][] = $record;
    }

    /**
     * Records a problem. Duplicates collapse, so one unsupported field type doesn't
     * produce ten thousand identical lines.
     */
    public function warn(string $message): void
    {
        if (!in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * @return array<string, int> Record count per type.
     */
    public function counts(): array
    {
        return array_map('count', $this->records);
    }

    public function totalRecords(): int
    {
        return array_sum($this->counts());
    }
}
