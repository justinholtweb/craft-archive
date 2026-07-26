<?php

namespace justinholtweb\archive\tests\unit;

use justinholtweb\archive\models\RecordStore;
use PHPUnit\Framework\TestCase;

/**
 * The record store is what keeps exports flat in memory, so its reading, counting and
 * cleanup all need to hold up.
 */
class RecordStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/archive-store-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testRecordsComeBackInTheOrderTheyWentIn(): void
    {
        $store = new RecordStore($this->dir);

        foreach (range(1, 5) as $i) {
            $store->add('entries', ['uid' => "uid-$i", 'title' => "Entry $i"]);
        }

        $titles = array_column(iterator_to_array($store->each('entries')), 'title');

        $this->assertSame(['Entry 1', 'Entry 2', 'Entry 3', 'Entry 4', 'Entry 5'], $titles);

        $store->cleanup();
    }

    public function testCountsAreTrackedPerTypeInFirstSeenOrder(): void
    {
        $store = new RecordStore($this->dir);

        $store->add('entries', ['uid' => 'a']);
        $store->add('assets', ['uid' => 'b']);
        $store->add('entries', ['uid' => 'c']);

        $this->assertSame(['entries' => 2, 'assets' => 1], $store->counts());
        $this->assertSame(['entries', 'assets'], $store->types());
        $this->assertSame(3, $store->total());
        $this->assertSame(2, $store->count('entries'));

        $store->cleanup();
    }

    public function testRegisteringATypeKeepsItVisibleAtZero(): void
    {
        $store = new RecordStore($this->dir);

        $store->register('tags');

        $this->assertSame(['tags' => 0], $store->counts());
        $this->assertSame([], iterator_to_array($store->each('tags')));

        $store->cleanup();
    }

    public function testValuesContainingNewlinesSurviveTheLineBasedFormat(): void
    {
        $store = new RecordStore($this->dir);

        $html = "<p>First line</p>\n<p>Second line</p>";
        $store->add('entries', ['uid' => 'a', 'body' => $html]);
        $store->add('entries', ['uid' => 'b', 'body' => 'plain']);

        $records = iterator_to_array($store->each('entries'));

        $this->assertCount(2, $records);
        $this->assertSame($html, $records[0]['body']);

        $store->cleanup();
    }

    public function testTheStoreCanBeWalkedMoreThanOnce(): void
    {
        $store = new RecordStore($this->dir);

        $store->add('entries', ['uid' => 'a']);
        $store->add('entries', ['uid' => 'b']);

        // The CSV writer depends on this: one pass for columns, one for rows.
        $first = iterator_to_array($store->each('entries'));
        $second = iterator_to_array($store->each('entries'));

        $this->assertSame($first, $second);

        $store->cleanup();
    }

    public function testEachOfAllPairsRecordsWithTheirType(): void
    {
        $store = new RecordStore($this->dir);

        $store->add('entries', ['uid' => 'a']);
        $store->add('assets', ['uid' => 'b']);

        $pairs = iterator_to_array($store->eachOfAll());

        $this->assertSame(['entries', ['uid' => 'a']], $pairs[0]);
        $this->assertSame(['assets', ['uid' => 'b']], $pairs[1]);

        $store->cleanup();
    }

    public function testTypeNamesAreNotAllowedToEscapeTheSpoolDirectory(): void
    {
        $store = new RecordStore($this->dir);

        $store->add('../../escape', ['uid' => 'a']);

        $files = array_map('basename', glob($this->dir . '/*') ?: []);

        $this->assertSame(['_escape.ndjson'], $files);

        $store->cleanup();
    }

    public function testCleanupRemovesTheScratchDirectory(): void
    {
        $store = new RecordStore($this->dir);
        $store->add('entries', ['uid' => 'a']);

        $this->assertDirectoryExists($this->dir);

        $store->cleanup();

        $this->assertDirectoryDoesNotExist($this->dir);
    }
}
