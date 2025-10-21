<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionData extends Model
{
    use HasFactory;

    /**
     * Nama tabel yang terhubung dengan model ini.
     *
     * @var string
     */
    protected $table = 'production_data';

    /**
     * Menonaktifkan timestamp default Laravel (created_at & updated_at).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Kolom-kolom yang boleh diisi secara massal.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'timestamp',
        'machine_name',
        'line_name',
        'quantity',
    ];

    /**
     * Relasi dengan InspectionTable berdasarkan address
     */
    public function inspectionTable()
    {
        return $this->belongsTo(InspectionTable::class, 'machine_name', 'address');
    }

    /**
     * Tipe data asli untuk atribut model.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'timestamp' => 'datetime',
        'quantity' => 'integer',
    ];
}