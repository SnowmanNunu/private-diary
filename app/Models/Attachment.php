<?php

namespace App\Models;

use App\Services\AttachmentTypes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'entry_id',
        'path',
        'original_name',
        'mime',
        'size',
        'kind',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }

    public function badge(): string
    {
        return AttachmentTypes::badge($this->original_name);
    }

    public function sizeLabel(): string
    {
        return Number::fileSize((int) $this->size, precision: 1);
    }

    public function markdown(string $url): string
    {
        return $this->isImage()
            ? '!['.$this->original_name.']('.$url.')'
            : '['.$this->original_name.']('.$url.')';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }
}
