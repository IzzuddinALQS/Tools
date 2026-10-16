<?php

namespace App\Services\Source;

use App\Models\Connection as ConnectionModel;
use App\Services\Fixture\FixtureCatalog;
use App\Services\Fixture\FixtureTable;
use App\Services\Fixture\OracleToSqliteDialect;
use App\Services\Source\Exceptions\SourceUnavailableException;
use App\Services\Source\Sql\SelectGuard;
use App\Services\Types\ColumnDefinition;
use Generator;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Oracle tiruan untuk pengembangan & test.
 *
 * Mengimplementasikan interface yang SAMA dengan OracleReader, jadi seluruh
 * alur (Preview, Simpan ke A, Copy ke B, verifikasi) berjalan lewat kode yang
 * identik. Yang berbeda hanya sumber datanya.
 *
 * Cara kerja:
 *  - fixture dimuat menjadi database SQLite in-memory
 *  - query SELECT ditulis user diterjemahkan ke dialek SQLite
 *  - setiap kolom dibaca sebagai TEKS (`CAST(... AS TEXT)`) supaya nilai
 *    sampai ke lapisan kanonikalisasi dalam bentuk yang stabil
 *  - NAMA dan TIPE kolom untuk mode identitas diambil dari metadata fixture,
 *    bukan dari tebakan isi data (mengikuti Aturan 1)
 *
 * Keterbatasan yang perlu diketahui: SQLite menyimpan angka sebagai IEEE-754,
 * jadi fixture hanya menjaga sekitar 15 digit signifikan. Presisi NUMBER yang
 * lebih panjang diuji langsung lewat Canonicalizer, bukan lewat fixture.
 */
class MockOracleReader implements SourceReader
{
    private const ABSOLUTE_MAX_ROWS = 5_000_000;

    private ?PDO $pdo = null;

    private ?FixtureCatalog $catalog = null;

    public function __construct(
        private readonly ConnectionModel $connection,
        private readonly SelectGuard $guard,
        private readonly OracleToSqliteDialect $dialect,
    ) {
    }

    public function name(): string
    {
        return 'mock';
    }

    public function test(): void
    {
        // query paling ringan: SELECT 1 FROM DUAL
        $this->stream('SELECT 1 AS OK FROM DUAL', [], 1, 5);
    }

    public function stream(
        string $sql,
        array $bindings = [],
        ?int $maxRows = null,
        ?int $timeoutSeconds = null,
    ): SourceCursor {
        $this->guard->assert($sql);

        $maxRows = min($maxRows ?? self::ABSOLUTE_MAX_ROWS, self::ABSOLUTE_MAX_ROWS);

        $translated = $this->dialect->translate(
            $sql,
            $this->catalog()->sysdate(),
            $this->fixtureSchemas(),
        );
        $pdo = $this->pdo();

        try {
            $names = $this->columnNames($pdo, $translated);

            if ($names === []) {
                return new SourceCursor([], (function () {
                    return;
                    yield;
                })());
            }

            $selectList = implode(', ', array_map(
                fn (string $name) => 'CAST('.$this->quote($name).' AS TEXT) AS '.$this->quote($name),
                $names,
            ));

            $statement = $pdo->prepare('SELECT '.$selectList.' FROM ('.$translated.')');

            foreach ($bindings as $position => $binding) {
                $statement->bindValue(
                    is_int($position) ? $position + 1 : $position,
                    $this->normalizeBinding($binding),
                    $this->bindingType($binding),
                );
            }

            $statement->execute();

            $columns = array_map(
                fn (string $name) => new ColumnDefinition(
                    name: $name,
                    oracleType: 'UNKNOWN',
                    position: 0,
                ),
                $names,
            );

            $rows = $this->rowGenerator($statement, $maxRows);

            return new SourceCursor($columns, $rows);
        } catch (SourceUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SourceUnavailableException(
                'Query mock gagal: '.$exception->getMessage(),
                $exception,
            );
        }
    }

    public function tableColumns(string $owner, string $table): ?array
    {
        $fixtureTable = $this->catalog()->find($owner, $table);

        if ($fixtureTable === null) {
            return null;
        }

        // Bentukkan ulang dengan owner = schema fixture, supaya penamaan di
        // PG A mengikuti skema tabel aslinya.
        return $fixtureTable->columns();
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->catalog = null;
    }

    public function catalog(): FixtureCatalog
    {
        return $this->catalog ??= FixtureCatalog::load($this->connection->fixtureSet());
    }

