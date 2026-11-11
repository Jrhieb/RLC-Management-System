<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('beneficiary_id')
                ->constrained('beneficiaries')
                ->restrictOnDelete();

            $table->string('category', 30);
            $table->string('item_name');
            $table->unsignedInteger('quantity')->default(1);
            $table->dateTime('released_at');
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_transactions');
    }
};
