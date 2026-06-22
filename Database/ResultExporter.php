<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Database;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Builds downloadable exports (CSV, JSON, XLSX) from an already-fetched result set.
 *
 * It only formats the provided columns/rows; it does not execute any SQL.
 */
class ResultExporter
{
    public const string FORMAT_CSV = 'csv';
    public const string FORMAT_JSON = 'json';
    public const string FORMAT_XLSX = 'xlsx';

    /**
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public function export(string $format, array $columns, array $rows): Response
    {
        return match ($format) {
            self::FORMAT_CSV => $this->exportCsv($columns, $rows),
            self::FORMAT_JSON => $this->exportJson($rows),
            self::FORMAT_XLSX => $this->exportXlsx($columns, $rows),
            default => throw new \InvalidArgumentException(sprintf('Unsupported export format "%s".', $format)),
        };
    }

    /**
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function exportCsv(array $columns, array $rows): Response
    {
        $path = $this->createTempFile();
        $handle = fopen($path, 'wb');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map($this->neutralizeFormula(...), $this->orderValues($columns, $row)));
        }
        fclose($handle);

        return $this->fileResponse($path, 'export.csv', 'text/csv');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function exportJson(array $rows): Response
    {
        $response = new Response(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'export.json')
        );

        return $response;
    }

    /**
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function exportXlsx(array $columns, array $rows): Response
    {
        $path = $this->createTempFile();
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($columns));
        foreach ($rows as $row) {
            $values = array_map(
                fn (mixed $value): string|int|float|bool => $this->normalizeCellValue($this->neutralizeFormula($value)),
                $this->orderValues($columns, $row)
            );
            $writer->addRow(Row::fromValues($values));
        }
        $writer->close();

        return $this->fileResponse(
            $path,
            'export.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    /**
     * Neutralizes spreadsheet formula injection: a cell whose text begins with a formula trigger
     * (=, +, -, @, tab, CR) is prefixed with an apostrophe so Excel/Sheets treat it as literal
     * text. Numeric strings ("-5", "+3.14") are left untouched so legitimate numbers keep their type.
     */
    private function neutralizeFormula(mixed $value): mixed
    {
        if (is_string($value)
            && $value !== ''
            && \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            && !is_numeric($value)
        ) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Normalizes a value into a scalar acceptable by the XLSX writer.
     */
    private function normalizeCellValue(mixed $value): string|int|float|bool
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return $value;
        }

        return (string) json_encode($value);
    }

    /**
     * @param string[] $columns
     * @param array<string, mixed> $row
     *
     * @return array<int, mixed>
     */
    private function orderValues(array $columns, array $row): array
    {
        return array_map(static fn (string $column) => $row[$column] ?? null, $columns);
    }

    private function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aaxis_export_');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file for the export.');
        }

        return $path;
    }

    private function fileResponse(string $path, string $filename, string $contentType): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $contentType);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
