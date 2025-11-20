<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

abstract class Controller
{
    /**
     * Get current user role from request token
     * Returns null if token is invalid or user not found
     */
    protected function getUserRoleFromToken(Request $request)
    {
        $token = $request->bearerToken() ?? $request->header('Authorization');
        if (!$token) {
            return null;
        }

        try {
            $session = DB::table('user_sessions')
                ->join('users', 'user_sessions.user_id', '=', 'users.id')
                ->where('user_sessions.token', str_replace('Bearer ', '', $token))
                ->where('users.active', 1)
                ->where('user_sessions.expires_at', '>', Carbon::now(config('app.timezone')))
                ->select('users.role')
                ->first();

            return $session ? $session->role : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if current user is management role (view-only)
     * Returns true if user is management, false otherwise
     */
    protected function isManagementRole(Request $request)
    {
        $role = $this->getUserRoleFromToken($request);
        return $role === 'management';
    }

    /**
     * Block write operations for management role
     * Returns 403 response if user is management, null otherwise
     */
    protected function blockManagementWrite(Request $request)
    {
        if ($this->isManagementRole($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Role management hanya dapat melihat data, tidak dapat melakukan perubahan.'
            ], 403);
        }
        return null;
    }
}
