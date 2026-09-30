<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        if ($request->user()?->role !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses hanya tersedia untuk super admin.',
            ], 403);
        }

        return $next($request);
    }
}