    /**
     * @return array<int,string>
     */
    private function fixtureSchemas(): array
    {
        return array_values(array_unique(array_map(
            fn ($table) => $table->schema(),
            $this->catalog()->tables(),
        )));
    }

    /**
     * @return array<int,string>
     */
    private function columnNames(PDO $pdo, string $translatedSql): array
    {
        // LIMIT 0 membuat SQLite tidak menjalankan badan query, hanya
        // mengkompilasi plans untuk mengetahui nama kolom.
        $statement = $pdo->query('SELECT * FROM ('.$translatedSql.') LIMIT 0');

        $names = [];

        // PDO SQLite meminta indeks kolom; berhenti saat error / habis.
        for ($index = 0; $index < 512; $index++) {
            try {
                $meta = $statement->getColumnMeta($index);
            } catch (\Throwable) {
                break;
            }

            if (! is_array($meta) || ! isset($meta['name'])) {
                break;
            }

            $names[] = (string) $meta['name'];
        }

        $statement->closeCursor();

        return $names;
    }

    /**
     * @return Generator<int,array<string,mixed>>
     */
    private function rowGenerator(PDOStatement $statement, int $maxRows): Generator
    {
        $read = 0;

        while ($read < $maxRows && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
            $read++;
        }

        $statement->closeCursor();
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            $this->pdo = new PDO('sqlite::memory:', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]);

            $this->buildSchema($this->pdo, $this->catalog());
        } catch (Throwable $exception) {
            throw new SourceUnavailableException(
                'Fixture mock tidak dapat dimuat: '.$exception->getMessage(),
                $exception,
            );
        }

        return $this->pdo;
    }

    private function buildSchema(PDO $pdo, FixtureCatalog $catalog): void
    {
        // SQLite tidak punya CREATE SCHEMA: semua tabel fixture hidup di satu
        // namespace datar. Qualifier schema dilepas oleh OracleToSqliteDialect.
        foreach ($catalog->tables() as $table) {
            $definitions = [];

            foreach ($table->columns() as $column) {
                $definitions[] = $this->quote($column->name).' '.$this->sqliteAffinity($column->oracleType);
            }

            $pdo->exec(sprintf(
                'CREATE TABLE "%s" (%s)',
                $this->escape($table->table()),
                implode(', ', $definitions),
            ));

            $this->insertRows($pdo, $table);
        }

        // DUAL supaya query gaya Oracle "SELECT 1 FROM DUAL" jalan.
        $pdo->exec('CREATE TABLE IF NOT EXISTS "DUAL" ("DUMMY" TEXT)');
        $pdo->exec("INSERT INTO \"DUAL\" VALUES ('X')");
    }

    private function insertRows(PDO $pdo, FixtureTable $table): void
    {
        $rows = $table->rows();

        if ($rows === []) {
            return;
        }

        $names = $table->columnNames();

        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $columns = implode(', ', array_map(fn (string $name) => $this->quote($name), $names));

        $statement = $pdo->prepare(sprintf(
            'INSERT INTO "%s" (%s) VALUES (%s)',
            $this->escape($table->table()),
            $columns,
            $placeholders,
        ));

        foreach ($rows as $row) {
            $values = [];

            foreach ($names as $name) {
                $value = $row[$name] ?? null;

                if ($value !== null && in_array($name, $table->binaryColumns(), true)) {
                    $decoded = base64_decode($value, true);

                    if ($decoded !== false) {
                        $value = $decoded;
                    }
                }

                $values[] = $value;
            }

            $statement->execute($values);
        }
    }

    /**
     * Affinity SQLite.
     *
     * Kolom numerik memakai NUMERIC supaya perbandingan, ORDER BY, dan
     * agregasi berperilaku seperti angka. Konsekuensinya hanya ~15 digit
     * signifikan yang terjaga; lihat catatan di docblock kelas.
     */
    private function sqliteAffinity(string $oracleType): string
    {
        return match (strtoupper($oracleType)) {
            'NUMBER', 'BINARY_FLOAT', 'BINARY_DOUBLE' => 'NUMERIC',
            'BLOB', 'RAW', 'LONG RAW' => 'BLOB',
            default => 'TEXT',
        };
    }

    private function normalizeBinding(mixed $binding): mixed
    {
        if ($binding === null) {
            return null;
        }

        if (is_bool($binding)) {
            return $binding ? 1 : 0;
        }

        return $binding;
    }

    private function bindingType(mixed $binding): int
    {
        return match (true) {
            $binding === null => PDO::PARAM_NULL,
            is_int($binding) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function escape(string $identifier): string
    {
        return str_replace('"', '""', $identifier);
    }
}
