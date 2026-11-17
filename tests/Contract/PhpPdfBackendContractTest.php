<?php

declare(strict_types=1);

namespace Quoin\Tests\Contract;

use Quoin\Backend\Backend;
use Quoin\Backend\PhpPdf\PhpPdfBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('gd')]
final class PhpPdfBackendContractTest extends BackendContractTestCase
{
    protected function backend(): Backend
    {
        return new PhpPdfBackend();
    }
}
