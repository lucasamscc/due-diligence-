<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->index('diligence_item_id');
            $table->index(['type', 'severity']);
        });

        Schema::table('diligences', function (Blueprint $table) {
            $table->index('entity_document');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropIndex(['diligence_item_id']);
            $table->dropIndex(['type', 'severity']);
        });

        Schema::table('diligences', function (Blueprint $table) {
            $table->dropIndex(['entity_document']);
            $table->dropIndex(['status']);
        });
    }
};
