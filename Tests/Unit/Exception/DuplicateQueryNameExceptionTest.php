<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Exception;

use Aaxis\Bundle\DevToolsBundle\Exception\DuplicateQueryNameException;
use PHPUnit\Framework\TestCase;

class DuplicateQueryNameExceptionTest extends TestCase
{
    public function testIsARuntimeExceptionSoGenericCatchBlocksKeepWorking(): void
    {
        $e = new DuplicateQueryNameException('taken');

        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame('taken', $e->getMessage());
    }
}
