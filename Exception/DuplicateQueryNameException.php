<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Exception;

/**
 * Thrown when a saved query name collides with an existing one within the same visibility scope.
 */
class DuplicateQueryNameException extends \RuntimeException
{
}
