<?php

namespace App\Services\Source;

use App\Models\Connection as ConnectionModel;
use App\Services\Source\Exceptions\SourceUnavailableException;
use App\Services\Source\Sql\SelectGuard;
use App\Services\Types\ColumnDefinition;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Akses Oracle sungguhan memakai ext-oci8 (yajra/laravel-oci8).
 *
 * Hanya dijalankan di server milik IT. Di mesin pengembangan kelas ini tidak
 * pernah dipakai: gunakan driver `mock`.
 *
 * Catatan keamanan:
 *  - statement dari user WAJIB sudah lolos SelectGuard sebelum sampai ke sini
 *  - tidak ada metode publik yang bisa mengirim DML/DDL
 *  - password tidak pernah masuk pesan error maupun log
 */
class OracleReader implements SourceReader
{
    /** Batas keras jumlah baris per statement (Keamanan 4). */
    private const ABSOLUTE_MAX_ROWS = 5_000_000;

    private const ABSOLUTE_TIMEOUT_SECONDS = 3600;

    private ?object $pdo = null;

    private bool $sessionApplied = false;

    public function __construct(
        private readonly ConnectionModel $connection,
        private readonly SelectGuard $guard,
    ) {
    }

    public function name(): string
    {
        return 'oracle';
    }

    public function test(): void
    {
        // SELECT 1 FROM DUAL: satu statement SELECT, tanpa data sensitif.
        $statement = $this->pdo()->prepare('SELECT 1 FROM DUAL');
        $statement->execute();
        $statement->fetch(PDO::FETCH_NUM);
        $statement->closeCursor();
    }

    public function stream(
        string $sql,
        array $bindings = [],
        ?int $maxRows = null,
        ?int $timeoutSeconds = null,
    ): SourceCursor {
        $this->guard->assert($sql);

        $maxRows = min($maxRows ?? self::ABSOLUTE_MAX_ROWS, self::ABSOLUTE_MAX_ROWS);
        $timeout = min($timeoutSeconds ?? self::ABSOLUTE_TIMEOUT_SECONDS, self::ABSOLUTE_TIMEOUT_SECONDS);

        try {
            $statement = $this->pdo()->prepare($sql);

            if ($timeout > 0 && defined('OCI_ATTR_STATEMENT_TIMEOUT')) {
                // ext-oci8 menyediakan statement timeout per statement (ms).
                $statement->attributeSet(\OCI_ATTR_STATEMENT_TIMEOUT, $timeout * 1000);
            }

            $statement->execute($bindings);

            [$columns, $pendingRow] = $this->describeStatement($statement);

            $rows = $this->rowGenerator($statement, $pendingRow, $maxRows);

            return new SourceCursor($columns, $rows);
        } catch (SourceUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Keamanan 6: tool tidak crash, pesan rapi, tanpa kredensial.
            throw new SourceUnavailableException(
                'Oracle tidak dapat dihubungi: '.$this->sanitize($exception->getMessage()),
                $exception,
            );
        }
    }

