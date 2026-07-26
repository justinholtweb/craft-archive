<?php

namespace justinholtweb\archive\tests\unit;

use justinholtweb\archive\helpers\Flattener;
use PHPUnit\Framework\TestCase;

/**
 * The flattener decides what a CSV bundle actually looks like, so its rules are worth
 * pinning down.
 */
class FlattenerTest extends TestCase
{
    public function testNestedKeysBecomeDottedColumns(): void
    {
        $flat = Flattener::flatten([
            'container' => ['section' => 'news', 'entryType' => 'article'],
            'title' => 'Hello',
        ]);

        $this->assertSame('news', $flat['container.section']);
        $this->assertSame('article', $flat['container.entryType']);
        $this->assertSame('Hello', $flat['title']);
    }

    public function testListsOfScalarsJoinIntoOneCell(): void
    {
        $flat = Flattener::flatten(['groups' => ['authors', 'editors']]);

        $this->assertSame('authors|editors', $flat['groups']);
    }

    public function testListsOfStructuresKeepTheirJson(): void
    {
        $flat = Flattener::flatten(['rows' => [['a' => 1], ['a' => 2]]]);

        $this->assertJson($flat['rows']);
        $this->assertSame([['a' => 1], ['a' => 2]], json_decode($flat['rows'], true));
    }

    public function testEmptyArraysBecomeNullRatherThanAnEmptyString(): void
    {
        $flat = Flattener::flatten(['tags' => []]);

        $this->assertNull($flat['tags']);
    }

    public function testBooleansInJoinedCellsReadAsWords(): void
    {
        $flat = Flattener::flatten(['flags' => [true, false]]);

        $this->assertSame('true|false', $flat['flags']);
    }

    public function testRelationFieldsCollapseToTargetUids(): void
    {
        $flat = Flattener::record([
            'uid' => 'record-uid',
            'fields' => [
                'related' => [
                    'kind' => 'relation',
                    'value' => [
                        ['uid' => 'first-uid', 'title' => 'First'],
                        ['uid' => 'second-uid', 'title' => 'Second'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('first-uid|second-uid', $flat['fields.related']);
    }

    public function testOptionFieldsCollapseToTheirValue(): void
    {
        $flat = Flattener::record([
            'uid' => 'record-uid',
            'fields' => [
                'colour' => ['kind' => 'option', 'value' => ['value' => 'red', 'label' => 'Red']],
                'sizes' => [
                    'kind' => 'options',
                    'value' => [['value' => 's', 'label' => 'Small'], ['value' => 'm', 'label' => 'Medium']],
                ],
            ],
        ]);

        $this->assertSame('red', $flat['fields.colour']);
        $this->assertSame('s|m', $flat['fields.sizes']);
    }

    public function testBlocksKeepTheirJsonSinceASpreadsheetCannotNest(): void
    {
        $flat = Flattener::record([
            'uid' => 'record-uid',
            'fields' => [
                'blocks' => [
                    'kind' => 'blocks',
                    'value' => [['uid' => 'block-uid', 'type' => 'text', 'fields' => []]],
                ],
            ],
        ]);

        $this->assertJson($flat['fields.blocks']);
        $this->assertStringContainsString('block-uid', $flat['fields.blocks']);
    }

    public function testScalarFieldValuesArePassedThroughUntouched(): void
    {
        $flat = Flattener::record([
            'uid' => 'record-uid',
            'fields' => [
                'body' => ['kind' => 'richText', 'value' => '<p>Hello</p>'],
                'count' => ['kind' => 'number', 'value' => 42],
                'live' => ['kind' => 'boolean', 'value' => true],
                'empty' => ['kind' => 'text', 'value' => null],
            ],
        ]);

        $this->assertSame('<p>Hello</p>', $flat['fields.body']);
        $this->assertSame(42, $flat['fields.count']);
        $this->assertTrue($flat['fields.live']);
        $this->assertNull($flat['fields.empty']);
    }

    public function testRelationsAreLeftOutOfTheRecordRowSinceTheyHaveTheirOwnFile(): void
    {
        $flat = Flattener::record([
            'uid' => 'record-uid',
            'relations' => [['field' => 'related', 'targets' => [['uid' => 'x']]]],
        ]);

        $this->assertArrayNotHasKey('relations', $flat);
        $this->assertArrayNotHasKey('relations.0.field', $flat);
    }
}
