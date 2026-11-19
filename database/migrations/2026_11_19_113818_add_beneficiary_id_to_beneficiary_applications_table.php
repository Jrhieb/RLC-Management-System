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
        Schema::table('beneficiary_applications', function (Blueprint $table) {
            $table->foreignId('beneficiary_id')->nullable()->unique()->constrained('beneficiaries')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('beneficiary_applications', function (Blueprint $table) {
            $table->dropForeign(['beneficiary_id']);
            $table->dropUnique(['beneficiary_id']);
            $table->dropColumn('beneficiary_id');
        });
    }
};
