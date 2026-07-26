<?php

namespace justinholtweb\archive\tests\unit;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\archive\helpers\ValueHelper;
use PHPUnit\Framework\TestCase;

/**
 * ValueHelper is the last thing standing between a field value and the bundle, so it has
 * to cope with whatever a field hands it.
 */
class ValueHelperTest extends TestCase
{
    public function testScalarsAndNullPassThrough(): void
    {
        $this->assertSame('text', ValueHelper::jsonSafe('text'));
        $this->assertSame(42, ValueHelper::jsonSafe(42));
        $this->assertSame(1.5, ValueHelper::jsonSafe(1.5));
        $this->assertTrue(ValueHelper::jsonSafe(true));
        $this->assertNull(ValueHelper::jsonSafe(null));
    }

    public function testDatesBecomeIso8601(): void
    {
        $date = new DateTimeImmutable('2026-07-26 14:02:33', new DateTimeZone('UTC'));

        $this->assertSame('2026-07-26T14:02:33+00:00', ValueHelper::jsonSafe($date));
        $this->assertSame('2026-07-26T14:02:33+00:00', ValueHelper::date($date));
        $this->assertNull(ValueHelper::date(null));
    }

    public function testNestedStructuresAreConvertedThroughout(): void
    {
        $result = ValueHelper::jsonSafe([
            'when' => new DateTime('2026-01-01 00:00:00', new DateTimeZone('UTC')),
            'nested' => ['deeper' => [new DateTime('2026-02-02 00:00:00', new DateTimeZone('UTC'))]],
        ]);

        $this->assertSame('2026-01-01T00:00:00+00:00', $result['when']);
        $this->assertSame('2026-02-02T00:00:00+00:00', $result['nested']['deeper'][0]);
    }

    public function testObjectsWithToStringAreStringified(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return 'stringified';
            }
        };

        $this->assertSame('stringified', ValueHelper::jsonSafe($stringable));
    }

    public function testPlainObjectsFallBackToTheirPublicProperties(): void
    {
        $object = new class {
            public string $name = 'value';
            private string $hidden = 'secret';
        };

        $this->assertSame(['name' => 'value'], ValueHelper::jsonSafe($object));
    }

    public function testResourcesBecomeNullRatherThanBreakingTheEncode(): void
    {
        $handle = fopen('php://memory', 'r');

        $this->assertNull(ValueHelper::jsonSafe($handle));

        fclose($handle);
    }

    public function testSelfReferencingStructuresTerminate(): void
    {
        $node = ['name' => 'root'];
        $node['self'] = &$node;

        // The depth guard is what stops this recursing forever.
        $result = ValueHelper::jsonSafe($node);

        $this->assertSame('root', $result['name']);
        $this->assertNotFalse(json_encode($result));
    }

    public function testCompactDropsNullsAndEmptyArraysButKeepsFalseAndZero(): void
    {
        $result = ValueHelper::compact([
            'kept' => 'value',
            'zero' => 0,
            'false' => false,
            'empty' => '',
            'null' => null,
            'emptyArray' => [],
        ]);

        $this->assertArrayHasKey('kept', $result);
        $this->assertArrayHasKey('zero', $result);
        $this->assertArrayHasKey('false', $result);
        $this->assertArrayHasKey('empty', $result);
        $this->assertArrayNotHasKey('null', $result);
        $this->assertArrayNotHasKey('emptyArray', $result);
    }
}
