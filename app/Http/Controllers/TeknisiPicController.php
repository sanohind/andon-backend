<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TeknisiPic;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeknisiPicController extends Controller
{
    /**
     * Get all teknisi PIC
     * Only accessible by admin
     */
    public function index(Request $request)
    {
        // Check if user is admin
        $userRole = $this->getUserRoleFromToken($request);
        if ($userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat mengakses data teknisi PIC.'
            ], 403);
        }

        try {
            $teknisiPics = TeknisiPic::orderBy('departement', 'asc')
                ->orderBy('nama', 'asc')
                ->get(['id', 'nama', 'departement', 'created_at', 'updated_at']);

            return response()->json([
                'success' => true,
                'data' => $teknisiPics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data teknisi PIC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new teknisi PIC
     * Only accessible by admin
     */
    public function store(Request $request)
    {
        // Check if user is admin
        $userRole = $this->getUserRoleFromToken($request);
        if ($userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat menambah teknisi PIC.'
            ], 403);
        }

        $validated = $request->validate([
            'nama' => 'required|string|max:100',
            'departement' => ['required', Rule::in(['maintenance', 'quality', 'engineering'])],
        ], [
            'nama.required' => 'Nama wajib diisi.',
            'nama.string' => 'Nama harus berupa teks.',
            'nama.max' => 'Nama maksimal 100 karakter.',
            'departement.required' => 'Departement wajib dipilih.',
            'departement.in' => 'Departement harus Maintenance, Quality, atau Engineering.',
        ]);

        try {
            $teknisiPic = TeknisiPic::create([
                'nama' => $validated['nama'],
                'departement' => $validated['departement'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Teknisi PIC berhasil ditambahkan.',
                'data' => $teknisiPic
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambah teknisi PIC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update teknisi PIC
     * Only accessible by admin
     */
    public function update(Request $request, $id)
    {
        // Check if user is admin
        $userRole = $this->getUserRoleFromToken($request);
        if ($userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat mengubah teknisi PIC.'
            ], 403);
        }

        $teknisiPic = TeknisiPic::find($id);
        if (!$teknisiPic) {
            return response()->json([
                'success' => false,
                'message' => 'Teknisi PIC tidak ditemukan.'
            ], 404);
        }

        $validated = $request->validate([
            'nama' => 'required|string|max:100',
            'departement' => ['required', Rule::in(['maintenance', 'quality', 'engineering'])],
        ], [
            'nama.required' => 'Nama wajib diisi.',
            'nama.string' => 'Nama harus berupa teks.',
            'nama.max' => 'Nama maksimal 100 karakter.',
            'departement.required' => 'Departement wajib dipilih.',
            'departement.in' => 'Departement harus Maintenance, Quality, atau Engineering.',
        ]);

        try {
            $teknisiPic->nama = $validated['nama'];
            $teknisiPic->departement = $validated['departement'];
            $teknisiPic->save();

            return response()->json([
                'success' => true,
                'message' => 'Teknisi PIC berhasil diperbarui.',
                'data' => $teknisiPic
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui teknisi PIC: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete teknisi PIC
     * Only accessible by admin
     */
    public function destroy(Request $request, $id)
    {
        // Check if user is admin
        $userRole = $this->getUserRoleFromToken($request);
        if ($userRole !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat menghapus teknisi PIC.'
            ], 403);
        }

        $teknisiPic = TeknisiPic::find($id);
        if (!$teknisiPic) {
            return response()->json([
                'success' => false,
                'message' => 'Teknisi PIC tidak ditemukan.'
            ], 404);
        }

        try {
            $teknisiPic->delete();

            return response()->json([
                'success' => true,
                'message' => 'Teknisi PIC berhasil dihapus.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus teknisi PIC: ' . $e->getMessage()
            ], 500);
        }
    }
}