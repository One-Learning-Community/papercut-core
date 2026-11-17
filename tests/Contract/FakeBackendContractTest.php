<?php

declare(strict_types=1);

namespace Quoin\Tests\Contract;

use Quoin\Backend\Backend;
use Quoin\Tests\Support\FakeBackend;

/** Non-PDF backend: every contract test must skip (see ContractGuardTest), never pass. */
final class FakeBackendContractTest extends BackendContractTestCase
{
    protected function backend(): Backend
    {
        return new FakeBackend();
    }
}
