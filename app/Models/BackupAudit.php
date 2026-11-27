<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'backup_path',
        'kind',
        'status',
        'details',
        'duration_ms',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'checked_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }
}
