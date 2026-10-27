<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

use PHPUnit\Framework\Assert;

final class PdfInspector
{
    public static function check(string $pdf): void
    {
        [$code, $out] = self::run(['qpdf', '--check'], $pdf);
        Assert::assertSame(0, $code, "qpdf --check failed:\n" . $out);
    }

    public static function text(string $pdf): string
    {
        return self::run(['pdftotext', '-enc', 'UTF-8'], $pdf, '-')[1];
    }

    /** @return list<array{text:string,xMin:float,yMin:float,xMax:float,yMax:float}> */
    public static function words(string $pdf, int $page = 1): array
    {
        $html = self::run(['pdftotext', '-bbox', '-enc', 'UTF-8', '-f', (string) $page, '-l', (string) $page], $pdf, '-')[1];
        preg_match_all('/<word xMin="(-?[\d.]+)" yMin="(-?[\d.]+)" xMax="(-?[\d.]+)" yMax="(-?[\d.]+)">(.*?)<\/word>/u', $html, $m, PREG_SET_ORDER);
        return array_map(static fn (array $w): array => [
            'text' => html_entity_decode($w[5], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'xMin' => (float) $w[1], 'yMin' => (float) $w[2],
            'xMax' => (float) $w[3], 'yMax' => (float) $w[4],
        ], $m);
    }

    /** @return array<string,string> */
    public static function info(string $pdf): array
    {
        $out = self::run(['pdfinfo', '-enc', 'UTF-8'], $pdf)[1];
        $info = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/u', $line, $m)) {
                $info[trim($m[1])] = trim($m[2]);
            }
        }
        return $info;
    }

    public static function qdf(string $pdf): string
    {
        $in = self::tmp($pdf);
        $out = $in . '.qdf';
        try {
            exec(sprintf('qpdf --qdf --object-streams=disable %s %s 2>&1', escapeshellarg($in), escapeshellarg($out)), $o, $code);
            Assert::assertContains($code, [0, 3], 'qpdf --qdf failed: ' . implode("\n", $o));
            return (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * @param list<string> $cmd
     * @return array{0:int,1:string}
     */
    private static function run(array $cmd, string $pdf, ?string $trailingArg = null): array
    {
        $file = self::tmp($pdf);
        $parts = array_map('escapeshellarg', $cmd);
        $parts[] = escapeshellarg($file);
        if ($trailingArg !== null) {
            $parts[] = escapeshellarg($trailingArg);
        }
        exec(implode(' ', $parts) . ' 2>&1', $lines, $code);
        @unlink($file);
        return [$code, implode("\n", $lines)];
    }

    private static function tmp(string $pdf): string
    {
        // Ubuntu's AppArmor profile for qpdf only lets it open files named *.pdf, *.json or *.qdf.
        $base = tempnam(sys_get_temp_dir(), 'pc');
        Assert::assertNotFalse($base);
        $file = $base . '.pdf';
        rename($base, $file);
        file_put_contents($file, $pdf);
        return $file;
    }
}
