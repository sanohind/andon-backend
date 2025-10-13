<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Handle login request dari Node.js frontend
     */
    public function login(Request $request)
    {
        \Log::info('Login attempt', [
        'method' => $request->getMethod(),
        'url' => $request->url(),
        'headers' => $request->headers->all(),
        'body' => $request->all()
        ]);
        
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string'
        ]);

        try {
            // Cari user berdasarkan username
            $user = DB::table('users')
                ->where('username', $request->username)
                ->where('active', true)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username tidak ditemukan'
                ], 401);
            }

            // Verifikasi password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password salah'
                ], 401);
            }

            // Generate token untuk session
            $token = $this->generateToken();

            // Simpan token ke database untuk tracking session
            DB::table('user_sessions')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'user_id' => $user->id,
                    'token' => $token,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                    'expires_at' => Carbon::now()->addHours(24)
                ]
            );

            // Update last login
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'last_login' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);

            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'name' => $user->name,
                    'role' => $user->role
                ],
                'message' => 'Login berhasil'
            ]);

        } catch (\Exception $e) {
            \Log::error('Login error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Server error, silakan coba lagi'
            ], 500);
        }
    }

    /**
     * Validate token untuk middleware authentication
     */
    public function validateToken(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        try {
            // LANGKAH 1: Validasi token dan HANYA ambil user_id
            $session = DB::table('user_sessions')
                ->where('token', $request->token)
                ->where('expires_at', '>', Carbon::now())
                ->select('user_id') // Hanya ambil user_id, lebih efisien
                ->first();

            // Jika token tidak valid atau sudah expired, hentikan di sini
            if (!$session) {
                return response()->json(['valid' => false, 'message' => 'Token tidak valid atau expired'], 401);
            }

            // LANGKAH 2: Ambil model User yang LENGKAP dari database menggunakan ID
            // Ini adalah cara yang paling andal untuk mendapatkan semua data user
            $user = \App\Models\User::find($session->user_id);

            // Jika user tidak ditemukan atau tidak aktif
            if (!$user || !$user->active) { // Saya berasumsi Anda punya kolom 'active'
                return response()->json(['valid' => false, 'message' => 'User tidak aktif'], 401);
            }
            
            // LANGKAH 3: Update sesi (logika Anda yang sudah ada)
            DB::table('user_sessions')
                ->where('token', $request->token)
                ->update([
                    'updated_at' => Carbon::now(),
                    'expires_at' => Carbon::now()->addHours(24)
                ]);

            // LANGKAH 4: Kirim kembali data user yang lengkap.
            // Metode ->only() akan secara aman mengambil semua data yang kita butuhkan.
            return response()->json([
                'valid' => true,
                'user' => $user->only(['id', 'name', 'username', 'role', 'line_name'])
            ]);

        } catch (\Exception $e) {
            \Log::error('Token validation error: ' . $e->getMessage());
            return response()->json(['valid' => false, 'message' => 'Server error'], 500);
        }
    }

    /**
     * Logout user - hapus token session
     */
    public function logout(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        try {
            // Hapus session dari database
            DB::table('user_sessions')
                ->where('token', $request->token)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil'
            ]);

        } catch (\Exception $e) {
            \Log::error('Logout error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    /**
     * Generate secure token untuk session
     */
    private function generateToken()
    {
        return hash('sha256', Str::random(60) . time());
    }

    /**
     * Get current authenticated user info
     */
    public function user(Request $request)
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Token required'
            ], 401);
        }

        try {
            $session = DB::table('user_sessions')
                ->join('users', 'user_sessions.user_id', '=', 'users.id')
                ->where('user_sessions.token', $token)
                ->where('users.active', true)
                ->where('user_sessions.expires_at', '>', Carbon::now())
                ->select(
                    'users.id',
                    'users.username', 
                    'users.name',
                    'users.role',
                    'users.created_at',
                    'users.last_login'
                )
                ->first();

            if (!$session) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'user' => $session
            ]);

        } catch (\Exception $e) {
            \Log::error('Get user error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }

    /**
     * Get current authenticated user info using Sanctum
     */
    public function sanctumUser(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'role' => $user->role,
                'division' => $user->division,
                'line_name' => $user->line_name,
                'created_at' => $user->created_at,
                'last_login' => $user->last_login
            ]
        ]);
    }

    /**
     * Cleanup expired sessions (untuk dijadwalkan di cron job)
     */
    public function cleanupExpiredSessions()
    {
        try {
            $deleted = DB::table('user_sessions')
                ->where('expires_at', '<', Carbon::now())
                ->delete();

            \Log::info("Cleaned up {$deleted} expired sessions");

            return response()->json([
                'success' => true,
                'cleaned' => $deleted
            ]);

        } catch (\Exception $e) {
            \Log::error('Session cleanup error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Server error'
            ], 500);
        }
    }
}