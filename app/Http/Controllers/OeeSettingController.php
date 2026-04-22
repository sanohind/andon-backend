<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OeeSetting;
use Illuminate\Http\Request;

class OeeSettingController extends Controller
{
    /**
     * Get current OEE settings.
     * - warning_threshold_percent default 85%
     * - target_efficiency_percent default 96%
     */
    public function show()
    {
        $setting = OeeSetting::first();
        $threshold = $setting ? (float) $setting->warning_threshold_percent : 85.0;
        $targetEfficiency = $setting && $setting->target_efficiency_percent !== null
            ? (float) $setting->target_efficiency_percent
            : 96.0;

        return response()->json([
            'success' => true,
            'data' => [
                'warning_threshold_percent' => $threshold,
                'target_efficiency_percent' => $targetEfficiency,
            ],
        ]);
    }

    /**
     * Update OEE settings. Only admin (checked at gateway/Node and here via header).
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
            'warning_threshold_percent' => 'sometimes|required|numeric|min:0|max:100',
            'target_efficiency_percent' => 'sometimes|required|numeric|min:0|max:100',
        ]);

        if (!array_key_exists('warning_threshold_percent', $validated) && !array_key_exists('target_efficiency_percent', $validated)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada perubahan yang dikirim.',
            ], 422);
        }

        $setting = OeeSetting::first();
        if (!$setting) {
            $setting = new OeeSetting();
        }
        if (array_key_exists('warning_threshold_percent', $validated)) {
            $setting->warning_threshold_percent = $validated['warning_threshold_percent'];
        }
        if (array_key_exists('target_efficiency_percent', $validated)) {
            $setting->target_efficiency_percent = $validated['target_efficiency_percent'];
        }
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => 'OEE settings berhasil disimpan.',
            'data' => [
                'warning_threshold_percent' => (float) $setting->warning_threshold_percent,
                'target_efficiency_percent' => $setting->target_efficiency_percent !== null ? (float) $setting->target_efficiency_percent : 96.0,
            ],
        ]);
    }
}

