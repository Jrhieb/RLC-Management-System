<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_applications', function (Blueprint $table): void {
            $table->uuid('submission_key')->nullable()->unique();
            $table->string('guardian_relationship')->nullable();
            $table->string('preferred_contact')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiary_applications', function (Blueprint $table): void {
            $table->dropUnique(['submission_key']);
            $table->dropColumn('submission_key');
            $table->dropColumn(['guardian_relationship', 'preferred_contact']);
        });
    }
};
