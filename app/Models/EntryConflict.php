<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EntryConflict extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entry_id',
        'entry_date',
        'title',
        'body',
        'tags',
        'mood',
        'weather',
        'source',
        'client_revision',
        'server_revision',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
        ];
    }
}
