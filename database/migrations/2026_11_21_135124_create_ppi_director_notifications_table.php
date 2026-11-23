<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppi_director_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ppi_household_application_id')->constrained('ppi_household_applications')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['ppi_household_application_id', 'recipient_id', 'kind'],
                'ppi_director_notifications_unique',
            );
            $table->index(
                ['recipient_id', 'read_at', 'created_at'],
                'ppi_director_notifications_recipient_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppi_director_notifications');
    }
};
