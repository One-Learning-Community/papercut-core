<?php

declare(strict_types=1);

namespace Papercut\Tests\Contract;

use Papercut\Backend\PhpPdf\PhpPdfBackend;
use Papercut\Tests\Support\FakeBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class ContractGuardTest extends TestCase
{
    public function test_non_pdf_backend_is_reported_as_non_pdf(): void
    {
        self::assertFalse(BackendContractTestCase::producesPdf(new FakeBackend()));
    }

    #[RequiresPhpExtension('gd')]
    public function test_php_pdf_backend_is_reported_as_pdf(): void
    {
        self::assertTrue(BackendContractTestCase::producesPdf(new PhpPdfBackend()));
    }
}
