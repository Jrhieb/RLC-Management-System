<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approval_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });

        // Preserve legacy access without inventing a review or touching user timestamps.
        DB::table('users')->where('role', 'caseworker')->update(['approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_reviewed_by');
            $table->dropColumn(['approval_status', 'approval_reviewed_at', 'rejection_reason']);
        });
    }
};
