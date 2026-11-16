<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use OpenCCK\App\Agent\Tool\JsonSchema;
use PHPUnit\Framework\TestCase;

final class JsonSchemaTest extends TestCase {
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'maxLength' => 10],
            'method' => ['type' => 'string', 'enum' => ['GET', 'HEAD']],
            'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'ratio' => ['type' => 'number'],
            'flag' => ['type' => 'boolean'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'meta' => ['type' => 'object'],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ];

    public function testValidValuePasses(): void {
        self::assertSame([], JsonSchema::validate(self::SCHEMA, ['url' => 'x', 'method' => 'GET', 'count' => 2, 'ratio' => 1, 'flag' => true, 'tags' => ['a'], 'meta' => []]));
    }

    public function testRequired(): void {
        self::assertSame(['url: обязательное поле'], JsonSchema::validate(self::SCHEMA, []));
    }

    public function testType(): void {
        self::assertSame(['url: ожидается string'], JsonSchema::validate(self::SCHEMA, ['url' => 5]));
    }

    public function testIntegerIsNotNumberWithFraction(): void {
        self::assertSame(['count: ожидается integer'], JsonSchema::validate(self::SCHEMA, ['url' => 'x', 'count' => 1.5]));
    }

    public function testEnum(): void {
        self::assertSame(['method: допустимо одно из GET, HEAD'], JsonSchema::validate(self::SCHEMA, ['url' => 'x', 'method' => 'POST']));
    }

    public function testMinimumAndMaximum(): void {
        self::assertSame(['count: не меньше 1'], JsonSchema::validate(self::SCHEMA, ['url' => 'x', 'count' => 0]));
        self::assertSame(['count: не больше 5'], JsonSchema::validate(self::SCHEMA, ['url' => 'x', 'count' => 6]));
    }

    public function testMaxLength(): void {
        self::assertSame(['url: не длиннее 10 символов'], JsonSchema::validate(self::SCHEMA, ['url' => 'абвгдеёжзий']));
    }

    public function testItems(): void {
        self::assertSame(['tags[1]: ожидается string'], JsonSchema::validate(self::SCHEMA, ['url' => 'x', 'tags' => ['a', 1]]));
    }

    public function testAdditionalProperties(): void {
        self::assertSame(['extra: неизвестное поле'], JsonSchema::validate(self::SCHEMA, ['url' => 'x', 'extra' => 1]));
    }

    public function testObjectMustBeMap(): void {
        self::assertSame(['ожидается object'], JsonSchema::validate(self::SCHEMA, ['a', 'b']));
    }
}
