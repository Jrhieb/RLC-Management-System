<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_applications', function (Blueprint $table): void {
            $table->text('assessment_findings')->nullable();
            $table->text('assessment_recommendation')->nullable();
            $table->text('information_needed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiary_applications', function (Blueprint $table): void {
            $table->dropColumn(['assessment_findings', 'assessment_recommendation', 'information_needed']);
        });
    }
};
