<?php

namespace justinholtweb\archive\collectors;

use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;

/**
 * Turns one Craft element type into bundle records.
 *
 * Register your own with {@see \justinholtweb\archive\services\CollectorRegistry::EVENT_REGISTER_COLLECTORS}
 * to teach Archive about an element type it doesn't ship support for.
 */
interface CollectorInterface
{
    /**
     * The key this collector's records are filed under in a bundle — 'entries',
     * 'categories', and so on. Must be unique and safe to use as a filename.
     */
    public static function key(): string;

    /**
     * Human-readable name, shown on the export screen.
     */
    public static function label(): string;

    /**
     * Whether this collector can run right now. Collectors for optional plugins should
     * return false when that plugin isn't installed.
     */
    public function isAvailable(): bool;

    /**
     * Collects records into the context.
     */
    public function collect(ExportContext $context): void;

    /**
     * Roughly how many records this collector will produce, so a queued export can show a
     * progress bar. Should count, not collect.
     */
    public function estimate(ExportConfig $config): int;
}
