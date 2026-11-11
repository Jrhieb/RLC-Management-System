<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('event_key', 80);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['recipient_id', 'event_key']);
            $table->index(['recipient_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_notifications');
    }
};
