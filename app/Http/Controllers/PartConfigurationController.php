<?php

namespace App\Http\Controllers;

use App\Models\PartConfiguration;
use Illuminate\Http\Request;

class PartConfigurationController extends Controller
{
    /**
     * GET: without "page" returns all rows (for inspect-tables / filter dropdowns).
     * With "page" (and optional filters) returns paginated list for Manage > Part.
     */
    public function index(Request $request)
    {
        $query = PartConfiguration::query()->orderBy('part_number');

        if ($request->filled('part_number')) {
            $query->where('part_number', $request->part_number);
        }
        if ($request->filled('part_name')) {
            $query->where('part_name', $request->part_name);
        }
        if ($request->filled('line_name')) {
            $query->where('line_name', $request->line_name);
        }

        if ($request->filled('page') || $request->filled('per_page')) {
            $perPage = min(max((int) $request->get('per_page', 10), 5), 100);
            $paginated = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ]);
        }

        $configurations = $query->get();

        return response()->json([
            'success' => true,
            'data' => $configurations,
        ]);
    }

    public function store(Request $request)
    {
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        $validated = $request->validate([
            'part_number' => 'required|string|max:255|unique:part_configurations,part_number',
            'part_name' => 'required|string|max:255',
            'channel' => 'nullable|integer|min:0',
            'line_name' => 'nullable|string|max:50',
            'cycle_time' => 'required|integer|min:0',
        ]);

        $configuration = PartConfiguration::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Part berhasil ditambahkan',
            'data' => $configuration,
        ], 201);
    }

    public function show(string $id)
    {
        $configuration = PartConfiguration::find($id);

        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Part tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $configuration,
        ]);
    }

    public function update(Request $request, string $id)
    {
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        $configuration = PartConfiguration::find($id);

        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Part tidak ditemukan',
            ], 404);
        }

        $validated = $request->validate([
            'part_number' => 'sometimes|required|string|max:255|unique:part_configurations,part_number,' . $configuration->id,
            'part_name' => 'sometimes|required|string|max:255',
            'channel' => 'sometimes|nullable|integer|min:0',
            'line_name' => 'sometimes|nullable|string|max:50',
            'cycle_time' => 'sometimes|required|integer|min:0',
        ]);

        $configuration->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Part berhasil diupdate',
            'data' => $configuration,
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        $configuration = PartConfiguration::find($id);

        if (!$configuration) {
            return response()->json([
                'success' => false,
                'message' => 'Part tidak ditemukan',
            ], 404);
        }

        $configuration->delete();

        return response()->json([
            'success' => true,
            'message' => 'Part berhasil dihapus',
        ]);
    }

    /**
     * Upsert by part_number (Manage > Part import / legacy).
     */
    public function bulkImport(Request $request)
    {
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        $request->validate([
            'configurations' => 'required|array',
            'configurations.*.part_number' => 'required|string|max:255',
            'configurations.*.part_name' => 'required|string|max:255',
            'configurations.*.channel' => 'nullable|integer|min:0',
            'configurations.*.line_name' => 'nullable|string|max:50',
            'configurations.*.cycle_time' => 'required|integer|min:0',
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($request->configurations as $index => $configData) {
            try {
                $existing = PartConfiguration::where('part_number', $configData['part_number'])->first();

                if ($existing) {
                    $existing->update([
                        'part_name' => $configData['part_name'],
                        'channel' => $configData['channel'],
                        'line_name' => $configData['line_name'],
                        'cycle_time' => $configData['cycle_time'],
                    ]);
                    $updated++;
                } else {
                    PartConfiguration::create([
                        'part_number' => $configData['part_number'],
                        'part_name' => $configData['part_name'],
                        'channel' => $configData['channel'],
                        'line_name' => $configData['line_name'],
                        'cycle_time' => $configData['cycle_time'],
                    ]);
                    $created++;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index + 1,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk import selesai',
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }
}
