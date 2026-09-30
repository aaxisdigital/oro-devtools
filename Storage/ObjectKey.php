<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

/**
 * Helpers for the "/"-delimited key space of an object store: folder prefixes always end with "/",
 * the root prefix is the empty string.
 */
final class ObjectKey
{
    /**
     * Turns a user-supplied path into a folder prefix ("" for the root, otherwise ending in "/").
     */
    public static function normalizePrefix(string $path): string
    {
        $prefix = ltrim(trim($path), '/');

        return $prefix === '' || str_ends_with($prefix, '/') ? $prefix : $prefix . '/';
    }

    /**
     * The prefix of the folder containing $prefix; null for the root, "" for a top-level folder.
     */
    public static function parentPrefix(string $prefix): ?string
    {
        if ($prefix === '') {
            return null;
        }
        $trimmed = rtrim($prefix, '/');
        $pos = strrpos($trimmed, '/');

        return $pos === false ? '' : substr($trimmed, 0, $pos + 1);
    }

    /**
     * The last path segment of a key or folder prefix.
     */
    public static function basename(string $key): string
    {
        $trimmed = rtrim($key, '/');
        $pos = strrpos($trimmed, '/');

        return $pos === false ? $trimmed : substr($trimmed, $pos + 1);
    }
}
