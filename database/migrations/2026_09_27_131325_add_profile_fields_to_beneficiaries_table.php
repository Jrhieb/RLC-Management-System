<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('beneficiary_id')->unique();
            $table->string('full_name');
            $table->date('registered_at');
            $table->string('status')->default('active');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn([
                'beneficiary_id',
                'full_name',
                'registered_at',
                'status',
            ]);
        });
    }
};
