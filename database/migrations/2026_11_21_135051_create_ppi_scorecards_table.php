<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppi_scorecards', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('version', 50);
            $table->string('country', 2);
            $table->string('source_reference', 500);
            $table->date('effective_from');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
            $table->unique(['name', 'version']);
        });

        Schema::create('ppi_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ppi_scorecard_id')->constrained('ppi_scorecards')->cascadeOnDelete();
            $table->string('code', 10);
            $table->text('prompt');
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['ppi_scorecard_id', 'code']);
            $table->unique(['ppi_scorecard_id', 'position']);
        });

        Schema::create('ppi_answer_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ppi_question_id')->constrained('ppi_questions')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('label', 255);
            $table->smallInteger('points');
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['ppi_question_id', 'code']);
            $table->unique(['ppi_question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppi_answer_options');
        Schema::dropIfExists('ppi_questions');
        Schema::dropIfExists('ppi_scorecards');
    }
};
