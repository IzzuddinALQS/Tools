<?php

namespace App\Services\Source\Sql;

/**
 * Scanner SQL minimal, sadar akan literal & komentar.
 *
 * Dipakai oleh SelectGuard (menolak DML/DDL/PL-SQL) dan BaseTableDetector
 * (mendeteksi apakah query membaca SATU tabel).
 *
 * Kenapa tokenizer, bukan regex mentah? Karena string literal Oracle boleh
 * berisi apa saja:
 *
 *     SELECT * FROM dual WHERE x = 'DROP TABLE users; --'
 *     SELECT 'FOR UPDATE' FROM dual
 *
 * Regex akan salah menolak (atau, lebih berbahaya, salah MELOLOSkan bila
 * payload dibuat LOOKLIKE). Scanner memisahkan literal dari kode.
 */
final class SqlScanner
{
    /** Token Finding. */
    public const T_WORD = 'word';

    public const T_STRING = 'string';

    public const T_QUOTED_IDENT = 'quoted_ident';

    public const T_NUMBER = 'number';

    public const T_PUNCT = 'punct';

    /** @var array<int,array{type:string,value:string,pos:int}> */
    private array $tokens = [];

    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public static function scan(string $sql): self
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            // --- whitespace ---
            if (ctype_space($char)) {
                $i++;
                continue;
            }

            // --- komentar baris ---
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // --- komentar blok (tidak bersarang di Oracle) ---
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            // --- q-quoted literal: q'[teks]', q'{teks}', q'(teks)', q'<teks>', q'XteksX' ---
            if (($char === 'q' || $char === 'Q')
                && ($sql[$i + 1] ?? '') === "'"
                && self::isWordBoundary($sql, $i)) {
                $consumed = self::scanQQuoted($sql, $i, $length);

                if ($consumed !== null) {
                    $i = $consumed;
                    continue;
                }
            }

            // --- string literal biasa, dengan '' sebagai escape ---
            if ($char === "'") {
                $start = $i;
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === "'") {
                        if (($sql[$i + 1] ?? '') === "'") {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $tokens[] = [self::T_STRING, substr($sql, $start, $i - $start), $start];
                continue;
            }

            // --- quoted identifier "COL" ---
            if ($char === '"') {
                $start = $i;
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === '"') {
                        if (($sql[$i + 1] ?? '') === '"') {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $tokens[] = [self::T_QUOTED_IDENT, substr($sql, $start, $i - $start), $start];
                continue;
            }

            // --- identifier / keyword ---
            if (ctype_alpha($char) || $char === '_' || $char === '$' || ord($char) >= 128) {
                $start = $i;
                while ($i < $length) {
                    $c = $sql[$i];
                    if (ctype_alnum($c) || $c === '_' || $c === '$' || $c === '#' || ord($c) >= 128) {
                        $i++;
                        continue;
                    }
                    break;
                }
                $tokens[] = [self::T_WORD, substr($sql, $start, $i - $start), $start];
                continue;
            }

            // --- bind variable :name atau :1 ---
            if ($char === ':' && ($sql[$i + 1] ?? '') !== ':') {
                $start = $i;
                $i++;
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_')) {
                    $i++;
                }
                if ($i > $start + 1) {
                    $tokens[] = [self::T_WORD, substr($sql, $start, $i - $start), $start];
                    continue;
                }
                $i = $start + 1;
                $tokens[] = [self::T_PUNCT, ':', $start];
                continue;
            }

            // --- angka ---
            if (ctype_digit($char) || ($char === '.' && ctype_digit($sql[$i + 1] ?? ''))) {
                $start = $i;
                while ($i < $length && (ctype_digit($sql[$i]) || $sql[$i] === '.'
                    || $sql[$i] === 'e' || $sql[$i] === 'E'
                    || (($sql[$i] === '+' || $sql[$i] === '-') && ($sql[$i - 1] === 'e' || $sql[$i - 1] === 'E')))) {
                    $i++;
                }
                $tokens[] = [self::T_NUMBER, substr($sql, $start, $i - $start), $start];
                continue;
            }

            $tokens[] = [self::T_PUNCT, $char, $i];
            $i++;
        }

        return new self($tokens);
    }

    /**
     * @return array<int,array{type:string,value:string,pos:int}>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * @return array<int,string>
     */
    public function words(): array
    {
        $words = [];

        foreach ($this->tokens as [$type, $value]) {
            if ($type === self::T_WORD) {
                $words[] = strtoupper($value);
            }
        }

        return $words;
    }

    public function hasWord(string $word): bool
    {
        return in_array(strtoupper($word), $this->words(), true);
    }

    /**
     * Berapa banyak kata `word` yang muncul, berurutan.
     */
    public function hasSequence(string ...$words): bool
    {
        $words = array_map('strtoupper', $words);
        $count = count($words);

        for ($i = 0; $i + $count <= count($this->tokens); $i++) {
            $ok = true;

            for ($j = 0; $j < $count; $j++) {
                [$type, $value] = $this->tokens[$i + $j];

                if ($type !== self::T_WORD || strtoupper($value) !== $words[$j]) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kata pada posisi indeks token tertentu (null bila bukan word).
     */
    public function wordAt(int $index): ?string
    {
        if (! isset($this->tokens[$index])) {
            return null;
        }

        [$type, $value] = $this->tokens[$index];

        return $type === self::T_WORD ? strtoupper($value) : null;
    }

    public function typeAt(int $index): ?string
    {
        return $this->tokens[$index][0] ?? null;
    }

    public function valueAt(int $index): ?string
    {
        return $this->tokens[$index][1] ?? null;
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    /**
     * Teks dengan literal & identifier di-quote dibuang total.
     * Dipakai untuk mendeteksi pola berbahaya (@ db link, FOR UPDATE, dll).
     */
    public function masked(): string
    {
        $out = '';

        foreach ($this->tokens as [$type, $value]) {
            $out .= match ($type) {
                self::T_STRING => "''",
                self::T_QUOTED_IDENT => '""',
                default => $value,
            };
            $out .= ' ';
        }

        return $out;
    }

    private static function isWordBoundary(string $sql, int $index): bool
    {
        if ($index === 0) {
            return true;
        }

        $prev = $sql[$index - 1];

        return ! (ctype_alnum($prev) || $prev === '_' || $prev === '$');
    }

    /**
     * @return int|null posisi baru setelah q-quoted literal, null bila bukan
     */
    private static function scanQQuoted(string $sql, int $i, int $length): ?int
    {
        $open = $sql[$i + 2] ?? null;

        if ($open === null) {
            return null;
        }

        $close = match ($open) {
            '[' => ']',
            '{' => '}',
            '(' => ')',
            '<' => '>',
            default => $open,
        };

        $multiChar = $close !== $open;
        $i += 3;
        $end = $multiChar ? strpos($sql, $close, $i) : false;

        if ($end === false) {
            // q-quoted dengan karakter delimiter tunggal: cari berulangan penutup
            for ($j = $i; $j + 1 < $length; $j++) {
                if ($sql[$j] === $close && $sql[$j + 1] === "'") {
                    return $j + 2;
                }
            }

            return $length;
        }

        return $end + 1;
    }
}
