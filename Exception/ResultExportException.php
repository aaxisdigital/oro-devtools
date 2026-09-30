<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Exception;

/**
 * Thrown when an export file cannot be produced (e.g. no temporary file could be created).
 */
class ResultExportException extends \RuntimeException
{
}
