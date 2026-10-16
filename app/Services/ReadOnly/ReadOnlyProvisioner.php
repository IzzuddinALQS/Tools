<?php

namespace App\Services\ReadOnly;

use App\Models\Target;
use App\Models\PgIdentifier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * User read-only untuk aplikasi pemakai PostgreSQL B (Keamanan 8).
 *
 * Tool bisa membuat user ini sendiri, ATAU DBA/admin menjalankannya manual.
 * Perintah SQL-nya juga disediakan di README sebagai jaring pengaman.
 *
 * Prinsip: user aplikasi hanya boleh SELECT. Tidak INSERT, tidak UPDATE,
 * tidak DELETE, tidak membuat objek.
 */
class ReadOnlyProvisioner
{
    /**
     * Buat (atau pastikan ada) user read-only di target PG B.
     *
     * @return array<int,string> peringatan untuk ditampilkan di UI
     */
    public function ensure(Target $target, string $password): array
    {
        if ($target->kind->value !== 'pg_b') {
            throw new \App\Services\Source\Exceptions\SyncException(
                'User read-only hanya dibuat untuk PostgreSQL B.',
                'invalid_target_kind',
            );
        }

        if ($target->readonly_username === null || $target->readonly_username === '') {
            throw new \App\Services\Source\Exceptions\SyncException(
                'Nama user read-only belum diisi pada target ini.',
                'readonly_username_missing',
            );
        }

        $warnings = [];
        $username = $target->readonly_username;
        $quotedUser = PgIdentifier::quote($username);

        $pdo = DB::connection($target->connectionName())->getPdo();

        try {
            $exists = $this->roleExists($pdo, $username);

            if ($exists) {
                $statement = $pdo->prepare('ALTER ROLE '.$quotedUser.' WITH LOGIN PASSWORD ?');
                $statement->execute([$password]);
            } else {
                $statement = $pdo->prepare('CREATE ROLE '.$quotedUser.' WITH LOGIN PASSWORD ? NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT');
                $statement->execute([$password]);
                $warnings[] = 'User read-only "'.$username.'" dibuat.';
            }

            // Batasi hak: hanya CONNECT.
            $statement = $pdo->prepare('GRANT CONNECT ON DATABASE '.$this->quoteCurrentDatabase($pdo).' TO '.$quotedUser);
            $statement->execute();
        } catch (Throwable $exception) {
            // Membuat role butuh hak admin. Kalau ditolak, jangan gagalkan
            // seluruh sync: cukupIngatkan DBA.
            $warnings[] = 'Tidak dapat membuat user read-only secara otomatis: '.$exception->getMessage();
            $warnings[] = 'Jalankan perintah SQL read-only dari README secara manual.';

            Log::warning('Gagal membuat user read-only.', [
                'target' => $target->name,
                'message' => $exception->getMessage(),
            ]);
        }

        $target->forceFill([
            'readonly_provisioned_at' => now(),
        ])->save();

        return $warnings;
    }

    /**
     * Beri hak SELECT pada schema & tabel tertentu.
     */
    public function grantSelect(Connection $connection, Target $target, string $schema): void
    {
        if (empty($target->readonly_username)) {
            return;
        }

        $quotedUser = PgIdentifier::quote($target->readonly_username);

        $connection->statement(sprintf(
            'GRANT USAGE ON SCHEMA %s TO %s',
            PgIdentifier::quote($schema),
            $quotedUser,
        ));

        $connection->statement(sprintf(
            'GRANT SELECT ON ALL TABLES IN SCHEMA %s TO %s',
            PgIdentifier::quote($schema),
            $quotedUser,
        ));
    }

    /**
     * @return array<int,string>
     */
    public function warningsFor(Target $target): array
    {
        if (empty($target->readonly_username)) {
            return [
                'Belum ada user read-only untuk target ini. '
                .'Aplikasi hanya boleh membaca lewat user read-only, bukan user owner.',
            ];
        }

        if ($target->readonly_provisioned_at === null) {
            return ['User read-only sudah diisi tetapi belum pernah dibuat otomatis. '
                .'Jalankan perintah SQL read-only dari README.'];
        }

        return [];
    }

    private function roleExists(object $pdo, string $username): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM pg_roles WHERE rolname = ?");
        $statement->execute([$username]);

        return $statement->fetchColumn() !== false;
    }

    private function quoteCurrentDatabase(object $pdo): string
    {
        $statement = $pdo->query('SELECT current_database()');
        $name = $statement === false ? '' : (string) $statement->fetchColumn();

        return PgIdentifier::quote($name);
    }
}
