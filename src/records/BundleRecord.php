<?php

namespace justinholtweb\archive\records;

use craft\db\ActiveRecord;
use justinholtweb\archive\migrations\Install;

/**
 * A bundle Archive has produced (or tried to).
 *
 * @property int $id
 * @property string $name
 * @property string|null $filename
 * @property string $format
 * @property string $status
 * @property int|null $size
 * @property string|null $counts JSON
 * @property string|null $config JSON
 * @property string|null $warnings JSON
 * @property string|null $error
 * @property int|null $creatorId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class BundleRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return Install::TABLE_BUNDLES;
    }

    /**
     * @return array<string, int>
     */
    public function getCountsArray(): array
    {
        return $this->decode($this->counts);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigArray(): array
    {
        return $this->decode($this->config);
    }

    /**
     * @return string[]
     */
    public function getWarningsArray(): array
    {
        return array_values($this->decode($this->warnings));
    }

    /**
     * @return array<mixed>
     */
    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
