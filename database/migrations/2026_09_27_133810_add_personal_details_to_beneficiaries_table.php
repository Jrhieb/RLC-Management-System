<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
            $table->date('birthdate')->nullable();
            $table->text('address')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_contact', 30)->nullable();
            $table->string('school')->nullable();
            $table->string('grade_level', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn([
                'photo_path',
                'birthdate',
                'address',
                'guardian_name',
                'guardian_contact',
                'school',
                'grade_level',
            ]);
        });
    }
};
