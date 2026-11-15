<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_number', 16)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('phone_verification_hash', 64)->nullable();
            $table->timestamp('phone_verification_expires_at')->nullable();
            $table->timestamp('phone_verification_sent_at')->nullable();
            $table->unsignedSmallInteger('phone_verification_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone_number', 'phone_verified_at', 'phone_verification_hash',
                'phone_verification_expires_at', 'phone_verification_sent_at', 'phone_verification_attempts']);
        });
    }
};
