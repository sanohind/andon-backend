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
        $tables = InspectionTable::all();
        
        // Sort using natural order (handles numbers correctly)
        $tables = $tables->sort(function($a, $b) {
            return strnatcasecmp($a->name, $b->name);
        });
        
        return $tables->values();
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
            'machine_name' => $validated['name'],
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