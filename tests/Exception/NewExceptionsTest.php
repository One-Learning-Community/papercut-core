<?php

declare(strict_types=1);

namespace Papercut\Tests\Exception;

use Papercut\Exception\{MissingExporter, UnsupportedFeature};
use Papercut\Export\{FidelityLevel, FidelityReport};
use Papercut\PapercutException;
use PHPUnit\Framework\TestCase;

final class NewExceptionsTest extends TestCase
{
    public function test_missing_exporter_message(): void
    {
        $e = MissingExporter::for('docx', 'papercut/docx');
        self::assertSame('DOCX export needs papercut/docx: run "composer require papercut/docx".', $e->getMessage());
        self::assertInstanceOf(PapercutException::class, $e);
    }

    public function test_unsupported_feature_carries_its_report(): void
    {
        $r = new FidelityReport(FidelityLevel::Dropped, 'docx', 'radius', 'page > row[2]');
        $e = UnsupportedFeature::from($r);
        self::assertSame('Strict mode: docx cannot render radius at page > row[2].', $e->getMessage());
        self::assertSame($r, $e->report);
        self::assertInstanceOf(PapercutException::class, $e);
    }
}
