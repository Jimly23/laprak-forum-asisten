<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClassroomController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Classroom::query()->orderBy('code')->get(),
        ]);
    }

    public function show(Classroom $classroom): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $classroom]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);
        $classroom = Classroom::query()->create($data);

        return response()->json([
            'success' => true,
            'message' => 'Kelas berhasil ditambahkan.',
            'data' => $classroom,
        ], 201);
    }

    public function update(Request $request, Classroom $classroom): JsonResponse
    {
        $classroom->update($this->validatedData($request, $classroom));

        return response()->json([
            'success' => true,
            'message' => 'Kelas berhasil diperbarui.',
            'data' => $classroom->fresh(),
        ]);
    }

    public function destroy(Classroom $classroom): JsonResponse
    {
        if ($classroom->submissions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kelas tidak dapat dihapus karena masih digunakan oleh tugas.',
            ], 409);
        }

        $classroom->delete();

        return response()->json(['success' => true, 'message' => 'Kelas berhasil dihapus.']);
    }

    private function validatedData(Request $request, ?Classroom $classroom = null): array
    {
        $name = strtoupper(trim((string) $request->input('name')));
        $code = trim((string) $request->input('code')) ?: Str::upper(Str::slug($name, '-'));
        $request->merge(['code' => $code, 'name' => $name]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('classes', 'code')->ignore($classroom?->id)],
            'name' => ['required', 'string', 'max:150'],
        ]);

        return $data;
    }
}
