<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();

            // mock | oracle
            $table->string('driver', 20);

            // --- Oracle ---
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->default(1521);

            // Tepat satu dari tiga harus terisi (divalidasi di layer model).
            $table->string('service_name')->nullable();
            $table->string('sid')->nullable();
            $table->string('tns_alias')->nullable();
            $table->text('dsn')->nullable();

            $table->string('username')->nullable();

            // Laravel encrypted cast. Tidak pernah dikembalikan ke UI/API,
            // tidak pernah di-log. JANGAN ganti / hilangkan APP_KEY.
            $table->text('password')->nullable();

            // timezone, nls_*, statement timeout, fetch arrays, ...
            $table->jsonb('options')->nullable();

            // Katalog fixture untuk driver `mock`.
            $table->string('fixture_set')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->text('last_test_message')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['driver', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
