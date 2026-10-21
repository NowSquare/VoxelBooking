<?php

declare(strict_types=1);

namespace App\Application\Shared;

/** CSV cells remain data when opened by spreadsheet applications. */
final class Csv
{
    public static function encode(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ([$headers, ...$rows] as $row) {
            fputcsv($stream, array_map(self::cell(...), $row), escape:'');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return $content;
    }

    public static function cell(mixed $value): string
    {
        $value = $value === null ? '' : (string)$value;
        return preg_match('/\A[\s\x00-\x1f]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }
}
