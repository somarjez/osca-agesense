<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Senior Citizens (core profile) ────────────────────────────────────
        Schema::create('senior_citizens', function (Blueprint $table) {
            $table->id();
            $table->string('osca_id')->unique()->comment('System-generated OSCA ID');

            // I. Identifying Information
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('name_extension')->nullable()->comment('Jr., Sr., II, III');
            $table->string('barangay');
            $table->date('date_of_birth');
            // age is computed by the application from date_of_birth; stored as a plain
            // column because MySQL 8 disallows CURDATE() in generated stored columns.
            $table->integer('age')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->enum('marital_status', ['Single','Married','Widowed','Separated','Divorced','Annulled'])->nullable();
            $table->enum('gender', ['Male','Female','Prefer not to say'])->nullable();
            $table->string('religion')->nullable();
            $table->string('ethnic_origin')->nullable();
            $table->string('blood_type')->nullable();
            $table->string('philsys_id')->nullable()->comment('PhilSys / National ID');

            // II. Family Composition
            $table->integer('num_children')->default(0);
            $table->integer('num_working_children')->default(0);
            $table->enum('child_financial_support', ['Yes','No','Occasional','N/A'])->nullable();
            $table->enum('spouse_working', ['Yes','No','Deceased','N/A'])->nullable();
            $table->integer('household_size')->default(1);

            // III. Education / HR Profile
            $table->string('educational_attainment')->nullable();
            $table->json('specialization')->nullable()->comment('Multi-select array');
            $table->json('community_service')->nullable()->comment('Multi-select array');

            // IV. Dependency Profile
            $table->json('living_with')->nullable()->comment('Multi-select array');
            $table->json('household_condition')->nullable()->comment('Multi-select array');

            // V. Economic Profile
            $table->json('income_source')->nullable();
            $table->json('real_assets')->nullable();
            $table->json('movable_assets')->nullable();
            $table->string('monthly_income_range')->nullable();
            $table->json('problems_needs')->nullable();

            // VI. Health Profile
            $table->json('medical_concern')->nullable();
            $table->string('dental_concern')->nullable();
            $table->string('optical_concern')->nullable();
            $table->string('hearing_concern')->nullable();
            $table->json('social_emotional_concern')->nullable();
            $table->string('healthcare_difficulty')->nullable();
            $table->boolean('has_medical_checkup')->default(false);
            $table->string('checkup_schedule')->nullable();

            // Admin
            $table->enum('status', ['active','inactive','deceased'])->default('active');
            $table->string('encoded_by')->nullable()->comment('User who encoded the record');
            $table->timestamps();
            $table->softDeletes();

            $table->index('barangay');
            $table->index('status');
            $table->index(['last_name', 'first_name']);
        });

        // ── QoL Survey Responses ──────────────────────────────────────────────
        Schema::create('qol_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_citizen_id')->constrained('senior_citizens')->cascadeOnDelete();
            $table->string('survey_version')->default('v1');
            $table->date('survey_date');

            // A. Overall Quality of Life
            $table->tinyInteger('a1_enjoy_life')->nullable();
            $table->tinyInteger('a2_life_satisfaction')->nullable();
            $table->tinyInteger('a3_future_outlook')->nullable();
            $table->tinyInteger('a4_meaningfulness')->nullable();

            // B. Physical Health
            $table->tinyInteger('b1_physical_energy')->nullable();
            $table->tinyInteger('b2_pain_discomfort')->nullable()->comment('Reverse-scored');
            $table->tinyInteger('b3_health_self_care')->nullable()->comment('Reverse-scored');
            $table->tinyInteger('b4_health_outside')->nullable();
            $table->tinyInteger('b5_mobility')->nullable();

            // C. Psychological & Emotional
            $table->tinyInteger('c1_happiness')->nullable();
            $table->tinyInteger('c2_calm_peace')->nullable();
            $table->tinyInteger('c3_loneliness')->nullable()->comment('Reverse-scored');
            $table->tinyInteger('c4_confidence')->nullable();

            // D. Independence, Control & Autonomy
            $table->tinyInteger('d1_independence')->nullable();
            $table->tinyInteger('d2_time_control')->nullable();
            $table->tinyInteger('d3_life_control')->nullable();
            $table->tinyInteger('d4_income_limits')->nullable()->comment('Reverse-scored');

            // E. Social Relationships & Participation
            $table->tinyInteger('e1_social_support')->nullable();
            $table->tinyInteger('e2_close_person')->nullable();
            $table->tinyInteger('e3_community_opportunities')->nullable();
            $table->tinyInteger('e4_participation')->nullable();
            $table->tinyInteger('e5_respect')->nullable();

            // F. Home & Neighborhood
            $table->tinyInteger('f1_home_safety')->nullable();
            $table->tinyInteger('f2_neighborhood_safety')->nullable();
            $table->tinyInteger('f3_service_access')->nullable();
            $table->tinyInteger('f4_home_comfort')->nullable();

            // G. Financial Situation
            $table->tinyInteger('g1_household_expenses')->nullable();
            $table->tinyInteger('g2_medical_afford')->nullable();
            $table->tinyInteger('g3_personal_wants')->nullable();

            // H. Spirituality (optional)
            $table->tinyInteger('h1_belief_comfort')->nullable();
            $table->tinyInteger('h2_belief_practice')->nullable();

            // Computed domain scores (filled after submission)
            $table->decimal('score_qol', 5, 3)->nullable();
            $table->decimal('score_physical', 5, 3)->nullable();
            $table->decimal('score_psychological', 5, 3)->nullable();
            $table->decimal('score_independence', 5, 3)->nullable();
            $table->decimal('score_social', 5, 3)->nullable();
            $table->decimal('score_environment', 5, 3)->nullable();
            $table->decimal('score_financial', 5, 3)->nullable();
            $table->decimal('score_spirituality', 5, 3)->nullable();
            $table->decimal('overall_score', 5, 3)->nullable();

            $table->enum('status', ['draft','submitted','processed'])->default('draft');
            $table->timestamps();

            $table->index('senior_citizen_id');
            $table->index('survey_date');
            $table->index('status');
        });

        // ── ML Inference Results ──────────────────────────────────────────────
        Schema::create('ml_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_citizen_id')->constrained('senior_citizens')->cascadeOnDelete();
            $table->foreignId('qol_survey_id')->nullable()->constrained('qol_surveys')->nullOnDelete();
            $table->string('model_version')->default('v1');

            // Cluster
            $table->integer('cluster_id')->nullable()->comment('0-indexed raw KMeans cluster');
            $table->integer('cluster_named_id')->nullable()->comment('1,2,3 human-readable');
            $table->string('cluster_name')->nullable();

            // Risk Scores (0-1 continuous)
            $table->decimal('ic_risk', 5, 4)->nullable()->comment('Intrinsic Capacity risk');
            $table->decimal('env_risk', 5, 4)->nullable()->comment('Environment risk');
            $table->decimal('func_risk', 5, 4)->nullable()->comment('Functional risk');
            $table->decimal('composite_risk', 5, 4)->nullable();
            $table->decimal('wellbeing_score', 5, 4)->nullable();

            // Risk Levels
            $table->enum('ic_risk_level', ['low','moderate','high','critical'])->nullable();
            $table->enum('env_risk_level', ['low','moderate','high','critical'])->nullable();
            $table->enum('func_risk_level', ['low','moderate','high','critical'])->nullable();
            $table->enum('overall_risk_level', ['LOW','MODERATE','HIGH','CRITICAL'])->nullable();

            // Section Scores from Preprocessing
            $table->json('section_scores')->nullable();

            // Raw model output
            $table->json('raw_output')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('senior_citizen_id');
            $table->index('overall_risk_level');
            $table->index('cluster_named_id');
        });

        // ── Recommendations ───────────────────────────────────────────────────
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_result_id')->constrained('ml_results')->cascadeOnDelete();
            $table->foreignId('senior_citizen_id')->constrained('senior_citizens')->cascadeOnDelete();

            $table->integer('priority');
            $table->enum('type', ['cluster','domain','section','general']);
            $table->string('domain')->nullable()->comment('ic_risk, env_risk, func_risk, general');
            $table->string('category')->nullable()->comment('health, financial, social, functional, hc_access');
            $table->text('action');
            $table->enum('urgency', ['immediate','urgent','planned','maintenance'])->nullable();
            $table->enum('risk_level', ['low','moderate','high','critical'])->nullable();

            // Status tracking
            $table->enum('status', ['pending','in_progress','completed','dismissed'])->default('pending');
            $table->text('notes')->nullable();
            $table->date('target_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['senior_citizen_id', 'status']);
            $table->index('ml_result_id');
        });

        // ── Cluster Snapshots (for analytics) ────────────────────────────────
        Schema::create('cluster_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->integer('cluster_id');
            $table->string('cluster_name');
            $table->integer('member_count');
            $table->decimal('avg_composite_risk', 5, 4)->nullable();
            $table->decimal('avg_ic_risk', 5, 4)->nullable();
            $table->decimal('avg_env_risk', 5, 4)->nullable();
            $table->decimal('avg_func_risk', 5, 4)->nullable();
            $table->json('barangay_distribution')->nullable();
            $table->json('risk_level_distribution')->nullable();
            $table->timestamps();

            $table->index('snapshot_date');
        });

        // ── Activity / Audit Log ──────────────────────────────────────────────
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('cluster_snapshots');
        Schema::dropIfExists('recommendations');
        Schema::dropIfExists('ml_results');
        Schema::dropIfExists('qol_surveys');
        Schema::dropIfExists('senior_citizens');
    }
};
