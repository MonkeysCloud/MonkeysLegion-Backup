<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PlaceholderTest extends TestCase
{
    public function testPlaceholder(): void
    {
        $value = $this->getValue();
        $this->assertTrue($value);
    }

    private function getValue(): bool
    {
        return true;
    }
}
