<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Write a log entry. Safe to call anywhere — silently skips on failure
     * so a logging error never breaks the primary operation.
     */
    public static function record(
        string $action,
        Model  $subject,
        string $description = '',
        array  $metadata    = []
    ): void {
        try {
            static::create([
                'user_id'      => Auth::id(),
                'action'       => $action,
                'subject_type' => get_class($subject),
                'subject_id'   => $subject->getKey(),
                'description'  => $description,
                'metadata'     => $metadata ?: null,
                'ip_address'   => Request::ip(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging break the application
        }
    }
}
