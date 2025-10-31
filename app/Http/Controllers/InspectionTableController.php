<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InspectionTable;
use App\Models\ProductionData;
use Illuminate\Http\Request;
use Carbon\Carbon;

class InspectionTableController extends Controller
{
    public function index()
    {
        try {
            $tables = InspectionTable::all();
            
            // Sort using natural order (handles numbers correctly)
            $tables = $tables->sort(function($a, $b) {
                return strnatcasecmp($a->name, $b->name);
            });
            
            \Log::info('InspectionTableController::index - Found tables:', ['count' => $tables->count(), 'tables' => $tables->toArray()]);
            
            // Return explicit JSON response with success/data format
            return response()->json([
                'success' => true,
                'data' => $tables->values()
            ]);
        } catch (\Exception $e) {
            \Log::error('InspectionTableController::index - Error:', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'line_name' => 'required|string|max:50',
            'division' => 'required|string|max:50'
        ]);

        // Validasi unik secara manual
        $exists = InspectionTable::where('name', $validated['name'])
                                ->where('line_name', $validated['line_name'])
                                ->exists();

        if ($exists) {
            return response()->json(['message' => 'Nama meja untuk line tersebut sudah ada.'], 422);
        }

        // Auto-generate address with 4-meja groups: 101-01 to 101-04, then 102-01 to 102-04, etc.
        $lastTable = InspectionTable::orderBy('id', 'desc')->first();
        
        if (!$lastTable || !$lastTable->address) {
            // First table
            $address = '101-01';
        } else {
            // Parse existing address to get group and position
            $addressParts = explode('-', $lastTable->address);
            $currentGroup = intval($addressParts[0]);
            $currentPosition = intval($addressParts[1]);
            
            if ($currentPosition < 4) {
                // Still in same group, increment position
                $newPosition = $currentPosition + 1;
                $address = $currentGroup . '-' . str_pad($newPosition, 2, '0', STR_PAD_LEFT);
            } else {
                // Move to next group, start at position 1
                $newGroup = $currentGroup + 1;
                $address = $newGroup . '-01';
            }
        }

        // Buat meja baru dengan address
        $inspectionTable = InspectionTable::create([
            'name' => $validated['name'],
            'line_name' => $validated['line_name'],
            'division' => $validated['division'],
            'address' => $address
        ]);

        // Auto-insert ke production_data untuk memulai penghitungan quantity
        ProductionData::create([
            'timestamp' => Carbon::now('Asia/Jakarta'),
            'machine_name' => $address, // Gunakan address sebagai machine_name
            'line_name' => $validated['line_name'],
            'quantity' => 0
        ]);

        return $inspectionTable;
    }

