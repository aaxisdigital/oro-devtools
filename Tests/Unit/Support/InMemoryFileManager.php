<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support;

use Oro\Bundle\GaufretteBundle\FileManager;

/**
 * Gaufrette FileManager kept in an array: just the calls the folder-scan store makes, with the same
 * prefix semantics (a plain string prefix over file names).
 */
final class InMemoryFileManager extends FileManager
{
    /** @var array<string, string> */
    public array $files = [];

    public function __construct()
    {
        parent::__construct('memory');
    }

    public function findFiles(string $prefix = ''): array
    {
        return array_values(array_filter(array_keys($this->files), static fn (string $name): bool => str_starts_with($name, $prefix)));
    }

    public function getFileContent(string $fileName, bool $throwException = true): ?string
    {
        return $this->files[$fileName] ?? null;
    }

    public function writeToStorage(string $content, string $fileName): void
    {
        $this->files[$fileName] = $content;
    }

    public function deleteAllFiles(string $prefix = ''): void
    {
        foreach ($this->findFiles($prefix) as $name) {
            unset($this->files[$name]);
        }
    }
}
