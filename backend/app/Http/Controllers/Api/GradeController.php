<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Submission;
use App\Services\GradeExportService;
use App\Services\GradeReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class GradeController extends Controller
{
    public function __construct(
        private readonly GradeReportService $reportService,
        private readonly GradeExportService $exportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);
        $rows = $this->reportService->rows($this->reportService->query($filters)->get());

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'meeting_count' => GradeReportService::MEETING_COUNT,
                'total' => count($rows),
            ],
        ]);
    }

    public function store(Request $request, Submission $submission): JsonResponse
    {
        $data = $request->validate([
            'score' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
        ], [
            'score.between' => 'Nilai harus berada di antara 0 sampai 100.',
            'score.decimal' => 'Nilai maksimal menggunakan dua angka desimal.',
        ]);

        [$grade, $created] = DB::transaction(function () use ($request, $submission, $data): array {
            $grade = Grade::query()
                ->where('nim', $submission->nim)
                ->where('class_id', $submission->class_id)
                ->where('course_id', $submission->course_id)
                ->where('meeting', $submission->meeting)
                ->lockForUpdate()
                ->first();
            $created = $grade === null;
            $grade ??= new Grade;
            $grade->fill([
                'submission_id' => $submission->id,
                'nim' => $submission->nim,
                'student_name' => $submission->student_name,
                'class_id' => $submission->class_id,
                'course_id' => $submission->course_id,
                'meeting' => $submission->meeting,
                'score' => $data['score'],
                'graded_by' => $request->user()->id,
            ])->save();

            return [$grade, $created];
        });

        return response()->json([
            'success' => true,
            'message' => $created ? 'Nilai berhasil disimpan.' : 'Nilai berhasil diperbarui.',
            'data' => $this->gradeData($grade),
        ], $created ? 201 : 200);
    }

    public function export(Request $request): BinaryFileResponse|JsonResponse
    {
        $filters = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('classes', 'id')],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'search' => ['nullable', 'string', 'max:150'],
        ]);
        $classroom = Classroom::query()->findOrFail($filters['class_id']);
        $course = Course::query()->findOrFail($filters['course_id']);
        $rows = $this->reportService->rows($this->reportService->query($filters)->get());

        if ($rows === []) {
            return response()->json(['success' => false, 'message' => 'Belum ada nilai untuk kelas dan mata kuliah ini.'], 404);
        }

        $path = tempnam(sys_get_temp_dir(), 'nilai_');
        $this->exportService->write($rows, $classroom, $course, $path);

        return response()->download($path, $this->exportService->filename($classroom, $course), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function exportAll(): BinaryFileResponse|JsonResponse
    {
        $groups = Grade::query()
            ->select(['class_id', 'course_id'])
            ->distinct()
            ->orderBy('class_id')
            ->orderBy('course_id')
            ->get();

        if ($groups->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Belum ada nilai yang dapat diekspor.'], 404);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'rekap_nilai_');
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['success' => false, 'message' => 'File ZIP tidak dapat dibuat.'], 500);
        }

        $temporaryFiles = [];
        try {
            foreach ($groups as $group) {
                $classroom = Classroom::query()->findOrFail($group->class_id);
                $course = Course::query()->findOrFail($group->course_id);
                $filters = ['class_id' => $classroom->id, 'course_id' => $course->id];
                $rows = $this->reportService->rows($this->reportService->query($filters)->get());
                $xlsxPath = tempnam(sys_get_temp_dir(), 'nilai_xlsx_');
                $temporaryFiles[] = $xlsxPath;
                $this->exportService->write($rows, $classroom, $course, $xlsxPath);
                $zip->addFile($xlsxPath, $this->exportService->filename($classroom, $course));
            }
            $zip->close();
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($zipPath);
            throw $exception;
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }

        return response()->download($zipPath, 'Rekap_Nilai.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')],
        ]);
    }

    private function gradeData(Grade $grade): array
    {
        return [
            'id' => $grade->id,
            'score' => (float) $grade->score,
            'graded_by' => $grade->graded_by,
            'graded_at' => $grade->updated_at?->toISOString(),
        ];
    }
}
