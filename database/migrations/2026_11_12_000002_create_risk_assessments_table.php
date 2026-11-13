<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha por avaliação, não por diligência: reavaliar com regras novas
        // gera um registro novo e preserva o histórico do que foi concluído antes.
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diligence_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('score');
            $table->string('level');
            $table->boolean('is_complete');
            $table->jsonb('alerts');
            $table->string('rules_version');
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['diligence_id', 'evaluated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
