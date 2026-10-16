<?php

namespace Tests\Unit;

use App\Support\TrustedProxy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Uji parsing TRUSTED_PROXIES.
 *
 * Nilai ini menentukan apakah `$request->ip()` mengembalikan IP klien atau IP
 * proxy, jadi kesalahan parsing berarti pembatasan IP menilai IP yang salah.
 */
class TrustedProxyTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function rawValues(): array
    {
        return [
            'string kosong' => ['', false],
            'spasi saja' => ['   ', false],
            'hanya koma' => [',', false],
            'satu ip' => ['10.0.0.1', true],
            'beberapa ip' => ['10.0.0.1,10.0.0.2', true],
            'dengan spasi' => [' 10.0.0.1 , 10.0.0.2 ', true],
            'pisah baris baru' => ["10.0.0.1\n10.0.0.2", true],
            'dengan koma menggantung' => ['10.0.0.1,', true],
            'cidr' => ['10.0.0.0/8', true],
        ];
    }

    #[DataProvider('rawValues')]
    public function test_parsing(string $raw, bool $shouldBeConfigured): void
    {
        putenv(TrustedProxy::ENV_KEY.'='.$raw);
        $_ENV[TrustedProxy::ENV_KEY] = $raw;
        $_SERVER[TrustedProxy::ENV_KEY] = $raw;

        $message = 'raw: '.var_export($raw, true)
            .' — diharapkan configured='.var_export($shouldBeConfigured, true);

        $this->assertSame($shouldBeConfigured, TrustedProxy::configured(), $message);

        $list = TrustedProxy::list();

        if (! $shouldBeConfigured) {
            $this->assertNull($list, $message.' — kosong harus berarti tidak ada proxy tepercaya.');

            return;
        }

        $this->assertIsArray($list, $message);
        $this->assertNotEmpty($list, $message);

        foreach ($list as $proxy) {
            $this->assertNotSame('', $proxy, $message.' — entri kosong tidak boleh ikut.');
        }
    }

    public function test_biarkan_kosong_tidak_pernah_jadi_semua(): void
    {
        /*
         * '*' membuat siapa pun bisa memalsukan X-Forwarded-For, jadi
         * pembatasan IP kehilangan seluruh artinya. Nilai kosong tidak boleh
         * diam-diam berubah jadi wildcard.
         */
        putenv(TrustedProxy::ENV_KEY);
        unset($_ENV[TrustedProxy::ENV_KEY], $_SERVER[TrustedProxy::ENV_KEY]);

        $this->assertNull(TrustedProxy::list());
        $this->assertFalse(TrustedProxy::configured());
    }
}