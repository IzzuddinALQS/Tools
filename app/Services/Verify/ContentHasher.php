<?php

namespace App\Services\Verify;

/**
 * Hash konten yang bebas-urutan dan streaming.
 *
 * Kenapa tidak `sha256` atas seluruh teks yang dibaca? Dua alasan:
 *
 *  1. MEMORI. Query bisa jutaan baris; tidak boleh dimuat seluruhnya.
 *  2. URUTAN. Oracle tidak menjamin urutan baris tanpa ORDER BY. Query yang
 *     sama dua kali bisa mengembalikan baris dengan urutan berbeda, dan hash
 *     harus tetap sama.
 *
 * Solusinya: digest per baris, lalu digabung dengan dua agregat yang
 * bebas-urutan:
 *
 *   count : jumlah baris
 *   xor   : XOR semua digest baris
 *   sum   : penjumlahan digest baris per byte (mod 256)
 *
 * XOR saja tidak cukup: dua baris identik yang di-OR-kan dua kali akan
 * saling meniadakan (xor = 0). Pasangan XOR + SUM + COUNT menutup celah itu:
 * jumlah baris berbeda akan langsung terlihat, dan konsekuensi perubahan
 * satu baris hampir selalu mengubah salah satu dari xor/sum.
 *
 * Digest akhir = sha256(count || xor || sum), diformat heksadesimal.
 */
final class ContentHasher
{
    private const DIGEST_LENGTH = 32;

    private int $count = 0;

    /** @var array<int,int>  32 byte */
    private array $xor = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

    /** @var array<int,int>  32 byte */
    private array $sum = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

    /**
     * Masukkan satu baris yang nilainya SUDAH dikanonikalisasi.
     *
     * @param  array<int,?string>  $canonicalValues
     */
    public function addRow(array $canonicalValues): void
    {
        $digest = hash('sha256', self::serializeRow($canonicalValues), true);

        $this->count++;

        for ($i = 0; $i < self::DIGEST_LENGTH; $i++) {
            $byte = ord($digest[$i]);
            $this->xor[$i] ^= $byte;
            $this->sum[$i] = ($this->sum[$i] + $byte) & 0xFF;
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    public function digest(): string
    {
        $xor = '';
        $sum = '';

        foreach ($this->xor as $byte) {
            $xor .= chr($byte);
        }

        foreach ($this->sum as $byte) {
            $sum .= chr($byte);
        }

        return hash('sha256', pack('N', $this->count).$xor.$sum);
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /**
     * @return array{row_count:int,hash:string}
     */
    public function toArray(): array
    {
        return [
            'row_count' => $this->count,
            'hash' => $this->digest(),
        ];
    }

    /**
     * Serialisasi baris yang tidak ambigu.
     *
     * Panjang awalan (length prefix) membuat setiap nilai dapat dibaca secara
     * unik, sehingga nilai yang berisi karakter pemisah tidak bisa menabrak
     * nilai berikutnya.
     *
     * @param  array<int,?string>  $values
     */
    public static function serializeRow(array $values): string
    {
        $out = '';

        foreach ($values as $value) {
            if ($value === null) {
                $out .= 'N';
                continue;
            }

            $out .= 'S'.strlen($value).':'.$value;
        }

        return $out;
    }
}
