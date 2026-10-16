<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();

            $table->foreignId('connection_id')->constrained('connections')->cascadeOnDelete();

            // Selalu PG A. Sync Oracle -> A, lalu copy A -> B.
            $table->foreignId('target_id')->constrained('targets')->cascadeOnDelete();

            // SQL versi terkini (denormalisasi dari dataset_versions agar
            // eksekusi tidak perlu join).
            $table->text('sql');

            /*
             * identity_mode = true  -> nama schema/tabel/kolom WAJIB identik
             *                           dengan Oracle, quoted, huruf besar.
             * identity_mode = false -> user wajib memberi destination sendiri
             *                           (wajib untuk JOIN/agregasi/alias).
             */
            $table->boolean('identity_mode')->default(true);

            // Selalu diisi, termasuk pada identity_mode: nilainya di-resolve
            // dari metadata Oracle saat dataset dibuat. Inilah yang membuat
            // unique index di bawah benar-benar menegakkan "satu tabel tujuan
            // hanya boleh dimiliki satu dataset".
            $table->string('destination_schema');
            $table->string('destination_table');

            // Konfirmasi tipe per kolom untuk query non-identitas.
            // Bentuk: { "COLUMN": "numeric(20,4)" }
            $table->jsonb('column_overrides')->nullable();

            // full | incremental
            $table->string('sync_mode', 20)->default('full');

            // Wajib bila sync_mode = incremental.
            $table->string('watermark_column')->nullable();
            $table->string('watermark_type', 30)->nullable();
            $table->jsonb('key_columns')->nullable();

            $table->string('schedule_cron')->nullable();
            $table->string('reconcile_cron')->nullable();

            // Batas keamanan (Keamanan 4).
            $table->unsignedInteger('timeout_seconds')->default(300);
            $table->unsignedBigInteger('max_rows')->default(1000000);
            $table->unsignedInteger('batch_size')->default(2000);

            // Target pg_b yang menerima copy otomatis tiap sync sukses.
            $table->jsonb('auto_copy_to')->nullable();

            // active | paused
            $table->string('status', 20)->default('active');

            $table->unsignedInteger('current_version_no')->default(1);
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedBigInteger('last_row_count')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // 🔒 Satu tabel tujuan hanya boleh dimiliki satu dataset.
            $table->unique(
                ['target_id', 'destination_schema', 'destination_table'],
                'datasets_destination_unique'
            );

            $table->index(['status', 'sync_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
