<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ppi_household_applications', function (Blueprint $table): void {
            $table->string('guardian_delivery_status', 16)->nullable();
            $table->foreignId('beneficiary_application_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ppi_household_applications', function (Blueprint $table): void {
            $table->dropUnique(['beneficiary_application_id']);
            $table->dropConstrainedForeignId('beneficiary_application_id');
            $table->dropColumn('guardian_delivery_status');
        });
    }
};
