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
     *
     * $subject is nullable for events with no domain-model subject —
     * currently only a failed login attempt against a non-existent email
     * (App\Listeners\LogAuthenticationActivity), which resolves no User at
     * all. Every other caller in the app passes a real Model.
     */
    public static function record(
        string $action,
        ?Model $subject,
        string $description = '',
        array $metadata = []
    ): void {
        try {
            static::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'subject_type' => $subject ? get_class($subject) : null,
                'subject_id' => $subject?->getKey(),
                'description' => $description,
                'metadata' => $metadata ?: null,
                'ip_address' => Request::ip(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging break the application
        }
    }
}
