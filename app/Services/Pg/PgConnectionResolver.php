<?php

namespace App\Services\Pg;

use App\Models\Target;
use App\Services\Source\Exceptions\TargetUnavailableException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Membuat koneksi PostgreSQL runtime untuk PG A dan PG B.
 *
 * Target dibuat dari UI, bukan dari file, jadi koneksinya tidak bisa
 * ditulis di config/database.php. Koneksi didaftarkan ke DatabaseManager
 * secara dinamis dan di-cache per proses worker.
 */
class PgConnectionResolver
{
    /** @var array<string,string> */
    private array $fingerprints = [];

    /** @var array<string,bool> */
    private array $prepared = [];

    public function resolve(Target $target): Connection
    {
        $name = $target->connectionName();
        $config = $target->connectionConfig();
        $fingerprint = md5((string) json_encode($config));

        if (($this->fingerprints[$name] ?? null) !== $fingerprint) {
            config(['database.connections.'.$name => $config]);
            DB::purge($name);
            unset($this->prepared[$name]);
            $this->fingerprints[$name] = $fingerprint;
        }

        try {
            /** @var Connection $connection */
            $connection = DB::connection($name);
        } catch (Throwable $exception) {
            throw new TargetUnavailableException(
                'Tidak dapat terhubung ke PostgreSQL ('.$target->safeLabel().'): '.$this->sanitize($exception->getMessage()),
                $exception,
                ['target' => $target->name],
            );
        }

        if (! ($this->prepared[$name] ?? false)) {
            $this->applySessionSettings($connection, $target);
            $this->prepared[$name] = true;
        }

        return $connection;
    }

    /**
     * Lupakan koneksi yang di-cache, mis. setelah password target diubah.
     */
    public function forget(Target $target): void
    {
        DB::purge($target->connectionName());

        unset($this->fingerprints[$target->connectionName()], $this->prepared[$target->connectionName()]);
    }

    /**
     * Setelan sesi agar nilai yang dibaca sebanding dengan sisi Oracle saat
     * verifikasi hash (Aturan 4 & 5).
     */
    private function applySessionSettings(Connection $connection, Target $target): void
    {
        $session = (array) config('oracle-sync.pg_session', []);

        $statements = [
            sprintf("SET TIME ZONE '%s'", $this->literal((string) ($session['timezone'] ?? 'UTC'))),
            'SET extra_float_digits = '.(int) ($session['extra_float_digits'] ?? 3),
        ];

        foreach ($statements as $sql) {
            try {
                $connection->statement($sql);
            } catch (Throwable $exception) {
                throw new TargetUnavailableException(
                    'PostgreSQL ('.$target->safeLabel().') menolak setelan sesi: '.$this->sanitize($exception->getMessage()),
                    $exception,
                    ['target' => $target->name],
                );
            }
        }
    }

    private function literal(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Jangan pernah bocorkan password target lewat pesan error.
     */
    private function sanitize(string $message): string
    {
        $message = (string) preg_replace(
            '/\b(?:password|passwd|pwd)\s*[=:]\s*\S+/i',
            'password=***',
            $message
        );

        return mb_substr(trim($message), 0, 400);
    }
}
