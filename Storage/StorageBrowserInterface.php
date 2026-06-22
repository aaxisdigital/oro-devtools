<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

/**
 * Common contract for the read-only "browser" tools (Filesystem Browser, Bucket Browser).
 *
 * Implementations read their own configuration and enforce their own safety model; the shared
 * controller and front-end component treat both the local filesystem and an S3 bucket uniformly.
 *
 * Entry shape (each item returned in "entries"):
 *   name, type ('dir'|'file'), path, size, sizeFormatted, created, modified,
 *   ownerUser, ownerGroup, readable
 */
interface StorageBrowserInterface
{
    /** Maximum number of bytes returned for a file/object preview. */
    public const int MAX_PREVIEW_BYTES = 524288;

    /**
     * The initial path/prefix to open when the page loads.
     */
    public function getStartPath(): string;

    /**
     * Lists a directory / prefix.
     *
     * @return array{path: string, parent: string|null, entries: array<int, array<string, mixed>>}
     */
    public function listDirectory(string $path): array;

    /**
     * Reads a bounded preview of a file/object.
     *
     * @return array{name: string, path: string, size: int, truncated: bool, binary: bool, content: string}
     */
    public function readFileContent(string $path): array;

    /**
     * Opens a readable resource for inline display / download.
     *
     * @return array{name: string, mime: string, size: int|null, stream: resource}
     */
    public function openResource(string $path): array;
}
