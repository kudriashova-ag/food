<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    protected $fillable = [
        'user_id',
        'filename',
        'total_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'errors',
        'status',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
