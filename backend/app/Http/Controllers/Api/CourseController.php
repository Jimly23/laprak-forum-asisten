<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Course::query()->orderBy('code')->get(),
        ]);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $course]);
    }

    public function store(Request $request): JsonResponse
    {
        $course = Course::query()->create($this->validatedData($request));

        return response()->json([
            'success' => true,
            'message' => 'Mata kuliah berhasil ditambahkan.',
            'data' => $course,
        ], 201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $course->update($this->validatedData($request, $course));

        return response()->json([
            'success' => true,
            'message' => 'Mata kuliah berhasil diperbarui.',
            'data' => $course->fresh(),
        ]);
    }

    public function destroy(Course $course): JsonResponse
    {
        if ($course->submissions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Mata kuliah tidak dapat dihapus karena masih digunakan oleh tugas.',
            ], 409);
        }

        $course->delete();

        return response()->json(['success' => true, 'message' => 'Mata kuliah berhasil dihapus.']);
    }

    private function validatedData(Request $request, ?Course $course = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('courses', 'code')->ignore($course?->id)],
            'name' => ['required', 'string', 'max:150'],
        ]);
        $data['name'] = trim($data['name']);

        return $data;
    }
}
