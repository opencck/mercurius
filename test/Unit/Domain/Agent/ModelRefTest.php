<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Domain\Agent;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;
use PHPUnit\Framework\TestCase;

final class ModelRefTest extends TestCase {
    public function testPlainIdMeansOpenRouter(): void {
        $ref = ModelRef::parse('z-ai/glm-5.3-flash');

        self::assertSame('openrouter', $ref->provider);
        self::assertSame('z-ai/glm-5.3-flash', $ref->model);
        self::assertSame('openrouter:z-ai/glm-5.3-flash', (string) $ref);
    }

    public function testProviderPrefix(): void {
        $ref = ModelRef::parse('anthropic:claude-opus-5');

        self::assertSame('anthropic', $ref->provider);
        self::assertSame('claude-opus-5', $ref->model);
    }

    public function testFreeVariantSuffixIsPartOfTheModel(): void {
        self::assertSame('x/y:free', ModelRef::parse('openrouter:x/y:free')->model);
        self::assertSame('openrouter', ModelRef::parse('x/y:free')->provider);
    }

    public function testEmptyModelIsRefused(): void {
        $this->expectException(InvalidArgumentException::class);
        ModelRef::parse('openrouter:');
    }
}
