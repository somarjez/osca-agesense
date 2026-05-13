<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClusterSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'cluster_id',
        'cluster_name',
        'member_count',
        'avg_composite_risk',
        'avg_ic_risk',
        'avg_env_risk',
        'avg_func_risk',
        'barangay_distribution',
        'risk_level_distribution',
    ];

    protected $casts = [
        'snapshot_date'           => 'date',
        'barangay_distribution'   => 'array',
        'risk_level_distribution' => 'array',
    ];
}
