<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\{Column, Document, Page, Text};
use Papercut\Exception\InvalidValue;
use Papercut\Export\{Encryption, PdfOptions};
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase
{
    private static function pdf(?Encryption $encryption): string
    {
        return Document::make(Page::a4()->body(Column::make(Text::h1('Statement'), Text::p('Account balance: 42.00'))))
            ->title('Statement')
            ->toPdf(new PdfOptions(encryption: $encryption))
            ->bytes();
    }

    /**
     * Runs a PDF tool on the bytes; $after comes after the file name.
     *
     * @param list<string> $args
     * @param list<string> $after
     * @return array{int, string}
     */
    private static function tool(string $pdf, array $args, array $after = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'pc');
        file_put_contents($file, $pdf);
        $cmd = [...array_map('escapeshellarg', $args), escapeshellarg($file), ...array_map('escapeshellarg', $after)];
        exec(implode(' ', $cmd) . ' 2>&1', $out, $code);
        unlink($file);

        return [$code, implode("\n", $out)];
    }

    private static function encryption(string $pdf, string $password): string
    {
        return self::tool($pdf, ['qpdf', '--show-encryption', "--password=$password"])[1];
    }

    public function test_the_password_is_needed_to_open_it(): void
    {
        $pdf = self::pdf(Encryption::password('open-sesame'));

        [$code, $out] = self::tool($pdf, ['pdfinfo']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Incorrect password', $out);

        [, $info] = self::tool($pdf, ['pdfinfo', '-upw', 'open-sesame']);
        $this->assertMatchesRegularExpression('/Encrypted:\s+yes .*algorithm:AES-256/', $info);
        $this->assertStringContainsString('Title:           Statement', $info);
    }

    public function test_text_survives_encryption(): void
    {
        $pdf = self::pdf(Encryption::password('open-sesame'));

        [$code, $check] = self::tool($pdf, ['qpdf', '--check', '--password=open-sesame']);
        $this->assertSame(0, $code, $check);
        [$code, $text] = self::tool($pdf, ['pdftotext', '-upw', 'open-sesame', '-enc', 'UTF-8'], ['-']);
        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('Account balance: 42.00', $text);
    }

    public function test_default_permissions_allow_printing_copying_and_screen_readers(): void
    {
        $out = self::encryption(self::pdf(Encryption::password('open-sesame')), 'open-sesame');

        $this->assertStringContainsString('R = 6', $out);
        $this->assertStringContainsString('Supplied password is user password', $out);
        foreach (['print high resolution: allowed', 'extract for any purpose: allowed', 'extract for accessibility: allowed', 'modify other: not allowed', 'modify annotations: not allowed', 'modify document assembly: not allowed'] as $line) {
            $this->assertStringContainsString($line, $out);
        }
    }

    public function test_permissions_can_be_tightened_and_loosened(): void
    {
        $out = self::encryption(self::pdf(new Encryption('pw', print: false, copy: false, modify: true, annotate: true, assemble: true)), 'pw');

        foreach (['print low resolution: not allowed', 'extract for any purpose: not allowed', 'extract for accessibility: allowed', 'modify other: allowed', 'modify annotations: allowed', 'modify document assembly: allowed'] as $line) {
            $this->assertStringContainsString($line, $out);
        }
    }

    public function test_no_user_password_opens_freely_but_keeps_the_restrictions(): void
    {
        $pdf = self::pdf(new Encryption(copy: false));

        [$code, $info] = self::tool($pdf, ['pdfinfo']);
        $this->assertSame(0, $code, $info);
        $this->assertMatchesRegularExpression('/Encrypted:\s+yes \(print:yes copy:no change:no/', $info);
    }

    public function test_the_owner_password_lifts_the_restrictions(): void
    {
        $out = self::encryption(self::pdf(new Encryption('user', 'owner', copy: false)), 'owner');

        $this->assertStringContainsString('Supplied password is owner password', $out);
    }

    public function test_without_an_owner_password_a_random_one_is_generated(): void
    {
        $a = new Encryption('user');
        $b = new Encryption('user');

        $this->assertSame(64, strlen($a->ownerPassword));
        $this->assertNotSame($a->ownerPassword, $b->ownerPassword);
        $this->assertStringContainsString('Supplied password is user password', self::encryption(self::pdf($a), 'user'));
    }

    public function test_an_owner_password_equal_to_the_user_password_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('lift the permissions');

        new Encryption('same', 'same');
    }

    public function test_a_password_longer_than_a_pdf_allows_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('at most 127 bytes');

        new Encryption(str_repeat('x', 128));
    }

    public function test_unencrypted_by_default(): void
    {
        [, $info] = self::tool(self::pdf(null), ['pdfinfo']);

        $this->assertMatchesRegularExpression('/Encrypted:\s+no/', $info);
    }

    public function test_bookmarks_still_work_when_encrypted(): void
    {
        [, $json] = self::tool(self::pdf(Encryption::password('pw')), ['qpdf', '--password=pw', '--json', '--json-key=outlines']);

        $this->assertSame('Statement', json_decode($json, true, flags: JSON_THROW_ON_ERROR)['outlines'][0]['title']);
    }
}
