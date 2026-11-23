<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppi_household_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->uuid('submission_key')->unique();
            $table->char('household_key', 64)->unique();
            $table->foreignId('ppi_scorecard_id')->constrained('ppi_scorecards')->restrictOnDelete();
            $table->string('child_first_name', 100);
            $table->string('child_middle_name', 100)->nullable();
            $table->string('child_last_name', 100);
            $table->string('child_suffix', 10)->nullable();
            $table->string('child_sex', 10);
            $table->date('child_date_of_birth');
            $table->string('guardian_name', 150);
            $table->string('guardian_relationship', 50);
            $table->string('contact_number', 32);
            $table->string('email')->nullable();
            $table->string('barangay');
            $table->string('city_municipality');
            $table->string('complete_address', 500);
            $table->smallInteger('ppi_score')->nullable();
            $table->string('status', 32)->default('submitted')->index();
            $table->timestamp('privacy_acknowledged_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['ppi_score', 'submitted_at']);
        });

        Schema::create('ppi_household_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ppi_household_application_id')->constrained('ppi_household_applications')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('relationship', 50)->nullable();
            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->index(['ppi_household_application_id', 'position'], 'ppi_members_application_position_index');
        });

        Schema::create('ppi_application_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ppi_household_application_id')->constrained('ppi_household_applications')->cascadeOnDelete();
            $table->foreignId('ppi_question_id')->constrained('ppi_questions')->restrictOnDelete();
            $table->foreignId('ppi_answer_option_id')->constrained('ppi_answer_options')->restrictOnDelete();
            $table->smallInteger('points_awarded');
            $table->timestamps();
            $table->unique(['ppi_household_application_id', 'ppi_question_id'], 'ppi_answers_application_question_unique');
        });

        Schema::create('ppi_application_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ppi_household_application_id')->constrained('ppi_household_applications')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ppi_household_application_id', 'created_at'], 'ppi_status_events_application_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppi_application_status_events');
        Schema::dropIfExists('ppi_application_answers');
        Schema::dropIfExists('ppi_household_members');
        Schema::dropIfExists('ppi_household_applications');
    }
};