    public function update(Request $request, InspectionTable $inspectionTable)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'line_name' => 'required|string|max:50'
        ]);

        // Validasi unik secara manual
        $exists = InspectionTable::where('name', $validated['name'])
                                ->where('line_name', $validated['line_name'])
                                ->where('id', '!=', $inspectionTable->id)
                                ->exists();

        if ($exists) {
            return response()->json(['message' => 'Nama meja untuk line tersebut sudah ada.'], 422);
        }

        $inspectionTable->update($validated);
        return $inspectionTable;
    }

    public function updateByAddress(Request $request, $address)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255'
        ]);

        // Find inspection table by address
        $inspectionTable = InspectionTable::where('address', $address)->first();

        if (!$inspectionTable) {
            return response()->json(['message' => 'Inspection table with address not found.'], 404);
        }

        // Update only the name
        $inspectionTable->update([
            'name' => $validated['name']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inspection table updated successfully.',
            'data' => $inspectionTable
        ]);
    }

    public function setTarget(Request $request, $address)
    {
        $validated = $request->validate([
            'target_quantity' => 'required|integer|min:0'
        ]);

        $table = InspectionTable::where('address', $address)->first();
        if (!$table) {
            return response()->json(['message' => 'Inspection table with address not found.'], 404);
        }

        // Update latest production_data row for this machine (address)
        $latest = ProductionData::where('machine_name', $address)->orderByDesc('timestamp')->first();
        if (!$latest) {
            $latest = ProductionData::create([
                'timestamp' => Carbon::now('Asia/Jakarta'),
                'machine_name' => $address,
                'line_name' => $table->line_name,
                'quantity' => 0,
                'target_quantity' => $validated['target_quantity']
            ]);
        } else {
            $latest->update(['target_quantity' => $validated['target_quantity']]);
        }

        // Recompute and store OEE using ACTUAL quantity when possible
        $cycle = $latest->cycle_time;
        $actualQuantity = $latest->quantity ?? 0;
        if (!is_null($cycle) && $cycle > 0) {
            $oee = (($actualQuantity * $cycle) / (8 * 3600)) * 100.0;
            $table->update(['oee' => round($oee, 2)]);
        }

        return response()->json(['success' => true, 'message' => 'Target quantity updated', 'data' => [
            'address' => $address,
            'target_quantity' => $latest->target_quantity,
            'cycle_time' => $latest->cycle_time,
            'oee' => $table->oee
        ]]);
    }

    public function setCycle(Request $request, $address)
    {
        $validated = $request->validate([
            'cycle_time' => 'required|integer|min:0'
        ]);

        $table = InspectionTable::where('address', $address)->first();
        if (!$table) {
            return response()->json(['message' => 'Inspection table with address not found.'], 404);
        }

        $latest = ProductionData::where('machine_name', $address)->orderByDesc('timestamp')->first();
        if (!$latest) {
            $latest = ProductionData::create([
                'timestamp' => Carbon::now('Asia/Jakarta'),
                'machine_name' => $address,
                'line_name' => $table->line_name,
                'quantity' => 0,
                'cycle_time' => $validated['cycle_time']
            ]);
        } else {
            $latest->update(['cycle_time' => $validated['cycle_time']]);
        }

        // Recompute and store OEE using ACTUAL quantity when possible
        $actualQuantity = $latest->quantity ?? 0;
        if ($validated['cycle_time'] > 0) {
            $oee = (($actualQuantity * $validated['cycle_time']) / (8 * 3600)) * 100.0;
            $table->update(['oee' => round($oee, 2)]);
        }

        return response()->json(['success' => true, 'message' => 'Cycle time updated', 'data' => [
            'address' => $address,
            'target_quantity' => $latest->target_quantity,
            'cycle_time' => $latest->cycle_time,
            'oee' => $table->oee
        ]]);
    }

    public function metrics()
    {
        // Return per-table metrics: address, target_quantity, cycle_time, oee
        $tables = InspectionTable::all();

        $result = $tables->map(function($t){
            $latest = ProductionData::where('machine_name', $t->address)->orderByDesc('timestamp')->first();
            $actualQty = $latest?->quantity ?? 0;
            $cycle = $latest?->cycle_time ?? null;
            $oee = null;
            if (!is_null($cycle) && $cycle > 0) {
                $oee = (($actualQty * $cycle) / (8 * 3600)) * 100.0;
            }
            return [
                'address' => $t->address,
                'name' => $t->name,
                'line_name' => $t->line_name,
                'target_quantity' => $latest?->target_quantity,
                'cycle_time' => $cycle,
                'oee' => is_null($oee) ? null : round($oee, 2),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function destroy(InspectionTable $inspectionTable)
    {
        $inspectionTable->delete();
        return response()->json(['message' => 'Meja Inspect berhasil dihapus.']);
    }
    public function getMachineStatus(Request $request, $machineName)
    {
        // Logika Anda untuk mendapatkan status, quantity, problemType dari database atau cache
        // Contoh:
        $statusData = [
            'status' => 'normal', // atau 'problem', 'warning'
            'quantity' => 123,
            'problemType' => null // 'Maintenance', 'Quality', dll.
        ];

        // Anda perlu mengambil data real-time dari database/cache Anda di sini
        // Misalnya, dari tabel 'machine_data'
        // $machineData = MachineData::where('machine_name', $machineName)->latest()->first();
        // if ($machineData) {
        //     $statusData['status'] = $machineData->current_status;
        //     $statusData['quantity'] = $machineData->total_quantity;
        //     $statusData['problemType'] = $machineData->current_problem_type;
        // }

        return response()->json($statusData);
    }
}