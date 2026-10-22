<?php

declare(strict_types=1);

namespace Papercut\Tests\Contract;

use Papercut\Backend\Backend;
use Papercut\Backend\PhpPdf\PhpPdfBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('gd')]
final class PhpPdfBackendContractTest extends BackendContractTestCase
{
    protected function backend(): Backend
    {
        return new PhpPdfBackend();
    }
}
