<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Storage;

/**
 * Human-readable byte sizes ("512 B", "1.5 KB", "3.0 GB") for the storage browsers' listings.
 */
final class SizeFormatter
{
    public static function format(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1)) . ' ' . $units[$i];
    }
}
