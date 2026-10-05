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

        $sheet->fromArray($headers, null, 'A1');
        $rowNumber = 2;
        foreach ($rows as $row) {
            $values = [
                $row['nim'],
                $row['student_name'],
                $this->referenceLabel($classroom->code, $classroom->name),
                $this->referenceLabel($course->code, $course->name),
            ];
            for ($meeting = 1; $meeting <= GradeReportService::MEETING_COUNT; $meeting++) {
                $values[] = $row['meetings'][$meeting];
            }
            $sheet->fromArray($values, null, "A{$rowNumber}");
            $rowNumber++;
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->freezePane('E2');
        $sheet->setAutoFilter("A1:{$lastColumn}1");
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
