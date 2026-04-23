<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master part list: part_number, part_name, cycle_time (no per-machine columns).
     * Removes: address, jumlah_bending, cavity, line_name (as requested).
     * Channel is dropped because it is only meaningful together with address.
     */
    public function up(): void
    {
        if (!Schema::hasTable('part_configurations')) {
            return;
        }

        if (Schema::hasColumn('part_configurations', 'address')) {
            try {
                Schema::table('part_configurations', function (Blueprint $table) {
                    $table->dropForeign(['address']);
                });
            } catch (\Throwable $e) {
                //
            }
        }

        if (!Schema::hasColumn('part_configurations', 'part_name')) {
            Schema::table('part_configurations', function (Blueprint $table) {
                $table->string('part_name', 255)->nullable()->after('part_number');
            });
        }

        foreach (DB::table('part_configurations')->orderBy('id')->cursor() as $row) {
            if ($row->part_name === null || $row->part_name === '') {
                $pn = $row->part_number ?? '';
                DB::table('part_configurations')->where('id', $row->id)->update(['part_name' => $pn !== '' ? $pn : 'Part']);
            }
        }

        Schema::table('part_configurations', function (Blueprint $table) {
            $drops = [];
            foreach (['address', 'line_name', 'channel', 'jumlah_bending', 'cavity'] as $col) {
                if (Schema::hasColumn('part_configurations', $col)) {
                    $drops[] = $col;
                }
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });

        $seen = [];
        foreach (DB::table('part_configurations')->orderBy('id')->get(['id', 'part_number']) as $row) {
            $key = (string) ($row->part_number ?? '');
            if ($key === '') {
                DB::table('part_configurations')->where('id', $row->id)->delete();
                continue;
            }
            if (isset($seen[$key])) {
                DB::table('part_configurations')->where('id', $row->id)->delete();
            } else {
                $seen[$key] = true;
            }
        }

        Schema::table('part_configurations', function (Blueprint $table) {
            try {
                $table->unique('part_number', 'part_configurations_part_number_unique');
            } catch (\Throwable $e) {
                //
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('part_configurations')) {
            return;
        }

        Schema::table('part_configurations', function (Blueprint $table) {
            try {
                $table->dropUnique('part_configurations_part_number_unique');
            } catch (\Throwable $e) {
                //
            }
        });

        if (Schema::hasColumn('part_configurations', 'part_name')) {
            Schema::table('part_configurations', function (Blueprint $table) {
                $table->dropColumn('part_name');
            });
        }

        Schema::table('part_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('part_configurations', 'address')) {
                $table->string('address', 20)->nullable();
            }
            if (!Schema::hasColumn('part_configurations', 'channel')) {
                $table->integer('channel')->nullable();
            }
            if (!Schema::hasColumn('part_configurations', 'jumlah_bending')) {
                $table->integer('jumlah_bending')->nullable();
            }
            if (!Schema::hasColumn('part_configurations', 'cavity')) {
                $table->integer('cavity')->nullable();
            }
            if (!Schema::hasColumn('part_configurations', 'line_name')) {
                $table->string('line_name', 50)->nullable();
            }
        });
    }
};
