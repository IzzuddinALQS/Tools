<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setiap perubahan query disimpan sebagai versi: siapa, kapan, dan isi
 * sebelum / sesudah. "Sebelum" = versi (version_no - 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->unsignedInteger('version_no');

            $table->text('sql');
            $table->string('destination_schema');
            $table->string('destination_table');
            $table->string('sync_mode', 20);
            $table->string('watermark_column')->nullable();
            $table->string('watermark_type', 30)->nullable();
            $table->jsonb('key_columns')->nullable();
            $table->jsonb('column_overrides')->nullable();
            $table->string('schedule_cron')->nullable();
            $table->string('reconcile_cron')->nullable();
            $table->unsignedInteger('timeout_seconds');
            $table->unsignedBigInteger('max_rows');
            $table->unsignedInteger('batch_size');

            // create | update
            $table->string('change_type', 20)->default('update');
            $table->text('note')->nullable();

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dataset_id', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_versions');
    }
};
