<?php

namespace App\Exports;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Support\DbHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RegistryExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping, WithStyles
{
    public function query(): Builder
    {
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        return SeniorCitizen::active()
            ->leftJoin('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                    ->whereIn('ml_results.id', $latestIds);
            })
            ->select(
                'senior_citizens.osca_id',
                'senior_citizens.last_name',
                'senior_citizens.first_name',
                'senior_citizens.middle_name',
                'senior_citizens.date_of_birth',
                DB::raw(DbHelper::ageExpr('senior_citizens.date_of_birth')),
                'senior_citizens.gender',
                'senior_citizens.marital_status',
                'senior_citizens.barangay',
                'senior_citizens.monthly_income_range',
                'senior_citizens.status',
                'ml_results.cluster_named_id as cluster',
                'ml_results.cluster_name',
                'ml_results.overall_risk_level as risk_level',
                'ml_results.composite_risk',
                'ml_results.ic_risk',
                'ml_results.env_risk',
                'ml_results.func_risk',
                'ml_results.wellbeing_score',
                'ml_results.priority_flag',
                'ml_results.processed_at as ml_processed_at'
            )
            ->orderBy('senior_citizens.barangay')
            ->orderBy('senior_citizens.last_name');
    }

    public function map($row): array
    {
        return [
            $row->osca_id, $row->last_name, $row->first_name, $row->middle_name,
            $row->date_of_birth, $row->age, $row->gender, $row->marital_status, $row->barangay,
            $row->monthly_income_range, $row->status,
            $row->cluster, $row->cluster_name, $row->risk_level,
            $row->composite_risk, $row->ic_risk, $row->env_risk, $row->func_risk,
            $row->wellbeing_score, $row->priority_flag, $row->ml_processed_at,
        ];
    }

    public function headings(): array
    {
        return [
            'OSCA ID', 'Last Name', 'First Name', 'Middle Name',
            'Date of Birth', 'Age', 'Gender', 'Marital Status', 'Barangay',
            'Monthly Income Range', 'Status',
            'Profile Group', 'Profile Group Name', 'Risk Level',
            'Composite Risk', 'IC Risk', 'Env Risk', 'Func Risk',
            'Wellbeing Score', 'Priority Flag', 'ML Processed At',
        ];
    }

    public function chunkSize(): int
    {
        return 200;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
