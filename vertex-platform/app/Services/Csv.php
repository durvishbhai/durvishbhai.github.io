<?php

declare(strict_types=1);

namespace Vertex\Services;

/** CSV reading/writing that is safe to open in Excel/Sheets (formula injection) and tolerant of what Excel produces (BOM, semicolons, CRLF). */
final class Csv
{
    /** A cell starting with = + - @ tab or CR is a formula to spreadsheet programs; a leading apostrophe makes it plain text. */
    public static function safeCell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !preg_match('/^[-+]?\d+([.,]\d+)?$/', $s)) {
            return "'" . $s;
        }

        return $s;
    }

    /** @param list<string> $headers @param iterable<list<mixed>> $rows */
    public static function build(array $headers, iterable $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $headers, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map([self::class, 'safeCell'], $r), ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @return array{headers:list<string>,rows:list<array<string,string>>}
     * @throws \InvalidArgumentException when the file is empty, binary, or has no header row
     */
    public static function parse(string $content, int $maxRows = 2000): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if ($content === '' || str_contains($content, "\0")) {
            throw new \InvalidArgumentException('The file is empty or is not a CSV text file.');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $content);
        rewind($fh);
        $headers = null;
        $rows = [];
        while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(static fn ($h): string => strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim((string) $h)), '_')), $cells);
                if (count(array_filter($headers)) !== count($headers) || count(array_unique($headers)) !== count($headers)) {
                    fclose($fh);
                    throw new \InvalidArgumentException('The first row must contain a unique, non-empty name for every column.');
                }
                continue;
            }
            if (count($rows) >= $maxRows) {
                fclose($fh);
                throw new \InvalidArgumentException("Too many rows: import at most {$maxRows} rows at a time.");
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($fh);
        if ($headers === null) {
            throw new \InvalidArgumentException('The file has no header row.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }
}
