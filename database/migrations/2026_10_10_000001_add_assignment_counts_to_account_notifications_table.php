<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_notifications', function (Blueprint $table) {
            $table->unsignedSmallInteger('added_count')->default(0);
            $table->unsignedSmallInteger('removed_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('account_notifications', function (Blueprint $table) {
            $table->dropColumn(['added_count', 'removed_count']);
        });
    }
};
