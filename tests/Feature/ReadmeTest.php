<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\Tests\Support\PdfInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executes every fenced ```php block of packages/core/README.md so the guide cannot rot.
 * Convention: a block is a complete snippet (it may start with `use` lines) that leaves a
 * Papercut\Document in `$document`
 * (and optionally a PdfOptions in `$options`); the harness saves it to a temp file and validates it.
 */
final class ReadmeTest extends TestCase
{
    private const string README = __DIR__ . '/../../README.md';

    /** @return array<string, array{string}> */
    public static function blocks(): array
    {
        $md = (string) file_get_contents(self::README);
        preg_match_all('/^```php\n(.*?)^```$/ms', $md, $m);
        $out = [];
        foreach ($m[1] as $i => $code) {
            $out['block ' . ($i + 1)] = [$code];
        }
        return $out;
    }

    public function test_readme_has_a_usage_guide(): void
    {
        self::assertGreaterThanOrEqual(8, count(self::blocks()), 'README needs the full usage guide (>= 8 php blocks)');
    }

    #[DataProvider('blocks')]
    public function test_block_runs_and_renders_a_valid_pdf(string $code): void
    {
        $dir = sys_get_temp_dir() . '/papercut-readme-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $script = $dir . '/block.php';
        $pdfPath = $dir . '/out.pdf';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($script, "<?php\ndeclare(strict_types=1);\nrequire " . var_export($autoload, true) . ";\n"
            . $code . "\n\$document->toPdf(\$options ?? null)->save(" . var_export($pdfPath, true) . ");\n");

        $output = [];
        $exit = -1;
        exec(sprintf('%s -d xdebug.mode=off %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $exit);

        try {
            self::assertSame(0, $exit, implode("\n", $output) . "\n--- block ---\n" . $code);
            self::assertFileExists($pdfPath);
            PdfInspector::check((string) file_get_contents($pdfPath));
        } finally {
            @unlink($script);
            @unlink($pdfPath);
            @rmdir($dir);
        }
    }
}
