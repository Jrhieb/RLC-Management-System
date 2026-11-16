<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiary_applications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('guardian_name');
            $table->string('contact_number', 32);
            $table->string('email')->nullable();
            $table->string('barangay');
            $table->string('municipality');
            $table->text('reason');
            $table->string('status')->default('pending_review')->index();
            $table->foreignId('assigned_caseworker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('beneficiary_application_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('beneficiary_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('status');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiary_application_audits');
        Schema::dropIfExists('beneficiary_applications');
    }
};
