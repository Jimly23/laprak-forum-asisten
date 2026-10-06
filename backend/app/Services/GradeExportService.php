<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Course;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GradeExportService
{
    public function write(array $rows, Classroom $classroom, Course $course, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Nilai');

        $headers = ['NIM', 'Nama', 'Kelas', 'Mata Kuliah'];
        for ($meeting = 1; $meeting <= GradeReportService::MEETING_COUNT; $meeting++) {
            $headers[] = "Pertemuan {$meeting}";
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $courseLabel = $this->referenceLabel($course->code, $course->name);
        $classLabel = $this->referenceLabel($classroom->code, $classroom->name);

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A1', "Nilai Mata Kuliah: {$courseLabel}");
        $sheet->setCellValue('A2', "Kelas: {$classLabel}");
        $sheet->fromArray($headers, null, 'A4');
        $rowNumber = 5;
        foreach ($rows as $row) {
            $values = [
                $row['nim'],
                $row['student_name'],
                $classLabel,
                $courseLabel,
            ];
            for ($meeting = 1; $meeting <= GradeReportService::MEETING_COUNT; $meeting++) {
                $values[] = $row['meetings'][$meeting];
            }
            $sheet->fromArray($values, null, "A{$rowNumber}");
            $rowNumber++;
        }

        $lastRow = max(4, $rowNumber - 1);
        $sheet->getStyle("A1:{$lastColumn}2")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '172554'], 'size' => 13],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '172554']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => 'thin',
                    'color' => ['rgb' => 'D1D5DB'],
                ],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(4)->setRowHeight(28);
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:{$lastColumn}{$lastRow}");
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(24);
        $sheet->getColumnDimension('D')->setWidth(34);
        for ($column = 5; $column <= count($headers); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(14);
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    public function filename(Classroom $classroom, Course $course): string
    {
        $coursePart = $this->safeFilename(trim($course->code.'_'.$course->name));
        $classPart = $this->safeFilename($classroom->code ?: $classroom->name);

        return "Nilai_{$coursePart}_{$classPart}.xlsx";
    }

    private function referenceLabel(?string $code, ?string $name): string
    {
        $code = trim((string) $code);
        $name = trim((string) $name);

        return ! $name || strcasecmp($code, $name) === 0 ? ($code ?: $name) : "{$code} - {$name}";
    }

    private function safeFilename(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?: 'Data';

        return trim($value, '_');
    }
}
