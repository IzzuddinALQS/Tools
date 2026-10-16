<?php

namespace App\Services\Fixture;

use App\Services\Source\Sql\SqlScanner;

/**
 * Menerjemahkan subset SQL Oracle ke SQLite, supaya driver `mock` bisa
 * menjalankan query SELECT yang realistis tanpa Oracle.
 *
 * PENTING: ini hanya untuk pengembangan & test. Mock BUKAN pengganti Oracle
 * dan tidak dimaksudkan menjadi emulator Oracle.
 *
 * Yang didukung:
 *   SYSDATE / SYSTIMESTAMP / CURRENT_DATE / CURRENT_TIMESTAMP
 *   ROWNUM <= n                     -> LIMIT n
 *   FETCH FIRST|NEXT n ROWS ONLY   -> LIMIT n
 *   NVL(a, b)                      -> IFNULL(a, b)
 *   TO_CHAR(<date>, 'YYYY-MM-DD')  -> STRFTIME('%Y-%m-%d', <date>)
 *   TRUNC(<date>)                  -> DATE(<date>)
 *   FROM DUAL                      -> tabel DUAL yang dibuat reader
 *
 * Yang TIDAK didukung (dokumentasikan di README):
 *   CONNECT BY, MODEL, PIVOT/UNPIVOT, MATCH_RECOGNIZE, DECODE,
 *   ADD_MONTHS, MONTHS_BETWEEN, paket DBMS_*, dan hint optimizer Oracle.
 *
 * SYSDATE dipetakan ke waktu TETAP dari fixture, bukan jam sistem, supaya
 * hasil test deterministik dan bisa diulang.
 */
class OracleToSqliteDialect
{
    private const PLACEHOLDER_PREFIX = "\x01";

    private const PLACEHOLDER_SUFFIX = "\x02";

    /*
     * Pola format tanggal Oracle -> pola strftime SQLite.
     * Urutan penting: token terpanjang diperiksa lebih dulu supaya HH24 tidak
     * lebih dulu terambil sebagai HH.
     */
    private const DATE_FORMAT_MAP = [
        'YYYY' => '%Y',
        'YY' => '%y',
        'MM' => '%m',
        'MON' => '%b',
        'DDD' => '%j',
        'DD' => '%d',
        'HH24' => '%H',
        'HH12' => '%I',
        'HH' => '%H',
        'MI' => '%M',
        'SS' => '%S',
    ];

    /**
     * @param  string  $sysdate  waktu tetap untuk SYSDATE, mis. "2026-03-15 09:00:00"
     * @param  array<int,string>  $schemas  nama schema fixture yang boleh
     *                                    dilepas qualifiers-nya. SQLite tidak
     *                                    punya konsep schema, jadi mock
     *                                    memakai satu namespace datar.
     */
    public function translate(string $sql, string $sysdate, array $schemas = []): string
    {
        [$masked, $literals] = $this->maskLiterals($sql);

        $result = rtrim(trim($masked), ';');

        $result = $this->stripSchemaQualifiers($result, $schemas);

        $plainSysdate = str_replace("'", "''", $sysdate);

        $result = (string) preg_replace('/\bSYSTIMESTAMP\b/i', "'{$plainSysdate}.000000'", $result);
        $result = (string) preg_replace('/\bSYSDATE\b/i', "'{$plainSysdate}'", $result);
        $result = (string) preg_replace('/\bCURRENT_DATE\b/i', "'{$plainSysdate}'", $result);
        $result = (string) preg_replace('/\bCURRENT_TIMESTAMP\b/i', "'{$plainSysdate}.000000'", $result);

        // --- fungsi ---
        $result = (string) preg_replace('/\bNVL\s*\(/i', 'IFNULL(', $result);
        $result = (string) preg_replace('/\bTRUNC\s*\(/i', 'DATE(', $result);
        $result = $this->translateToChar($result);

        // --- pagination ---
        $result = $this->translateFetchFirst($result);

        return $this->restoreLiterals($result, $literals);
    }

    /**
     * ROWNUM <= n  ->  LIMIT n
     * Syaratnya belum ada LIMIT dari FETCH FIRST.
     */
    private function translateFetchFirst(string $sql): string
    {
        $limit = null;

        // FETCH FIRST n ROWS ONLY / FETCH NEXT n ROWS ONLY
        $sql = (string) preg_replace_callback(
            '/\bFETCH\s+(?:FIRST|NEXT)\s+(\d+)\s+ROWS\s+ONLY\b/i',
            function (array $matches) use (&$limit) {
                $limit = (int) $matches[1];

                return ' ';
            },
            $sql
        );

        // ROWNUM <= n : hapus kondisinya dari WHERE
        $sql = (string) preg_replace_callback(
            '/\bAND\s+ROWNUM\s*<=\s*(\d+)/i',
            function (array $matches) use (&$limit) {
                $limit ??= (int) $matches[1];

                return ' ';
            },
            $sql
        );

        $sql = (string) preg_replace_callback(
            '/\bROWNUM\s*<=\s*(\d+)/i',
            function (array $matches) use (&$limit) {
                $limit ??= (int) $matches[1];

                return '1 = 1';
            },
            $sql
        );

        // ROWNUM tanpa perbandingan -> nomor baris
        $sql = (string) preg_replace('/\bROWNUM\b/i', 'ROW_NUMBER() OVER ()', $sql);

        if ($limit !== null && stripos($sql, 'LIMIT') === false) {
            $sql .= ' LIMIT '.$limit;
        }

        return $sql;
    }

