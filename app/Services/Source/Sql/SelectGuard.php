<?php

namespace App\Services\Source\Sql;

use App\Services\Source\Exceptions\SelectGuardException;

/**
 * Penjaga lapis kedua sebelum statement dikirim ke Oracle (Keamanan 1).
 *
 * Menerima HANYA satu statement SELECT / WITH, dan menolak:
 *   DML, DDL, PL/SQL, `;` ganda, FOR UPDATE, dan db link.
 *
 * PENGAMAN UTAMA tetap user Oracle yang SELECT-only (dibuat DBA). Kelas ini
 * menangkap kesalahan penulisan user, bukan serangan.
 */
class SelectGuard
{
    /**
     * @throws SelectGuardException
     */
    public function assert(string $sql): void
    {
        $reasons = $this->violations($sql);

        if ($reasons !== []) {
            throw new SelectGuardException(
                'SQL ditolak oleh guard. Hanya satu statement SELECT/WITH yang diizinkan. '
                .'Masalah: '.implode('; ', $reasons),
                $reasons,
            );
        }
    }

    public function passes(string $sql): bool
    {
        return $this->violations($sql) === [];
    }

    /**
     * @return array<int,string>
     */
    public function violations(string $sql): array
    {
        $reasons = [];
        $maxLength = (int) config('oracle-sync.guard.max_sql_length', 100_000);

        if (trim($sql) === '') {
            return ['SQL kosong.'];
        }

        if (strlen($sql) > $maxLength) {
            $reasons[] = "SQL terlalu panjang (maksimum {$maxLength} karakter).";
        }

        $scanner = SqlScanner::scan($sql);

        // --- harus DIMULAI dengan SELECT atau WITH ---
        //
        // Penting: bukan sekadar "mengandung" SELECT. Tanpa cek posisi,
        // `EXPLAIN PLAN FOR SELECT ...` akan lolos karena memuat kata SELECT.
        $firstWord = $this->firstSignificantWord($scanner);

        if ($firstWord !== 'SELECT' && $firstWord !== 'WITH') {
            $reasons[] = 'Statement harus dimulai dengan SELECT atau WITH'
                .($firstWord === null ? '.' : " (dimulai dengan \"{$firstWord}\").");
        }

        // --- hanya boleh satu statement ---
        $semicolons = 0;

        foreach ($scanner->tokens() as [$type, $value]) {
            if ($type === SqlScanner::T_PUNCT && $value === ';') {
                $semicolons++;
            }
        }

        if ($semicolons > 1) {
            $reasons[] = 'Hanya satu statement yang diizinkan (ditemukan beberapa tanda ";").';
        } elseif ($semicolons === 1) {
            // Tanda titik koma hanya boleh di akhir, sebagai terminator.
            $tokens = $scanner->tokens();
            $last = end($tokens);

            if ($last === false || $last[1] !== ';') {
                $reasons[] = 'Tanda ";" hanya boleh muncul di akhir statement.';
            }
        }

        // --- keyword terlarang ---
        $words = $scanner->words();
        $forbidden = array_map('strtoupper', config('oracle-sync.guard.forbidden_keywords', []));

        foreach ($forbidden as $keyword) {
            if (in_array($keyword, $words, true)) {
                $reasons[] = "Keyword terlarang: {$keyword}.";
            }
        }

        // --- pola berbahaya, diperiksa pada teks yang literal-nya sudah dibuang ---
        $masked = $scanner->masked();

        foreach ((array) config('oracle-sync.guard.forbidden_patterns', []) as $label => $pattern) {
            if (preg_match($pattern, $masked) === 1) {
                $reasons[] = match ($label) {
                    'for_update' => 'FOR UPDATE tidak diizinkan.',
                    'db_link' => 'Db link (@) tidak diizinkan.',
                    'execute_immediate' => 'EXECUTE IMMEDIATE tidak diizinkan.',
                    'dbms_package' => 'Paket DBMS_* tidak diizinkan.',
                    'utl_package' => 'Paket UTL_* tidak diizinkan.',
                    'dblink_package' => 'Paket DBMS_DB_LINK tidak diizinkan.',
                    'remote' => 'Kata remote tidak diizinkan.',
                    'pipe' => 'DBMS_PIPE tidak diizinkan.',
                    default => "Pola terlarang terdeteksi: {$label}.",
                };
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Kata pertama yang berarti, melewati kurung pembuka.
     * Mengembalikan null untuk SQL yang tidak punya kata sama sekali.
     */
    private function firstSignificantWord(SqlScanner $scanner): ?string
    {
        for ($i = 0; $i < $scanner->count(); $i++) {
            $type = $scanner->typeAt($i);
            $value = $scanner->valueAt($i);

            if ($type === SqlScanner::T_PUNCT && $value === '(') {
                continue;
            }

            if ($type === SqlScanner::T_WORD) {
                return strtoupper($value);
            }

            // Literal atau operator di depan: statement tidak valid.
            return null;
        }

        return null;
    }

    /**
     * Buang satu titik koma terminator di akhir, supaya SQL yang disimpan
     * sebagai versi dataset bersih.
     */
    public function normalize(string $sql): string
    {
        return trim(rtrim(trim($sql), ';'));
    }
}
