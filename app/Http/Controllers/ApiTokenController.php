<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\Dataset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Pengelolaan token API Tahap 2, diletakkan di halaman Driver.
 *
 * Hanya admin. Token disimpan sebagai hash; nilai aslinya hanya dikirim ke
 * browser SEKALI, lewat Inertia::flash() supaya tidak ikut tersimpan di session
 * dan tidak muncul lagi saat halaman dimuat ulang.
 */
class ApiTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        // Dicek SEBELUM token dibuat, supaya penolakan tidak menyisakan token
        // yang sudah tersimpan tapi tidak bisa ditampilkan.
        $this->assertSessionIsEncryptedOutsideLocal();

        $dataset = Dataset::with('target')->findOrFail($data['dataset_id']);

        [$token, $plain] = ApiToken::issue([
            'name' => $data['name'],
            'app_name' => $data['app_name'],
            'target_id' => $dataset->target_id,
            'dataset_id' => $dataset->id,
            'rate_limit_per_minute' => $data['rate_limit_per_minute'],
            'allowed_ips' => $this->normaliseIps($data['allowed_ips'] ?? null),
            'created_by' => $request->user()?->id,
        ]);

        /*
         * Nilai asli DIENKRIPSI sebelum masuk flash.
         *
         * Flash Inertia berarti lewat session. Dengan session driver `database`
         * dan SESSION_ENCRYPT=false, payload session tersimpan apa adanya di
         * tabel `sessions` — artinya token plaintext akan menetap di database
         * sampai sesi kadaluarsa, dan hashing di api_tokens jadi tidak berarti.
         *
         * Dienkripsi dengan APP_KEY supaya session yang bocor tidak cukup
         * untuk mendapat token. Dipakai Crypt, bukan flash biasa, karena
         * nilainya lalu ditarik manual oleh DriverController agar bisa
         * didekripsi di server sebelum dikirim ke browser.
         */
        Inertia::flash('issuedToken', [
            'uid' => $token->uid,
            'plain' => Crypt::encryptString($plain),
            'app_name' => $token->app_name,
            'dataset' => $dataset->name,
            'target_uid' => $dataset->target?->uid,
            'dataset_uid' => $dataset->uid,
        ]);

        return back()->with('success', 'Token untuk "'.$dataset->name.'" dibuat. Salin sekarang, nilai ini tidak akan ditampilkan lagi.');
    }

    /**
     * Menolak menerbitkan token kalau session tidak terenkripsi di produksi.
     *
     * Gagal secara tertutup memang lebih baik daripada menyimpan rahasia yang
     * tidak terenkripsi. Di luar produksi tetap dibolehkan supaya development
     * dan test tidak tersendat.
     */
    private function assertSessionIsEncryptedOutsideLocal(): void
    {
        if (config('app.env') === 'production' && ! config('session.encrypt')) {
            throw ValidationException::withMessages([
                'token' => 'SESSION_ENCRYPT harus true di produksi sebelum token bisa diterbitkan.',
            ]);
        }
    }

    public function revoke(Request $request, ApiToken $token): RedirectResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        if (! $token->isActive()) {
            throw ValidationException::withMessages([
                'token' => 'Token ini sudah dicabut sebelumnya.',
            ]);
        }

        $token->revoke($request->string('reason')->toString() ?: 'Dicabut dari halaman Driver');

        return back()->with('success', 'Token '.$token->uid.' dicabut. Aplikasi yang memakainya langsung mendapat 403.');
    }

    /**
     * @return array<string,mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'app_name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'dataset_id' => ['required', Rule::exists('datasets', 'id')],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:600'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Terima daftar IP yang dikoma atau dipisah baris baru.
     *
     * @return array<int,string>|null
     */
    private function normaliseIps(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $ips = preg_split('/[\s,]+/', trim($raw)) ?: [];

        $clean = [];

        foreach ($ips as $ip) {
            if ($ip === '') {
                continue;
            }

            // filter_var menolak baik IPv4 maupun IPv6 yang tidak valid.
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw ValidationException::withMessages([
                    'allowed_ips' => '"'.$ip.'" bukan alamat IP yang valid.',
                ]);
            }

            $clean[] = $ip;
        }

        return $clean === [] ? null : array_values(array_unique($clean));
    }
}