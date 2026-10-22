<?php

declare(strict_types=1);

namespace Papercut\Tests\Contract;

use Papercut\Backend\Backend;
use Papercut\Tests\Support\FakeBackend;

/** Non-PDF backend: every contract test must skip (see ContractGuardTest), never pass. */
final class FakeBackendContractTest extends BackendContractTestCase
{
    protected function backend(): Backend
    {
        return new FakeBackend();
    }
}
