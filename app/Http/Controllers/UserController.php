<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        try {
            return User::select('id', 'name', 'username', 'role', 'division', 'line_name')->get();
        } catch (\Exception $e) {
            // Fallback jika kolom division belum ada
            return User::select('id', 'name', 'username', 'role', 'line_name')
                ->get()
                ->map(function($user) {
                    $user->division = null;
                    return $user;
                });
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['admin', 'manager', 'leader', 'maintenance', 'quality', 'engineering'])],
            'division' => 'nullable|string|max:50|required_if:role,manager|required_if:role,leader',
            'line_name' => 'nullable|string|max:50|required_if:role,leader',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'line_name' => $validated['role'] === 'leader' ? $validated['line_name'] : null,
        ]);

        // Tambahkan division jika kolom ada
        try {
            if (isset($validated['division'])) {
                $user->division = $validated['division'];
                $user->save();
            }
        } catch (\Exception $e) {
            // Kolom division belum ada, skip
        }

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required','string','max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role' => ['required', Rule::in(['admin', 'manager', 'leader', 'maintenance', 'quality', 'engineering'])],
            'division' => 'nullable|string|max:50|required_if:role,manager|required_if:role,leader',
            'line_name' => 'nullable|string|max:50|required_if:role,leader',
        ]);

        $user->name = $validated['name'];
        $user->username = $validated['username'];
        $user->role = $validated['role'];
        $user->line_name = $validated['role'] === 'leader' ? ($validated['line_name'] ?? null) : null;

        // Tambahkan division jika kolom ada
        try {
            if (isset($validated['division'])) {
                $user->division = $validated['division'];
            }
        } catch (\Exception $e) {
            // Kolom division belum ada, skip
        }

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return response()->json($user);
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['success' => true]);
    }
}