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
        return User::select('id', 'name', 'username', 'role', 'line_name')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['admin', 'manager', 'leader', 'maintenance', 'quality', 'engineering'])],
            'line_name' => 'nullable|string|max:50|required_if:role,leader',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'line_name' => $validated['role'] === 'leader' ? $validated['line_name'] : null,
        ]);

        return response()->json($user, 201);
    }
}