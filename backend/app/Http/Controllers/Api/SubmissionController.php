<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'meeting' => ['nullable', 'integer', 'between:1,14'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Submission::query()->with(['classroom', 'course', 'grade'])->latest();
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($builder) => $builder
                ->where('nim', 'like', "%{$search}%")
                ->orWhere('student_name', 'like', "%{$search}%"));
        }
        if (! empty($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }
        if (! empty($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }
        if (! empty($filters['meeting'])) {
            $query->where('meeting', $filters['meeting']);
        }

        $paginator = $query->paginate($filters['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn (Submission $submission) => $this->submissionData($submission)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nim' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/'],
            'student_name' => ['required', 'string', 'max:150'],
            'class_id' => ['required', 'integer', Rule::exists('classes', 'id')],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'meeting' => ['required', 'integer', 'between:1,14'],
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf,application/x-pdf', 'max:10240'],
        ], [
            'nim.regex' => 'NIM hanya boleh berisi huruf dan angka.',
            'file.mimes' => 'Berkas tugas harus berformat PDF.',
            'file.mimetypes' => 'Berkas tugas harus berupa PDF yang valid.',
            'file.max' => 'Ukuran berkas maksimal 10 MB.',
        ]);

        $file = $request->file('file');
        $path = $file->store('submissions', 'local');

        try {
            $submission = Submission::query()->create([
                'nim' => strtoupper(trim($data['nim'])),
                'student_name' => trim($data['student_name']),
                'class_id' => $data['class_id'],
                'course_id' => $data['course_id'],
                'meeting' => $data['meeting'],
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/pdf',
                'file_size' => $file->getSize(),
            ])->load(['classroom', 'course', 'grade']);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'Tugas berhasil dikirim.',
            'data' => $this->submissionData($submission),
        ], 201);
    }

    public function show(Submission $submission): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->submissionData($submission->load(['classroom', 'course', 'grade'])),
        ]);
    }

    public function file(Submission $submission): BinaryFileResponse|JsonResponse
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($submission->stored_path)) {
            return response()->json(['success' => false, 'message' => 'Berkas tugas tidak ditemukan.'], 404);
        }

        $filename = str_replace(['"', "\r", "\n"], '', $submission->original_filename);

        return response()->file($disk->path($submission->stored_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function submissionData(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'nim' => $submission->nim,
            'student_name' => $submission->student_name,
            'class' => [
                'id' => $submission->classroom->id,
                'code' => $submission->classroom->code,
                'name' => $submission->classroom->name,
            ],
            'course' => [
                'id' => $submission->course->id,
                'code' => $submission->course->code,
                'name' => $submission->course->name,
            ],
            'meeting' => $submission->meeting,
            'file' => [
                'name' => $submission->original_filename,
                'mime_type' => $submission->mime_type,
                'size' => $submission->file_size,
            ],
            'submitted_at' => $submission->created_at?->toISOString(),
            'grade' => $submission->grade ? [
                'id' => $submission->grade->id,
                'score' => (float) $submission->grade->score,
                'graded_by' => $submission->grade->graded_by,
                'graded_at' => $submission->grade->updated_at?->toISOString(),
            ] : null,
        ];
    }
}
