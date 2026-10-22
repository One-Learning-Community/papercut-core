<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\Export\Output;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function test_accessors_return_the_constructor_values(): void
    {
        $out = new Output('abc', 'text/html; charset=UTF-8', 'html');
        self::assertSame('abc', $out->bytes());
        self::assertSame('text/html; charset=UTF-8', $out->mime());
        self::assertSame('html', $out->extension());
    }

    public function test_save_writes_bytes_and_missing_directory_throws(): void
    {
        $out = new Output('%PDF-abc', 'application/pdf', 'pdf');
        $path = sys_get_temp_dir() . '/papercut-output-' . getmypid() . '.pdf';
        try {
            $out->save($path);
            self::assertSame('%PDF-abc', file_get_contents($path));
        } finally {
            @unlink($path);
        }
        $missing = sys_get_temp_dir() . '/papercut-no-such-dir-' . getmypid();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Directory does not exist: $missing");
        $out->save($missing . '/x.pdf');
    }

    public function test_save_failure_names_the_path(): void
    {
        $out = new Output('x', 'text/plain', 'txt');
        $dir = sys_get_temp_dir();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Could not write output to "' . preg_quote($dir, '/') . '": ./');
        $out->save($dir);
    }

    public function test_stream_returns_byte_count(): void
    {
        $out = new Output('hello', 'text/plain', 'txt');
        ob_start();
        $n = $out->stream();
        $echoed = (string) ob_get_clean();
        self::assertSame(5, $n);
        self::assertSame('hello', $echoed);
        self::assertSame('hello', $out->bytes());
    }
}
