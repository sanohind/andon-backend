<?php

namespace App\Http\Controllers;

use App\Models\PartConfiguration;
use App\Models\InspectionTable;
use Illuminate\Http\Request;

class PartConfigurationController extends Controller
{
    /**
     * Display a listing of the resource by address.
     */
    public function index(Request $request)
    {
        $address = $request->query('address');
        
        if ($address) {
            $configurations = PartConfiguration::where('address', $address)->get();
            return response()->json([
                'success' => true,
                'data' => $configurations
            ]);
        }
        
        $configurations = PartConfiguration::all();
        return response()->json([
            'success' => true,
            'data' => $configurations
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'address' => 'required|string|exists:inspection_tables,address',
            'channel' => 'required|integer|min:0',
            'part_number' => 'required|string|max:255',
            'cycle_time' => 'nullable|integer|min:0',
            'jumlah_bending' => 'required|integer|min:0',
            'cavity' => 'required|integer|min:0'
        ]);

        $configuration = PartConfiguration::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Part configuration berhasil ditambahkan',
            'data' => $configuration
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $configuration = PartConfiguration::find($id);
        
        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Part configuration tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $configuration
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $configuration = PartConfiguration::find($id);
        
        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Part configuration tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'channel' => 'sometimes|required|integer|min:0',
            'part_number' => 'sometimes|required|string|max:255',
            'cycle_time' => 'nullable|integer|min:0',
            'jumlah_bending' => 'sometimes|required|integer|min:0',
            'cavity' => 'sometimes|required|integer|min:0'
        ]);

        $configuration->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Part configuration berhasil diupdate',
            'data' => $configuration
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $configuration = PartConfiguration::find($id);
        
        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Part configuration tidak ditemukan'
            ], 404);
        }

        $configuration->delete();

        return response()->json([
            'success' => true,
            'message' => 'Part configuration berhasil dihapus'
        ]);
    }

    /**
     * Bulk import part configurations.
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'configurations' => 'required|array',
            'configurations.*.address' => 'required|string|exists:inspection_tables,address',
            'configurations.*.channel' => 'required|integer|min:0',
            'configurations.*.part_number' => 'required|string|max:255',
            'configurations.*.cycle_time' => 'nullable|integer|min:0',
            'configurations.*.jumlah_bending' => 'required|integer|min:0',
            'configurations.*.cavity' => 'required|integer|min:0'
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($request->configurations as $index => $configData) {
            try {
                // Check if configuration exists (by address, channel, and part_number)
                $existing = PartConfiguration::where('address', $configData['address'])
                    ->where('channel', $configData['channel'])
                    ->where('part_number', $configData['part_number'])
                    ->first();

                if ($existing) {
                    // Update existing configuration
                    $existing->update([
                        'cycle_time' => $configData['cycle_time'] ?? null,
                        'jumlah_bending' => $configData['jumlah_bending'],
                        'cavity' => $configData['cavity']
                    ]);
                    $updated++;
                } else {
                    // Create new configuration
                    PartConfiguration::create($configData);
                    $created++;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index + 1,
                    'message' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk import selesai',
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors
        ]);
    }
}
