<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('beneficiary_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_key', 100);
            $table->string('event_type', 40);
            $table->string('subject');
            $table->text('applicant_message');
            $table->string('status', 20)->default('pending');
            $table->string('provider_message_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivery_confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['beneficiary_application_id', 'event_key']);
        });
        Schema::create('application_appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('beneficiary_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_key')->unique();
            $table->timestamp('scheduled_at');
            $table->string('location_or_method');
            $table->text('instructions');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_appointments');
        Schema::dropIfExists('application_emails');
    }
};
