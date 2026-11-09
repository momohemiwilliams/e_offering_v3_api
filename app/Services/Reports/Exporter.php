<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloadable reports (CR s7): Order Book, Allotment Schedule, Lodgement Summary, etc.
 * Excel by default, CSV on request.
 */
class Exporter
{
    public function download(string $filename, array $headings, array $rows, string $format = 'xlsx', ?string $title = null): StreamedResponse
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headings, $rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $headings);
                foreach ($rows as $row) {
                    fputcsv($out, array_values($row));
                }
                fclose($out);
            }, $filename.'.csv', ['Content-Type' => 'text/csv']);
        }

        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setTitle('Report');
        $line = 1;
        if ($title) {
            $sheet->setCellValue('A1', $title);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
            $sheet->setCellValue('A2', 'Generated '.now()->format('F j, Y g:i A').' — FMDQ e-Offering Platform');
            $line = 4;
        }
        $sheet->fromArray($headings, null, 'A'.$line);
        $headerRange = 'A'.$line.':'.$sheet->getHighestColumn().$line;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1D326D');
        if ($rows !== []) {
            $sheet->fromArray(array_map('array_values', $rows), null, 'A'.($line + 1));
        }
        foreach (range(1, count($headings)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $spreadsheet = $sheet->getParent();

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
