<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();

            // pg_a (kanonik) | pg_b (salinan per aplikasi)
            $table->string('kind', 10);

            // Untuk pg_b: aplikasi pemakai, mis. "armors".
            $table->string('app_name')->nullable();

            $table->string('host');
            $table->unsignedInteger('port')->default(5432);
            $table->string('database');
            $table->string('username');

            // Encrypted cast, tidak pernah ditampilkan kembali.
            $table->text('password');

            $table->string('sslmode', 20)->nullable();
            $table->jsonb('options')->nullable();

            // User read-only untuk aplikasi pemakai PG B.
            $table->boolean('auto_provision_readonly')->default(false);
            $table->string('readonly_username')->nullable();
            $table->text('readonly_password')->nullable();
            $table->timestamp('readonly_provisioned_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->text('last_test_message')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'is_active']);
        });

        // Hanya boleh ada SATU PostgreSQL A. Partial unique index tidak
        // didukung schema builder Laravel, jadi dibuat eksplisit.
        DB::statement(
            'CREATE UNIQUE INDEX targets_single_pg_a ON targets (kind) WHERE kind = \'pg_a\''
        );

        // PG B wajib punya app_name supaya baris _sync.status dan GRANT
        // untuk aplikasi pemakai bisa dibedakan dengan jelas.
        DB::statement(
            "ALTER TABLE targets ADD CONSTRAINT targets_pg_b_needs_app
             CHECK (kind <> 'pg_b' OR app_name IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('targets');
    }
};
