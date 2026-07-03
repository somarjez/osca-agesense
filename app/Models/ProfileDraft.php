<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileDraft extends Model
{
    protected $fillable = ['senior_citizen_id', 'created_by', 'step', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    public function seniorCitizen(): BelongsTo
    {
        return $this->belongsTo(SeniorCitizen::class);
    }
}
