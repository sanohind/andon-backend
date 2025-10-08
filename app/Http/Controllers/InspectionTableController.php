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
            'line_name' => 'required|string|max:50'
        ]);

        // Validasi unik secara manual
        $exists = InspectionTable::where('name', $validated['name'])
                                ->where('line_name', $validated['line_name'])
                                ->exists();

        if ($exists) {
            return response()->json(['message' => 'Nama meja untuk line tersebut sudah ada.'], 422);
        }

        // Buat meja baru
        $inspectionTable = InspectionTable::create($validated);

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