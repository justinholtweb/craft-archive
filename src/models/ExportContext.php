<?php

namespace justinholtweb\archive\models;

use craft\elements\Asset;

/**
 * The mutable state of one export run: the records collected so far, the asset files
 * queued for bundling, and anything Archive couldn't fully represent.
 *
 * Collectors, the field serializer and the writers all share this. Records themselves live
 * in a {@see RecordStore} on disk rather than on this object, so a large export doesn't
 * grow with the size of the site.
 */
class ExportContext
{
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

    /**
     * Called after each record is collected, for progress reporting.
     *
     * @var callable(string, int): void|null
     */
    public $onRecord = null;

    public function __construct(
        public ExportConfig $config,
        public RecordStore $records,
    ) {
    }

    public function addRecord(string $type, array $record): void
    {
        $this->records->add($type, $record);

        if ($this->onRecord !== null) {
            ($this->onRecord)($type, $this->records->total());
        }
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
        return $this->records->counts();
    }

    public function totalRecords(): int
    {
        return $this->records->total();
    }
}
