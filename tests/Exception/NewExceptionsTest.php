<?php

declare(strict_types=1);

namespace Quoin\Tests\Exception;

use Quoin\Exception\{MissingExporter, UnsupportedFeature};
use Quoin\Export\{FidelityLevel, FidelityReport};
use Quoin\QuoinException;
use PHPUnit\Framework\TestCase;

final class NewExceptionsTest extends TestCase
{
    public function test_missing_exporter_message(): void
    {
        $e = MissingExporter::for('docx', 'quoin/docx');
        self::assertSame('DOCX export needs quoin/docx: run "composer require quoin/docx".', $e->getMessage());
        self::assertInstanceOf(QuoinException::class, $e);
    }

    public function test_unsupported_feature_carries_its_report(): void
    {
        $r = new FidelityReport(FidelityLevel::Dropped, 'docx', 'radius', 'page > row[2]');
        $e = UnsupportedFeature::from($r);
        self::assertSame('Strict mode: docx cannot render radius at page > row[2].', $e->getMessage());
        self::assertSame($r, $e->report);
        self::assertInstanceOf(QuoinException::class, $e);
    }
}
