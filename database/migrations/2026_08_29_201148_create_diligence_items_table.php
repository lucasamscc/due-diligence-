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
        Schema::create('diligence_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diligence_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('product');
            $table->jsonb('params');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('cost_cents')->default(0);
            $table->string('raw_payload_key')->nullable();
            $table->string('raw_payload_hash')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['diligence_id', 'provider', 'product']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diligence_items');
    }
};
