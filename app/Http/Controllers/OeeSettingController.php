<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OeeSetting;
use Illuminate\Http\Request;

class OeeSettingController extends Controller
{
    /**
     * Get current OEE warning threshold. If not set, return default 85%.
     */
    public function show()
    {
        $setting = OeeSetting::first();
        $threshold = $setting ? (float) $setting->warning_threshold_percent : 85.0;

        return response()->json([
            'success' => true,
            'data' => [
                'warning_threshold_percent' => $threshold,
            ],
        ]);
    }

    /**
     * Update OEE warning threshold. Only admin (checked at gateway/Node and here via header).
     */
    public function update(Request $request)
    {
        // Role check via X-User-Role header (Node frontend meneruskan role user)
        $role = $request->header('X-User-Role');
        if ($role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat mengatur threshold OEE.',
            ], 403);
        }

        $validated = $request->validate([
            'warning_threshold_percent' => 'required|numeric|min:0|max:100',
        ]);

        $setting = OeeSetting::first();
        if (!$setting) {
            $setting = new OeeSetting();
        }
        $setting->warning_threshold_percent = $validated['warning_threshold_percent'];
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => 'Threshold OEE berhasil disimpan.',
            'data' => [
                'warning_threshold_percent' => (float) $setting->warning_threshold_percent,
            ],
        ]);
    }
}

