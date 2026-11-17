<?php

declare(strict_types=1);

namespace Quoin\Tests\Support;

/** Makes any PHP diagnostic (warning, notice, deprecation) fail the test that raised it. */
trait FailsOnDiagnostics
{
    protected function setUp(): void
    {
        parent::setUp();
        set_error_handler(static function (int $no, string $msg, string $file, int $line): never {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }
}
