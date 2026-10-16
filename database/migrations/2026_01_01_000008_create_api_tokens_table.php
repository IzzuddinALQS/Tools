<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token API per aplikasi untuk Tahap 2.
 *
 * Token DISIMPAN SEBAGAI HASH, sama seperti password. Nilai aslinya hanya
 * ditampilkan satu kali saat pembuatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();

            $table->string('name');
            $table->string('app_name')->index();

            // Hanya boleh memicu dataset milik target ini.
            $table->foreignId('target_id')->constrained('targets')->cascadeOnDelete();
            $table->foreignId('dataset_id')->constrained('datasets')->cascadeOnDelete();

            // SHA-256 dari token. Kolom aslinya tidak pernah disimpan.
            $table->string('token_hash', 64)->unique();

            // Batas permintaan, mis. 10 per menit.
            $table->unsignedInteger('rate_limit_per_minute')->default(10);

            $table->jsonb('allowed_ips')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['app_name', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