    /**
     * TO_CHAR(<expr>, 'YYYY-MM-DD HH24:MI:SS') -> STRFTIME('<pola>', <expr>)
     */
    private function translateToChar(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\bTO_CHAR\s*\(([^,()]+),\s*\'([^\']+)\'\s*\)/i',
            function (array $matches) {
                $argument = trim($matches[1]);
                $format = $matches[2];

                $sqliteFormat = $this->convertDateFormat($format);

                if ($sqliteFormat === null) {
                    // Format tidak dikenali: biarkan apa adanya supaya error
                    // terlihat jelas, bukan diam-diam menghasilkan data salah.
                    return $matches[0];
                }

                return "STRFTIME('".str_replace("'", "''", $sqliteFormat)."', ".$argument.')';
            },
            $sql
        );
    }

    private function convertDateFormat(string $oracleFormat): ?string
    {
        // Sisakan literal di dalam kurung kurawal, mis. FMYYYY.
        $cleaned = (string) preg_replace('/\bFM\b/', '', $oracleFormat);

        if ($cleaned === '') {
            return null;
        }

        $result = '';
        $length = strlen($cleaned);
        $supported = true;

        for ($i = 0; $i < $length; $i++) {
            if ($cleaned[$i] === '"') {
                $end = strpos($cleaned, '"', $i + 1);

                if ($end === false) {
                    return null;
                }

                $result .= substr($cleaned, $i + 1, $end - $i - 1);
                $i = $end;
                continue;
            }

            $matched = null;

            foreach (self::DATE_FORMAT_MAP as $token => $replacement) {
                if (substr($cleaned, $i, strlen($token)) === $token) {
                    $matched = $replacement;
                    $i += strlen($token) - 1;
                    break;
                }
            }

            if ($matched === null) {
                if (in_array($cleaned[$i], ['-', '/', ':', ' ', '.'], true)) {
                    $result .= $cleaned[$i];
                    continue;
                }

                $supported = false;
                break;
            }

            $result .= $matched;
        }

        return $supported && $result !== '' ? $result : null;
    }

    /**
     * Buang qualifier schema fixture: APPS.PO_HEADERS -> PO_HEADERS.
     *
     * SQLite tidak punya konsep schema, jadi driver mock memakai satu
     * namespace datar. Nama tabel fixture sudah unik, jadi tidak ada ambiguitas.
     *
     * @param  array<int,string>  $schemas
     */
    private function stripSchemaQualifiers(string $sql, array $schemas): string
    {
        foreach ($schemas as $schema) {
            $escaped = preg_quote($schema, '/');

            $sql = (string) preg_replace('/"'.$escaped.'"\./', '', $sql);
            $sql = (string) preg_replace('/\b'.$escaped.'\.(?=[A-Za-z_])/', '', $sql);
        }

        return $sql;
    }

    /**
     * Sembunyikan literal string supaya transformasi regex tidak ikut
     * mengubah isi data.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function maskLiterals(string $sql): array
    {
        $scanner = SqlScanner::scan($sql);

        $spans = [];

        foreach ($scanner->tokens() as [$type, $value, $position]) {
            if ($type === SqlScanner::T_STRING) {
                $spans[] = [$position, strlen($value)];
            }
        }

        if ($spans === []) {
            return [$sql, []];
        }

        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $masked = '';
        $literals = [];
        $cursor = 0;

        foreach ($spans as [$position, $length]) {
            if ($position < $cursor) {
                continue;
            }

            $masked .= substr($sql, $cursor, $position - $cursor);

            $token = self::PLACEHOLDER_PREFIX.count($literals).self::PLACEHOLDER_SUFFIX;
            $literals[] = substr($sql, $position, $length);
            $masked .= $token;

            $cursor = $position + $length;
        }

        $masked .= substr($sql, $cursor);

        return [$masked, $literals];
    }

    /**
     * @param  array<int,string>  $literals
     */
    private function restoreLiterals(string $sql, array $literals): string
    {
        if ($literals === []) {
            return $sql;
        }

        return (string) preg_replace_callback(
            '/'.preg_quote(self::PLACEHOLDER_PREFIX, '/').'(\d+)'.preg_quote(self::PLACEHOLDER_SUFFIX, '/').'/',
            fn (array $matches) => $literals[(int) $matches[1]],
            $sql
        );
    }
}
