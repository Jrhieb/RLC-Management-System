<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('program_activity_id')
                ->constrained('program_activities')
                ->restrictOnDelete();

            $table->foreignId('beneficiary_id')
                ->constrained('beneficiaries')
                ->restrictOnDelete();

            $table->timestamp('checked_in_at');
            $table->string('source', 20)->default('manual');

            $table->foreignId('recorded_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['program_activity_id', 'beneficiary_id'],
                'activity_attendance_unique_beneficiary'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_attendances');
    }
};
