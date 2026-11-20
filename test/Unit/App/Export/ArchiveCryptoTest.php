<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Export;

use Amp\PHPUnit\AsyncTestCase;
use InvalidArgumentException;
use OpenCCK\App\Export\ArchiveCrypto;

/**
 * spec: brief/operations.md#экспорт-и-импорт-агента — the archive of an agent opens only with the owner's password
 */
final class ArchiveCryptoTest extends AsyncTestCase {
    private string $dir = '';

    protected function setUp(): void {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/archive-crypto-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void {
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testAnArchiveOpensWithItsPasswordOnly(): void {
        $plain = random_bytes(3 * 1024 * 1024 + 17);
        file_put_contents($this->dir . '/plain', $plain);

        ArchiveCrypto::encrypt($this->dir . '/plain', $this->dir . '/sealed', 'правильный пароль 12');
        $sealed = (string) file_get_contents($this->dir . '/sealed');
        self::assertStringStartsWith(ArchiveCrypto::MAGIC, $sealed);
        self::assertStringNotContainsString(substr($plain, 0, 64), $sealed);

        ArchiveCrypto::decrypt($this->dir . '/sealed', $this->dir . '/opened', 'правильный пароль 12');
        self::assertSame($plain, file_get_contents($this->dir . '/opened'));

        try {
            ArchiveCrypto::decrypt($this->dir . '/sealed', $this->dir . '/wrong', 'другой пароль 123456');
            self::fail('a wrong password opened the archive');
        } catch (InvalidArgumentException) {
        }
        self::assertFileDoesNotExist($this->dir . '/wrong', 'nothing is left of a failed opening');
    }

    public function testACutArchiveIsRefused(): void {
        file_put_contents($this->dir . '/plain', random_bytes(2 * 1024 * 1024));
        ArchiveCrypto::encrypt($this->dir . '/plain', $this->dir . '/sealed', 'правильный пароль 12');
        $sealed = (string) file_get_contents($this->dir . '/sealed');
        file_put_contents($this->dir . '/cut', substr($sealed, 0, intdiv(strlen($sealed) * 7, 10)));

        $this->expectException(InvalidArgumentException::class);
        ArchiveCrypto::decrypt($this->dir . '/cut', $this->dir . '/opened', 'правильный пароль 12');
    }

    public function testAShortPasswordIsNotTaken(): void {
        file_put_contents($this->dir . '/plain', 'x');
        $this->expectException(InvalidArgumentException::class);
        ArchiveCrypto::encrypt($this->dir . '/plain', $this->dir . '/sealed', 'короткий');
    }
}
