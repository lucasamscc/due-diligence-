<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('dataset_versions', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            // Quando o órgão publicou a lista; diferente de quando nós a copiamos.
            $table->timestamp('published_at')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->boolean('is_active')->default(false);
            $table->string('status')->default('ingesting'); // ingesting | published | rejected
            $table->string('source_file')->nullable();
            $table->string('file_hash')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['source', 'is_active']);
        });

        // Invariante no banco: no máximo UMA versão ativa por fonte.
        // O swap atômico depende disso — sem o índice, um bug no código
        // poderia deixar duas versões ativas e o lookup devolveria duplicatas.
        DB::statement('CREATE UNIQUE INDEX dataset_versions_one_active_per_source ON dataset_versions (source) WHERE is_active');

        Schema::create('dataset_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_version_id')->constrained()->cascadeOnDelete();
            $table->string('source_key');
            $table->string('document', 14)->nullable();
            $table->string('name_normalized')->nullable();
            $table->jsonb('payload');

            $table->unique(['dataset_version_id', 'source_key']);
            $table->index(['dataset_version_id', 'document']);
        });

        DB::statement('CREATE INDEX dataset_records_name_trgm ON dataset_records USING gin (name_normalized gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_records');
        Schema::dropIfExists('dataset_versions');
    }
};
