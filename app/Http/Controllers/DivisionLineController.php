<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Line;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DivisionLineController extends Controller
{
    /**
     * Get all divisions with their lines
     */
    public function index()
    {
        try {
            $divisions = Division::with('lines')->orderBy('name')->get();
            
            return response()->json([
                'success' => true,
                'data' => $divisions
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching divisions and lines: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching divisions and lines: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new division
     */
    public function storeDivision(Request $request)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:divisions,name'
        ]);

        try {
            $division = Division::create($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Division created successfully',
                'data' => $division->load('lines')
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating division: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating division: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a division
     */
    public function updateDivision(Request $request, $id)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        $division = Division::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:divisions,name,' . $id
        ]);

        try {
            $division->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Division updated successfully',
                'data' => $division->load('lines')
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating division: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating division: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a division
     */
    public function destroyDivision(Request $request, $id)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        try {
            $division = Division::findOrFail($id);
            
            // Check if division has lines
            if ($division->lines()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete division with existing lines. Please delete all lines first.'
                ], 400);
            }
            
            $division->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Division deleted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting division: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting division: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new line
     */
    public function storeLine(Request $request)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        $validated = $request->validate([
            'division_id' => 'required|exists:divisions,id',
            'name' => 'required|string|max:100'
        ]);

        // Check for unique line name within division
        $exists = Line::where('division_id', $validated['division_id'])
                     ->where('name', $validated['name'])
                     ->exists();
        
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Line name already exists in this division'
            ], 422);
        }

        try {
            $line = Line::create($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Line created successfully',
                'data' => $line->load('division')
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating line: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating line: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a line
     */
    public function updateLine(Request $request, $id)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        $line = Line::findOrFail($id);
        
        $validated = $request->validate([
            'division_id' => 'required|exists:divisions,id',
            'name' => 'required|string|max:100'
        ]);

        // Check for unique line name within division (excluding current line)
        $exists = Line::where('division_id', $validated['division_id'])
                     ->where('name', $validated['name'])
                     ->where('id', '!=', $id)
                     ->exists();
        
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Line name already exists in this division'
            ], 422);
        }

        try {
            $line->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Line updated successfully',
                'data' => $line->load('division')
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating line: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating line: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a line
     */
    public function destroyLine(Request $request, $id)
    {
        // Block write operations for management role
        if ($blockResponse = $this->blockManagementWrite($request)) {
            return $blockResponse;
        }

        try {
            $line = Line::findOrFail($id);
            
            // Check if line is used in inspection_tables
            $usedInTables = DB::table('inspection_tables')
                             ->where('line_name', $line->name)
                             ->exists();
            
            if ($usedInTables) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete line that is used in inspection tables. Please update or remove related inspection tables first.'
                ], 400);
            }
            
            // Check if line is used in users
            $usedInUsers = DB::table('users')
                           ->where('line_name', $line->name)
                           ->exists();
            
            if ($usedInUsers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete line that is assigned to users. Please update or remove related users first.'
                ], 400);
            }
            
            $line->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Line deleted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting line: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting line: ' . $e->getMessage()
            ], 500);
        }
    }
}