    public function tableColumns(string $owner, string $table): ?array
    {
        /*
         * Metadata statement.
         *
         * Ini BUKA query tambahan untuk hash. Nama & tipe kolom HARUS berasal
         * dari katalog Oracle (Aturan 1). Hash tetap dihitung dari baris yang
         * sedang dibaca, tanpa query tambahan (Aturan 5).
         */
        $sql = 'SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, CHAR_LENGTH,
                       CHAR_USED, DATA_PRECISION, DATA_SCALE, COLUMN_ID
                  FROM ALL_TAB_COLUMNS
                 WHERE OWNER = :owner AND TABLE_NAME = :table
                 ORDER BY COLUMN_ID';

        try {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute([':owner' => strtoupper($owner), ':table' => strtoupper($table)]);

            $columns = [];

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns[] = ColumnDefinition::fromMetadataRow($row);
            }

            $statement->closeCursor();

            return $columns === [] ? null : $columns;
        } catch (Throwable $exception) {
            throw new SourceUnavailableException(
                'Gagal membaca metadata Oracle untuk '.$owner.'.'.$table.': '.$this->sanitize($exception->getMessage()),
                $exception,
                ['owner' => $owner, 'table' => $table],
            );
        }
    }

    public function close(): void
    {
        // ext-oci8 menutup koneksi saat objek PDO dihancurkan; tidak ada
        // handle yang harus ditutup manual.
        $this->pdo = null;
        $this->sessionApplied = false;
    }

    /**
     * Koneksi PDO yang sudah disiapkan dengan setelan sesi deterministik.
     */
    private function pdo(): object
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $name = 'source_'.$this->connection->uid;

        try {
            config(['database.connections.'.$name => $this->connectionConfig()]);
            DB::purge($name);

            /** @var \Illuminate\Database\Connection $laravelConnection */
            $laravelConnection = DB::connection($name);
            $this->pdo = $laravelConnection->getPdo();
        } catch (Throwable $exception) {
            throw new SourceUnavailableException(
                'Tidak dapat terhubung ke Oracle ('.$this->connection->safeLabel().'): '
                .$this->sanitize($exception->getMessage()),
                $exception,
            );
        }

        $this->applySessionDefaults();

        return $this->pdo;
    }

    private function connectionConfig(): array
    {
        $options = (array) ($this->connection->options ?? []);

        $config = [
            'driver' => 'oci8',
            'host' => $this->connection->host,
            'port' => $this->connection->port,
            'username' => $this->connection->username,
            'password' => $this->connection->password,
            'charset' => $options['charset'] ?? 'AL32UTF8',
            'session_mode' => $options['session_mode'] ?? 'NORMAL',
        ];

        if ($this->connection->dsn) {
            $config['tns'] = $this->connection->dsn;
        } elseif ($this->connection->tns_alias) {
            $config['tns'] = $this->connection->tns_alias;
        } else {
            $config['database'] = $this->connection->sid;
            $config['service_name'] = $this->connection->service_name;
        }

        return $config;
    }

    /**
     * Setelan sesi agar hasil baca konsisten (Aturan 4).
     *
     * PENTING: statement di sini adalah LITERAL MILIK TOOL yang dibaca dari
     * config, tidak pernah berisi input user, dan TIDAK melewati SelectGuard.
     * ALTER SESSION hanya mengubah state sesi; tidak menulis data apa pun.
     * Bila DBA melarangnya, kosongkan nilainya di config `oracle-sync.session`.
     */
    private function applySessionDefaults(): void
    {
        if ($this->sessionApplied) {
            return;
        }

        $session = (array) config('oracle-sync.session', []);

        $statements = array_values(array_filter([
            $this->sessionStatement('TIME_ZONE', $session['time_zone'] ?? null),
            $this->sessionStatement('NLS_DATE_FORMAT', $session['nls_date_format'] ?? null),
            $this->sessionStatement('NLS_TIMESTAMP_FORMAT', $session['nls_timestamp_format'] ?? null),
            $this->sessionStatement('NLS_TIMESTAMP_TZ_FORMAT', $session['nls_timestamp_tz_format'] ?? null),
            $this->sessionStatement('NLS_NUMERIC_CHARACTERS', $session['nls_numeric_characters'] ?? null),
        ]));

        foreach ($statements as $sql) {
            try {
                $statement = $this->pdo->prepare($sql);
                $statement->execute();
                $statement->closeCursor();
            } catch (Throwable $exception) {
                // Tidak fatal: pembacaan tetap jalan, tapi format NLS bisa
                // berbeda. Dicatat tanpa kredensial supaya bisa ditelusuri.
                Log::warning('Oracle session setting gagal diterapkan.', [
                    'connection' => $this->connection->safeLabel(),
                    'setting' => substr($sql, 0, 48),
                    'message' => $this->sanitize($exception->getMessage()),
                ]);

                break;
            }
        }

        $this->sessionApplied = true;
    }

    private function sessionStatement(string $name, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        return sprintf(
            "ALTER SESSION SET %s = '%s'",
            $name,
            str_replace("'", "''", (string) $value)
        );
    }

    /**
     * @return array{0:array<int,ColumnDefinition>,1:?array<string,mixed>}
     */
    private function describeStatement(object $statement): array
    {
        $meta = [];

        if (method_exists($statement, 'getColumnMeta')) {
            try {
                $meta = (array) $statement->getColumnMeta();
            } catch (Throwable) {
                $meta = [];
            }
        }

        if ($meta !== []) {
            $columns = [];

            foreach ($meta as $index => $columnMeta) {
                $columns[] = new ColumnDefinition(
                    name: (string) ($columnMeta['name'] ?? ('C'.($index + 1))),
                    oracleType: strtoupper((string) ($columnMeta['native_type'] ?? 'UNKNOWN')),
                    dataPrecision: isset($columnMeta['precision']) ? (int) $columnMeta['precision'] : null,
                    position: $index + 1,
                );
            }

            return [$columns, null];
        }

        // Fallback: nama kolom diambil dari baris pertama. Baris itu dikembalikan
        // ke generator supaya tidak hilang dari hasil.
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [[], null];
        }

        $columns = [];

        foreach (array_keys($row) as $index => $name) {
            $columns[] = new ColumnDefinition(
                name: (string) $name,
                oracleType: 'UNKNOWN',
                position: $index + 1,
            );
        }

        return [$columns, $row];
    }

    /**
     * @param  array<string,mixed>|null  $pendingRow
     * @return Generator<int,array<string,mixed>>
     */
    private function rowGenerator(object $statement, ?array $pendingRow, int $maxRows): Generator
    {
        $read = 0;

        if ($pendingRow !== null) {
            yield $pendingRow;
            $read++;
        }

        while ($read < $maxRows && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
            $read++;
        }

        try {
            $statement->closeCursor();
        } catch (Throwable) {
            // Statement sudah selesai atau koneksi diputus; tidak masalah.
        }
    }

    /**
     * Buang apa pun yang menyerupai kredensial dari pesan error.
     */
    private function sanitize(string $message): string
    {
        $message = (string) preg_replace('/\b(?:password|passwd|pwd)\s*=\s*\S+/i', 'password=***', $message);
        $message = (string) preg_replace('/DESCRIPTION\s*=\s*\([^)]*\)/i', 'DESCRIPTION=(***)', $message);

        $secret = $this->connection->password;

        if (is_string($secret) && $secret !== '') {
            $message = str_replace($secret, '***', $message);
        }

        return mb_substr(trim($message), 0, 500);
    }
}
