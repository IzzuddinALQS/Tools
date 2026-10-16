<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat setiap eksekusi: sync (Oracle -> A), copy (A -> B), rekonsiliasi,
 * dan tes koneksi. Satu tabel agar panel Riwayat bisa menampilkan semuanya
 * dalam urutan waktu yang benar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dataset_id')->nullable()->constrained('datasets')->cascadeOnDelete();

            // Koneksi sumber (untuk kind = sync / reconcile).
            $table->foreignId('connection_id')->nullable()->constrained('connections')->nullOnDelete();

            // Target TUJUAN (pg_a untuk sync, pg_b untuk copy).
            $table->foreignId('target_id')->nullable()->constrained('targets')->cascadeOnDelete();

            // Untuk kind = copy: PG A sebagaiasal data.
            $table->foreignId('source_target_id')->nullable()->constrained('targets')->nullOnDelete();

            // sync | copy | reconcile | test
            $table->string('kind', 20);

            // manual | schedule | api | reconcile
            $table->string('trigger', 20);

            // queued | running | success | failed | skipped | paused
            $table->string('status', 20);

            // SQL yang benar-benar dieksekusi (termasuk wrapper watermark
            // yang disusun tool) + hash-nya, agar bisa diaudit ulang.
            $table->text('sql_text')->nullable();
            $table->string('sql_hash', 64)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->unsignedBigInteger('row_count')->nullable();

            /*
             * Hasil verifikasi (Aturan 5):
             * {
             *   "source": {"row_count": 10, "hash": "..."},
             *   "target": {"row_count": 10, "hash": "..."},
             *   "matched": true,
             *   "message": "..."
             * }
             */
            $table->jsonb('verification')->nullable();

            $table->string('error_code', 80)->nullable();

            // Sudah melewati ErrorMessageSanitizer: tidak pernah memuat
            // password, DSN, atau username.
            $table->text('error_message')->nullable();

            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();

            // Diisi pada Tahap 2 (API refresh).
            $table->string('api_token_id')->nullable();

            $table->string('log_path')->nullable();

            $table->timestamps();

            $table->index(['dataset_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
