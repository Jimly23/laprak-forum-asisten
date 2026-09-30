<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function index(): JsonResponse
    {
        $admins = User::query()->latest()->get()->map(fn (User $user) => $this->userData($user));

        return response()->json(['success' => true, 'data' => $admins]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
        $email = $data['email'];
        $user = User::query()->create([
            'name' => strstr($email, '@', true) ?: 'Admin',
            'email' => $email,
            'password' => Hash::make($data['password']),
            'role' => 'member',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Akun anggota berhasil ditambahkan.',
            'data' => $this->userData($user),
        ], 201);
    }

    public function destroy(Request $request, User $admin): JsonResponse
    {
        if ($admin->is($request->user()) || $admin->role === 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akun super admin atau akun yang sedang digunakan tidak dapat dihapus.',
            ], 409);
        }

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json(['success' => true, 'message' => 'Akun anggota berhasil dihapus.']);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
