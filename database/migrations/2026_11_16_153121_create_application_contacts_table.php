<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('beneficiary_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contact_at');
            $table->string('method', 20);
            $table->string('outcome', 30);
            $table->string('summary', 1000);
            $table->date('follow_up_on')->nullable();
            $table->text('private_notes')->nullable();
            $table->timestamps();
            $table->index(['beneficiary_application_id', 'contact_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_contacts');
    }
};
