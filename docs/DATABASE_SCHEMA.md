# Database Schema — AgeSense

> **System:** AgeSense — OSCA Senior Citizen Profiling and Analytics System
> **Last Updated:** 2026-06-04 — Added ml_results prediction-source/staleness/domain-risk/XAI columns, recommendation enrichment & citation columns, hospital/pharmacy accessibility metrics, route-distance/route-failure tables, and corrected GIS columns and default seeder accounts.
> **Database:** MySQL 8.0+ / MariaDB 10.6+, charset `utf8mb4_unicode_ci`

---

## Table of Contents

1. [Entity Relationship Overview](#1-entity-relationship-overview)
2. [Table: senior_citizens](#2-table-senior_citizens)
3. [Table: qol_surveys](#3-table-qol_surveys)
4. [Table: ml_results](#4-table-ml_results)
5. [Table: recommendations](#5-table-recommendations)
6. [Table: users](#6-table-users)
7. [Table: activity_logs](#7-table-activity_logs)
8. [Table: cluster_snapshots](#8-table-cluster_snapshots)
9. [Table: sessions](#9-table-sessions)
10. [Table: jobs](#10-table-jobs)
11. [Relationships Summary](#11-relationships-summary)
12. [Indexes and Performance Notes](#12-indexes-and-performance-notes)
13. [GIS Foundation](#13-gis-foundation)

---

## 1. Entity Relationship Overview

```
users
  └─(encoded_by)──► senior_citizens
                         │
                         ├──► qol_surveys ──► ml_results ──► recommendations
                         │
                         └──(soft delete via deleted_at)
```

**Core flow:**
1. An authenticated `user` creates a `senior_citizen` record.
2. One or more `qol_surveys` are submitted for that senior.
3. Each processed `qol_survey` triggers one `ml_result` (cluster + risk scores).
4. Each `ml_result` generates one or more `recommendations`.

**Soft deletes:** `senior_citizens`, `qol_surveys`, `ml_results`, and `recommendations` use Laravel `SoftDeletes` — records are flagged with `deleted_at` rather than physically removed. Hard deletes cascade to all child records.

---

## 2. Table: `senior_citizens`

Primary subject of all system operations. One record per registered senior citizen.

### Identity & Demographics

| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | Auto-increment primary key |
| `osca_id` | `varchar(20)` | NO | Unique — format `{BRGY}-YYYY-NNNN`, where `{BRGY}` is the barangay's first 3 letters uppercased (e.g. `SAM-2026-0001`), except Barangay I/II (Poblacion) which use `BR1`/`BR2`. Sequence = max existing +1 **including soft-deleted rows** (see `SeniorCitizen::generateOscaId`). |
| `first_name` | `varchar(100)` | NO | |
| `middle_name` | `varchar(100)` | YES | |
| `last_name` | `varchar(100)` | NO | |
| `name_extension` | `varchar(10)` | YES | Jr., Sr., III, etc. |
| `date_of_birth` | `date` | NO | |
| `gender` | `varchar(20)` | YES | Male / Female / Other |
| `marital_status` | `varchar(30)` | YES | |
| `contact_number` | `varchar(30)` | YES | |
| `place_of_birth` | `varchar(150)` | YES | |
| `religion` | `varchar(80)` | YES | |
| `ethnic_origin` | `varchar(80)` | YES | |
| `blood_type` | `varchar(10)` | YES | |
| `philsys_id` | `varchar(50)` | YES | Philippine national ID |
| `barangay` | `varchar(80)` | NO | One of 16 Pagsanjan barangays |

### Family

| Column | Type | Nullable | Description |
|---|---|---|---|
| `num_children` | `tinyint` | YES | |
| `num_working_children` | `tinyint` | YES | |
| `child_financial_support` | `varchar(10)` | YES | Yes / No |
| `spouse_working` | `varchar(10)` | YES | Yes / No |
| `household_size` | `tinyint` | YES | Total persons in household |

### Education & Skills

| Column | Type | Nullable | Description |
|---|---|---|---|
| `educational_attainment` | `varchar(80)` | YES | |
| `specialization` | `json` | YES | Array of skill strings |
| `community_service` | `json` | YES | Array of service involvement strings |

### Household & Living

| Column | Type | Nullable | Description |
|---|---|---|---|
| `living_with` | `json` | YES | Array: alone / children / spouse / etc. |
| `household_condition` | `json` | YES | Array: informal settler / owned / etc. |

### Economic

| Column | Type | Nullable | Description |
|---|---|---|---|
| `income_source` | `json` | YES | Array of income source strings |
| `real_assets` | `json` | YES | Array: house & lot / lot / etc. |
| `movable_assets` | `json` | YES | Array: automobile / mobile phone / etc. |
| `monthly_income_range` | `varchar(50)` | YES | Ordinal range string |
| `problems_needs` | `json` | YES | Array of self-reported problems |

### Health

| Column | Type | Nullable | Description |
|---|---|---|---|
| `medical_concern` | `json` | YES | Array of medical condition strings |
| `dental_concern` | `json` | YES | |
| `optical_concern` | `json` | YES | |
| `hearing_concern` | `json` | YES | |
| `social_emotional_concern` | `json` | YES | |
| `healthcare_difficulty` | `json` | YES | Array: cost / transport / etc. |
| `has_medical_checkup` | `boolean` | YES | |
| `checkup_schedule` | `varchar(100)` | YES | |

### Administrative

| Column | Type | Nullable | Description |
|---|---|---|---|
| `status` | `varchar(20)` | YES | Default: `active` |
| `encoded_by` | `bigint unsigned` | YES | FK → `users.id` (nullable) |
| `consent_given_at` | `timestamp` | YES | Date/time the senior gave data-collection consent |
| `consent_method` | `varchar(30)` | YES | `verbal` / `written` / `digital` |
| `deleted_at` | `timestamp` | YES | Soft delete timestamp |
| `created_at` | `timestamp` | NO | |
| `updated_at` | `timestamp` | NO | |

**Unique constraint:** `osca_id`

**Encrypted fields:** `contact_number`, `place_of_birth`, and `philsys_id` are stored using Laravel's `encrypted` cast (AES-256-CBC via `APP_KEY`). These fields cannot be used in `LIKE` / `WHERE` database queries — filter by name or barangay instead.

---

## 3. Table: `qol_surveys`

One row per Quality of Life survey administered to a senior. A senior may have multiple surveys over time.

### Identifiers

| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | PK |
| `senior_citizen_id` | `bigint unsigned` | NO | FK → `senior_citizens.id` |
| `survey_date` | `date` | YES | Date the survey was conducted |
| `survey_version` | `varchar(10)` | YES | Default: `v1` |
| `status` | `varchar(20)` | YES | `draft` / `submitted` / `processed` |

### Survey Responses (Section A — Overall QoL)

| Column | Type | Range |
|---|---|---|
| `a1` | `tinyint` | 1–5 |
| `a2` | `tinyint` | 1–5 |
| `a3` | `tinyint` | 1–5 |
| `a4` | `tinyint` | 1–5 |

### Survey Responses (Section B — Physical Health)

| Column | Notes |
|---|---|
| `b1` | Energy level |
| `b2` | Pain (**reverse-scored**) |
| `b3` | Health limits self-care (**reverse-scored**) |
| `b4` | Outside activity |
| `b5` | Mobility |

### Survey Responses (Sections C–H)

All items `c1`–`h2` are `tinyint` (1–5). Reverse-scored items: `c3` (loneliness) and `d4` (income limits).

Sections: C = Psychological, D = Independence, E = Social, F = Home/Neighbourhood, G = Financial, H = Spirituality (optional).

### Computed Domain Scores

| Column | Type | Description |
|---|---|---|
| `score_qol` | `decimal(5,4)` | Overall QoL (0–1) |
| `score_physical` | `decimal(5,4)` | Physical health domain (0–1) |
| `score_psychological` | `decimal(5,4)` | Psychological domain (0–1) |
| `score_independence` | `decimal(5,4)` | Independence/autonomy domain (0–1) |
| `score_social` | `decimal(5,4)` | Social relationships domain (0–1) |
| `score_environment` | `decimal(5,4)` | Home & neighbourhood domain (0–1) |
| `score_financial` | `decimal(5,4)` | Financial domain (0–1) |
| `score_spirituality` | `decimal(5,4)` | Spirituality domain (0–1) |
| `overall_score` | `decimal(5,4)` | Weighted overall score (0–1) |

### Administrative

| Column | Type | Description |
|---|---|---|
| `deleted_at` | `timestamp` | Soft delete (cascades on senior archive) |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

---

## 4. Table: `ml_results`

One row per ML pipeline execution. A senior may have many results over time (one per survey processed). The system always uses the latest result for display.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `senior_citizen_id` | `bigint unsigned` | FK → `senior_citizens.id` |
| `qol_survey_id` | `bigint unsigned` | FK → `qol_surveys.id` (nullable) |
| `cluster_id` | `tinyint` | Raw KMeans cluster ID (0, 1, 2, 3) |
| `cluster_named_id` | `tinyint` | Human-friendly cluster ID (1, 2, 3, 4) |
| `cluster_name` | `varchar(100)` | e.g. "High Functioning" |
| `ic_risk` | `decimal(5,4)` | Intrinsic Capacity risk score (0–1) |
| `ic_risk_level` | `varchar(20)` | LOW / MODERATE / HIGH |
| `env_risk` | `decimal(5,4)` | Environment risk score (0–1) |
| `env_risk_level` | `varchar(20)` | |
| `func_risk` | `decimal(5,4)` | Functional Ability risk score (0–1) |
| `func_risk_level` | `varchar(20)` | |
| `composite_risk` | `decimal(5,4)` | Weighted overall risk score (0–1) |
| `overall_risk_level` | `varchar(20)` | LOW / MODERATE / HIGH (uppercase). Urgency expressed via `priority_flag`. |
| `wellbeing_score` | `decimal(5,4)` | Inverse of composite risk (0–1, higher = better) |
| `section_scores` | `json` | Object: sec1_age_risk … overall_wellbeing |
| `xai_data` | `json` | Explainability payload (per-feature contributions) for the model-insights view (nullable) |
| `raw_output` | `json` | Full Python service response |
| `model_version` | `varchar(30)` | Model artefact version tag |
| `processed_at` | `timestamp` | When the pipeline ran |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

**Note on `section_scores` JSON keys:** `sec1_age_risk`, `sec2_family_support`, `sec3_hr_score`, `sec4_dependency_risk`, `sec5_eco_stability`, `sec6_health_score`, `overall_wellbeing`.

### Prediction source & freshness

These columns drive the two-path prediction system (see [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md)) and staleness tracking.

| Column | Type | Description |
|---|---|---|
| `prediction_source` | `varchar(20)` | `notebook_cache` (283 seeded seniors) or `live_model` (default). |
| `is_cached_prediction` | `boolean` | True when the result came from the notebook cache rather than a live model run. |
| `critical_flag` | `boolean` | Set when the result requires urgent attention (drives `priority_flag` semantics). |
| `is_stale` | `boolean` | True when the source survey/profile changed after this result was scored. |
| `stale_reason` | `varchar(255)` | Why the result is stale (nullable). |
| `stale_at` | `timestamp` | When the result was marked stale (nullable). |
| `scored_at` | `timestamp` | When the prediction was scored (nullable). |

### Rule-based domain risks & WHO sub-scores

Added so the dashboard/reports can show per-domain risk breakdowns alongside the model's composite risk. All `decimal(5,4)`, nullable.

| Column | Description |
|---|---|
| `risk_medical`, `risk_financial`, `risk_social`, `risk_functional`, `risk_housing`, `risk_hc_access`, `risk_sensory` | Rule-based domain risk scores (0–1). |
| `rule_composite` | Weighted composite of all rule-based domain risks. |
| `ic_score`, `env_score`, `func_score`, `qol_score` | WHO Intrinsic Capacity / Environment / Functional Ability / Quality of Life sub-scores. |

---

## 5. Table: `recommendations`

One row per generated recommendation. Each `ml_result` produces multiple recommendations across different domains.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `senior_citizen_id` | `bigint unsigned` | FK → `senior_citizens.id` |
| `ml_result_id` | `bigint unsigned` | FK → `ml_results.id` |
| `priority` | `tinyint` | Integer rank (1 = highest urgency) |
| `type` | `varchar(30)` | `domain` / `general` |
| `domain` | `varchar(20)` | `ic` / `env` / `func` / `general` |
| `category` | `varchar(30)` | `health` / `financial` / `social` / `functional` / `hc_access` / `general` |
| `action` | `text` | Plain-language recommended action |
| `recommendation_code` | `varchar(50)` | Stable code for the recommendation template (nullable) |
| `service_provider` | `varchar(255)` | Responsible agency/office for the action (nullable) |
| `evidence_source` | `text` | Evidence/policy basis for the recommendation (nullable) |
| `apa_reference` | `text` | APA-formatted citation for the evidence source (nullable) |
| `source_type` | `varchar(100)` | Type of citation source, e.g. law / guideline / study (nullable) |
| `eligibility_basis` | `text` | Why the senior is eligible for this service (nullable) |
| `documents_needed` | `json` | Documents the senior must prepare (nullable) |
| `requires_human_validation` | `boolean` | Default `true` — flags AI-generated actions for staff review |
| `urgency` | `varchar(20)` | `immediate` / `urgent` / `planned` / `maintenance` |
| `risk_level` | `varchar(20)` | Risk level that triggered this recommendation |
| `status` | `varchar(20)` | `pending` / `in_progress` / `completed` / `dismissed` |
| `notes` | `text` | Staff notes |
| `target_date` | `date` | Target completion date |
| `assigned_to` | `bigint unsigned` | FK → `users.id` (nullable) |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

---

## 6. Table: `users`

Authentication table. Auth scaffolding is Laravel Breeze; roles and permissions are handled by `spatie/laravel-permission`. Every user has exactly one of three roles: `admin`, `encoder`, or `viewer` (stored in the `roles` / `model_has_roles` permission tables, not on this table).

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `name` | `varchar(255)` | Display name |
| `email` | `varchar(255)` | Unique login email |
| `email_verified_at` | `timestamp` | Email verification timestamp |
| `password` | `varchar(255)` | Bcrypt hash |
| `remember_token` | `varchar(100)` | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

**Default accounts** (created by `UserSeeder`, idempotent via `updateOrCreate`):

| Email | Password | Role |
|---|---|---|
| `admin@osca.local` | `Admin@OSCA2026!` | admin |
| `encoder@osca.local` | `Encoder@OSCA2026!` | encoder |
| `viewer@osca.local` | `Viewer@OSCA2026!` | viewer |

These seeded credentials must be changed before any production/public deployment.

---

## 7. Table: `activity_logs`

Audit trail of all create/update/delete/restore operations on senior, survey, and recommendation records. Populated automatically by `ActivityLogObserver` (wired in `AppServiceProvider`). Viewable at `/activity-log`.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `user_id` | `bigint unsigned` | FK → `users.id` (nullable — null for system/seeder actions) |
| `action` | `varchar(32)` | `created` / `updated` / `archived` / `force_deleted` / `restored` |
| `subject_type` | `varchar(128)` | Fully-qualified model class, e.g. `App\Models\SeniorCitizen` |
| `subject_id` | `bigint unsigned` | PK of the affected record |
| `description` | `text` | Human-readable summary (e.g. "Senior Juan Dela Cruz was created") |
| `metadata` | `json` | Optional extra context (nullable) |
| `ip_address` | `varchar(45)` | Client IP at time of action |
| `created_at` | `timestamp` | When the action occurred |

**Indexes:** `(subject_type, subject_id)`, `action`, `created_at`.

---

## 8. Table: `cluster_snapshots`

Longitudinal tracking of cluster composition over time. Populated by the `osca:snapshot-clusters` command (`SnapshotClusters`) and the admin-only `POST /reports/cluster/snapshot` action.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `snapshot_date` | `date` | Date the snapshot was taken |
| `cluster_named_id` | `tinyint` | 1, 2, or 3 |
| `cluster_name` | `varchar(100)` | |
| `member_count` | `int` | Number of seniors in this cluster on this date |
| `avg_composite_risk` | `decimal(5,4)` | Average composite risk for the cluster |
| `avg_ic_risk` | `decimal(5,4)` | |
| `avg_env_risk` | `decimal(5,4)` | |
| `avg_func_risk` | `decimal(5,4)` | |
| `created_at` | `timestamp` | |

---

## 9. Table: `sessions`

Stores user session data. Used when `SESSION_DRIVER=database`.

| Column | Type | Description |
|---|---|---|
| `id` | `varchar(255)` | Session token (PK) |
| `user_id` | `bigint unsigned` | FK → `users.id` (nullable, null if unauthenticated) |
| `ip_address` | `varchar(45)` | |
| `user_agent` | `text` | |
| `payload` | `longtext` | Serialized session data |
| `last_activity` | `int` | Unix timestamp of last request |

---

## 10. Table: `jobs`

Laravel queue jobs table. `QUEUE_CONNECTION=database` is the default (set in `.env.example`). The queue worker starts automatically in the background when `start.bat` is run. Batch ML inference dispatches `ProcessMlBatch` jobs here — each job processes 100 seniors.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `queue` | `varchar(255)` | Queue name (default: `default`) |
| `payload` | `longtext` | Serialized job |
| `attempts` | `tinyint` | Number of times attempted |
| `reserved_at` | `int` | Unix timestamp when picked up by a worker |
| `available_at` | `int` | Unix timestamp when available to be processed |
| `created_at` | `int` | Unix timestamp |

### Table: `job_batches`

Tracks the overall progress of a `Bus::batch()` dispatch. Created when batch ML inference is triggered from `/ml/batch`. Used by the `/ml/batch/status` polling endpoint to report progress.

| Column | Type | Description |
|---|---|---|
| `id` | `varchar(255)` | Batch UUID (PK) |
| `name` | `varchar(255)` | Batch label |
| `total_jobs` | `int` | Total jobs in batch |
| `pending_jobs` | `int` | Jobs not yet finished |
| `failed_jobs` | `int` | Jobs that threw an exception |
| `failed_job_ids` | `longtext` | JSON array of failed job IDs |
| `options` | `mediumtext` | Serialized batch options |
| `cancelled_at` | `int` | Unix timestamp if cancelled (nullable) |
| `created_at` | `int` | |
| `finished_at` | `int` | Unix timestamp when all jobs completed (nullable) |

---

## 11. Relationships Summary

| Relationship | Type | FK |
|---|---|---|
| `SeniorCitizen` → `QolSurvey` | `hasMany` | `qol_surveys.senior_citizen_id` |
| `SeniorCitizen` → `MlResult` | `hasMany` | `ml_results.senior_citizen_id` |
| `SeniorCitizen` → `Recommendation` | `hasMany` | `recommendations.senior_citizen_id` |
| `SeniorCitizen` → `latestMlResult` | `hasOne` (latest by ID) | `ml_results.senior_citizen_id` |
| `QolSurvey` → `MlResult` | `hasOne` | `ml_results.qol_survey_id` |
| `MlResult` → `SeniorCitizen` | `belongsTo` | `ml_results.senior_citizen_id` |
| `MlResult` → `QolSurvey` | `belongsTo` | `ml_results.qol_survey_id` |
| `MlResult` → `Recommendation` | `hasMany` | `recommendations.ml_result_id` |
| `Recommendation` → `SeniorCitizen` | `belongsTo` | `recommendations.senior_citizen_id` |
| `Recommendation` → `MlResult` | `belongsTo` | `recommendations.ml_result_id` |

**Soft delete cascade:** When a `SeniorCitizen` is soft-deleted (archived), all linked `QolSurvey` records are also soft-deleted via the `SeniorCitizen::boot()` observer. When restored, the surveys are also restored.

---

## 12. Indexes and Performance Notes

| Table | Index | Purpose |
|---|---|---|
| `senior_citizens` | `UNIQUE(osca_id)` | Enforce unique OSCA ID |
| `senior_citizens` | `INDEX(deleted_at)` | Fast active-record filtering |
| `senior_citizens` | `INDEX(barangay)` | Barangay filter queries |
| `ml_results` | `INDEX(senior_citizen_id)` | Join to senior |
| `ml_results` | `INDEX(overall_risk_level)` | Risk level filter |
| `ml_results` | `INDEX(cluster_named_id)` | Cluster filter |
| `qol_surveys` | `INDEX(senior_citizen_id)` | Join to senior |
| `qol_surveys` | `INDEX(status)` | Filter by draft/processed |
| `recommendations` | `INDEX(senior_citizen_id, status)` | Pending recommendation counts |
| `sessions` | `INDEX(user_id)` | Session lookup by user |
| `sessions` | `INDEX(last_activity)` | Session expiry cleanup |

**Query patterns to note:**
- `ClusterAnalyticsService::latestResultIds()` uses `MAX(id) GROUP BY senior_citizen_id` — this assumes the highest ID is always the most recent, which is true as long as IDs are auto-incremented in insertion order.
- The cluster analysis page eagerly loads `seniorCitizen` on `MlResult` and filters out null relations (`whereHas('seniorCitizen')`) to exclude archived seniors.

---

## 13. GIS Foundation

The GIS module is implemented and live. It provides a Leaflet-based interactive map at `/reports/gis` showing senior distribution, facility overlays, and risk zone visualizations across Pagsanjan.

### GIS columns on `senior_citizens`

| Column | Type | Description |
|---|---|---|
| `latitude` | `decimal(10,7)` NULL | Generalized or barangay-centroid coordinate |
| `longitude` | `decimal(10,7)` NULL | Generalized or barangay-centroid coordinate |
| `location_source` | `varchar(255)` NULL | `barangay_generalized` / `barangay_centroid` (generated by `gis:geocode`). `manual_pin` / `gps_capture` are **legacy verified sources** — recognized and protected from overwrite, but no longer written by the app (the profile coordinate picker was removed). |
| `location_accuracy` | `varchar(255)` NULL | `barangay_level` / `approximate` (generated). `verified/manual` is legacy (see `location_source`). |
| `location_verified_at` | `timestamp` NULL | When coordinates were last verified (set only for verified pins) |

> For privacy, coordinates are generalized to barangay-centroid level by default and do not represent exact home addresses.

### Table: `facilities`

Stores public/community points of interest used as accessibility reference points.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `name` | `varchar(255)` | Facility name |
| `type` | `varchar(255)` | e.g. `health_center`, `barangay_hall`, `market`, `church` |
| `barangay` | `varchar(255)` NULL | Barangay where located |
| `address` | `varchar(255)` NULL | Street address |
| `latitude` | `decimal(10,7)` | |
| `longitude` | `decimal(10,7)` | |
| `source` | `varchar(255)` NULL | Data source (e.g. `osm`, `lgu`, `manual`) |
| `osm_id` | `varchar` NULL | OpenStreetMap element id for facilities imported via `facilities:import-osm` (used for dedupe) |
| `is_active` | `boolean` | Default `true` — inactive facilities excluded from map |
| `created_at` / `updated_at` | `timestamp` | |

**Indexes:** `type`, `barangay`, `is_active`, `(latitude, longitude)`

Seeded by `PagsanjanFacilitySeeder` with health centers, barangay halls, markets, and churches across all barangays.

### Table: `senior_accessibility_metrics`

Derived proximity metrics per senior to their nearest key facilities. Calculated post-seeding.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `senior_citizen_id` | `bigint unsigned` | FK → `senior_citizens.id` (cascade delete) |
| `nearest_health_center_id` | `bigint unsigned` NULL | FK → `facilities.id` (null on delete) |
| `distance_to_health_center_m` | `decimal(10,2)` NULL | Distance in metres |
| `nearest_hospital_id` | `bigint unsigned` NULL | FK → `facilities.id` |
| `distance_to_hospital_m` | `decimal(10,2)` NULL | Distance in metres |
| `nearest_pharmacy_id` | `bigint unsigned` NULL | FK → `facilities.id` |
| `distance_to_pharmacy_m` | `decimal(10,2)` NULL | Distance in metres |
| `nearest_barangay_hall_id` | `bigint unsigned` NULL | FK → `facilities.id` |
| `distance_to_barangay_hall_m` | `decimal(10,2)` NULL | Distance in metres |
| `nearest_market_id` | `bigint unsigned` NULL | FK → `facilities.id` |
| `distance_to_market_m` | `decimal(10,2)` NULL | Distance in metres |
| `accessibility_score` | `decimal(6,4)` NULL | Composite 0–1 score (lower = harder to reach) |
| `calculated_at` | `timestamp` NULL | When metrics were last computed |
| `created_at` / `updated_at` | `timestamp` | |

**Indexes:** `senior_citizen_id`, `calculated_at`

### Table: `senior_facility_route_distances`

Caches OpenRouteService road-network distances per senior/facility pair so map popups don't repeatedly call the external API. Written by `gis:cache-route-distances` and the `route-distance` endpoint.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `senior_citizen_id` | `bigint unsigned` | FK → `senior_citizens.id` (cascade delete) |
| `facility_id` | `bigint unsigned` | FK → `facilities.id` (cascade delete) |
| `origin_latitude` / `origin_longitude` | `decimal(10,7)` | Senior coordinate used for the lookup |
| `destination_latitude` / `destination_longitude` | `decimal(10,7)` | Facility coordinate used for the lookup |
| `route_distance_m` | `decimal(10,2)` | Road-network distance in metres |
| `route_duration_s` | `decimal(10,2)` NULL | Travel duration in seconds |
| `provider` | `varchar(40)` | Default `openrouteservice` |
| `calculated_at` | `timestamp` NULL | When the route was computed |
| `created_at` / `updated_at` | `timestamp` | |

**Unique:** `(senior_citizen_id, facility_id)`. **Indexes:** `(senior_citizen_id, route_distance_m)`, `facility_id`, `calculated_at`.

### Table: `senior_facility_route_failures`

Records permanent route lookup failures (e.g. no road route exists) so the system reuses the failure instead of re-calling the provider.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | PK |
| `senior_citizen_id` | `bigint unsigned` | FK → `senior_citizens.id` (cascade delete) |
| `facility_id` | `bigint unsigned` | FK → `facilities.id` (cascade delete) |
| `origin_latitude` / `origin_longitude` | `decimal(10,7)` | Senior coordinate |
| `destination_latitude` / `destination_longitude` | `decimal(10,7)` | Facility coordinate |
| `provider` | `varchar(40)` | Default `openrouteservice` |
| `status_code` | `smallint unsigned` NULL | HTTP/error status from the provider |
| `error_message` | `varchar(500)` NULL | Failure detail |
| `failed_at` | `timestamp` NULL | When the failure was recorded |
| `created_at` / `updated_at` | `timestamp` | |

**Unique:** `(senior_citizen_id, facility_id)`. **Index:** `failed_at`.

### GIS API routes

These are served from `routes/web.php` (inside the authenticated session group), **not** `routes/api.php`, so browser `fetch` calls are session-authenticated.

| Route | Description |
|---|---|
| `GET /api/gis/seniors` | GeoJSON FeatureCollection of all active seniors (generalized coords, risk level, cluster) |
| `GET /api/gis/facilities` | GeoJSON FeatureCollection of all active facilities |
| `GET /api/gis/boundary/pagsanjan` | Municipal boundary GeoJSON |
| `GET /api/gis/boundary/barangays` | Barangay-level boundary GeoJSON (cached 24h) |
| `GET /api/gis/route-distance` | Road-network distance/duration between an origin and destination (throttled 60/min/user; caches to `senior_facility_route_distances`) |

### Frontend dependencies

Leaflet (`^1.9.4`) and `leaflet.markercluster` (`^1.5.3`) are bundled via Vite (`npm run build`). `window.L` is exposed globally from `resources/js/app.js`.
