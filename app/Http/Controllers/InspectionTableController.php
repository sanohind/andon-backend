<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InspectionTable;
use Illuminate\Http\Request;

class InspectionTableController extends Controller
{
    public function index()
    {
        return InspectionTable::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:255|unique:inspection_tables']);
        return InspectionTable::create($validated);
    }

    public function update(Request $request, InspectionTable $inspectionTable)
    {
        $validated = $request->validate(['name' => 'required|string|max:255|unique:inspection_tables,name,' . $inspectionTable->id]);
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