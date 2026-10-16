<?php

namespace App\Http\Controllers;

use App\Enums\DriverEnum;
use App\Enums\TargetKindEnum;
use App\Models\ApiToken;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\Target;
use App\Services\Pg\PgConnectionResolver;
use App\Services\Source\SourceReaderFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Driver: koneksi sumber (mock / oracle) dan target PostgreSQL.
 * Hanya admin (middleware EnsureAdmin).
 *
 * Password TIDAK PERNAH dikirim balik ke browser: kolomnya hidden di model dan
 * form memakai placeholder "kosongkan bila tidak diubah".
 */
class DriverController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Driver', [
            'connections' => Connection::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Connection $connection) => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'description' => $connection->description,
                    'driver' => $connection->driver->value,
                    'driver_label' => $connection->driver->label(),
                    'host' => $connection->host,
                    'port' => $connection->port,
                    'service_name' => $connection->service_name,
                    'sid' => $connection->sid,
                    'tns_alias' => $connection->tns_alias,
                    'username' => $connection->username,
                    // Tidak pernah dikirim: hanya flag "sudah diisi".
                    'has_password' => $connection->password !== null,
                    'options' => $connection->options,
                    'fixture_set' => $connection->fixture_set,
                    'is_active' => $connection->is_active,
                    'last_tested_at' => $connection->last_tested_at?->toDateTimeString(),
                    'last_test_status' => $connection->last_test_status,
                    'last_test_message' => $connection->last_test_message,
                ]),
            'targets' => Target::query()
                ->orderBy('kind')
                ->orderBy('name')
                ->get()
                ->map(fn (Target $target) => [
                    'id' => $target->id,
                    'name' => $target->name,
                    'description' => $target->description,
                    'kind' => $target->kind->value,
                    'kind_label' => $target->kind->label(),
                    'app_name' => $target->app_name,
                    'host' => $target->host,
                    'port' => $target->port,
                    'database' => $target->database,
                    'username' => $target->username,
                    'has_password' => $target->password !== null,
                    'readonly_username' => $target->readonly_username,
                    'has_readonly_password' => $target->readonly_password !== null,
                    'is_active' => $target->is_active,
                    'last_tested_at' => $target->last_tested_at?->toDateTimeString(),
                    'last_test_status' => $target->last_test_status,
                    'last_test_message' => $target->last_test_message,
                ]),
            'defaults' => [
                'oracle_port' => config('oracle-sync.defaults.oracle_port'),
                'pg_port' => config('oracle-sync.defaults.pg_port'),
                'driver' => Connection::defaultDriver()->value,
                'drivers' => array_map(fn (DriverEnum $d) => [
                    'value' => $d->value,
                    'label' => $d->label(),
                ], DriverEnum::cases()),
                'target_kinds' => array_map(fn (TargetKindEnum $k) => [
                    'value' => $k->value,
                    'label' => $k->label(),
                ], TargetKindEnum::cases()),
            ],

            // Token API Tahap 2. Hash tidak pernah dikirim ke browser.
            'tokens' => ApiToken::query()
                ->with(['dataset:id,name,uid', 'target:id,name,uid'])
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (ApiToken $token) => [
                    'id' => $token->id,
                    'uid' => $token->uid,
                    'name' => $token->name,
                    'app_name' => $token->app_name,
                    'dataset' => $token->dataset?->name,
                    'dataset_uid' => $token->dataset?->uid,
                    'target_uid' => $token->target?->uid,
                    'rate_limit_per_minute' => $token->rate_limit_per_minute,
                    'allowed_ips' => $token->allowed_ips,
                    'last_used_at' => $token->last_used_at?->toDateTimeString(),
                    'revoked_at' => $token->revoked_at?->toDateTimeString(),
                    'revoked_reason' => $token->revoked_reason,
                    'created_at' => $token->created_at?->toDateTimeString(),
                    'is_active' => $token->isActive(),
                ]),

            'tokenableDatasets' => Dataset::query()
                ->with('target:id,name,uid')
                ->orderBy('name')
                ->get()
                ->map(fn (Dataset $dataset) => [
                    'id' => $dataset->id,
                    'name' => $dataset->name,
                    'target' => $dataset->target?->name,
                    'target_uid' => $dataset->target?->uid,
                    'uid' => $dataset->uid,
                ]),
        ]);
    }

    public function storeConnection(Request $request): RedirectResponse
    {
        $data = $request->validate($this->connectionRules());

        // Password tidak wajib saat mock: tidak ada kredensial sungguhan.
        if (($data['driver'] ?? 'mock') === 'oracle' && empty($data['password'])) {
            return back()->withErrors(['password' => 'Password Oracle wajib diisi untuk driver oracle.']);
        }

        $connection = Connection::create([
            ...$this->connectionAttributes($data),
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Koneksi sumber "'.$connection->name.'" dibuat.');
    }

    public function updateConnection(Request $request, Connection $connection): RedirectResponse
    {
        $data = $request->validate($this->connectionRules($connection->id));

        $attributes = $this->connectionAttributes($data);

        // Password kosong = tidak diubah, BUKAN dihapus.
        if (($attributes['password'] ?? null) === null) {
            unset($attributes['password']);
        }

        $connection->update($attributes);

        // Reader harus dibangun ulang karena kredensialnya berubah.
        app(SourceReaderFactory::class)->forget($connection);

        return back()->with('success', 'Koneksi sumber "'.$connection->name.'" diperbarui.');
    }

    public function destroyConnection(Connection $connection): RedirectResponse
    {
        if ($connection->datasets()->exists()) {
            return back()->withErrors([
                'name' => 'Koneksi ini masih dipakai dataset. Hapus atau pindahkan dataset dulu.',
            ]);
        }

        $connection->delete();

        return back()->with('success', 'Koneksi sumber dihapus.');
    }

    public function storeTarget(Request $request): RedirectResponse
    {
        $data = $request->validate($this->targetRules());

        $target = Target::create([
            ...$this->targetAttributes($data),
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Target "'.$target->name.'" dibuat.');
    }

    public function updateTarget(Request $request, Target $target): RedirectResponse
    {
        $data = $request->validate($this->targetRules($target->id));

        $attributes = $this->targetAttributes($data);

        foreach (['password', 'readonly_password'] as $secret) {
            if (($attributes[$secret] ?? null) === null) {
                unset($attributes[$secret]);
            }
        }

        $target->update($attributes);

        // Koneksi runtime harus dibangun ulang karena kredensial berubah.
        app(PgConnectionResolver::class)->forget($target);

        return back()->with('success', 'Target "'.$target->name.'" diperbarui.');
    }

    public function destroyTarget(Target $target): RedirectResponse
    {
        if ($target->isPgA() && $target->datasets()->exists()) {
            return back()->withErrors([
                'name' => 'PG A masih dipakai dataset. Hapus dataset-nya dulu.',
            ]);
        }

        $target->delete();

        return back()->with('success', 'Target dihapus.');
    }

    /**
     * Tes koneksi sumber (mock atau Oracle). Tidak menyimpan password baru.
     */
    public function testConnection(Connection $connection, SourceReaderFactory $readers): RedirectResponse
    {
        try {
            $readers->for($connection)->test();

            $connection->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'ok',
                'last_test_message' => 'Koneksi berhasil.',
            ])->save();

            return back()->with('success', 'Koneksi berhasil: '.$connection->safeLabel());
        } catch (\Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);

            $connection->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'failed',
                'last_test_message' => $message,
            ])->save();

            return back()->withErrors(['name' => $message]);
        }
    }

    /**
     * Tes koneksi PostgreSQL target (PG A / PG B).
     */
    public function testTarget(Target $target, PgConnectionResolver $resolver): RedirectResponse
    {
        try {
            $resolver->resolve($target)->select('SELECT 1');

            $target->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'ok',
                'last_test_message' => 'Koneksi berhasil.',
            ])->save();

            return back()->with('success', 'Koneksi berhasil: '.$target->safeLabel());
        } catch (\Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);

            $target->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'failed',
                'last_test_message' => $message,
            ])->save();

            return back()->withErrors(['name' => $message]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function connectionRules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('connections', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'driver' => ['required', Rule::in([DriverEnum::Mock->value, DriverEnum::Oracle->value])],
            'host' => ['nullable', 'string', 'max:255', 'required_if:driver,oracle'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'service_name' => ['nullable', 'string', 'max:255'],
            'sid' => ['nullable', 'string', 'max:255'],
            'tns_alias' => ['nullable', 'string', 'max:255'],
            'dsn' => ['nullable', 'string', 'max:2000'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'fixture_set' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function targetRules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('targets', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'kind' => [
                'required',
                Rule::in([TargetKindEnum::PgA->value, TargetKindEnum::PgB->value]),
                // Hanya boleh ada SATU PostgreSQL A. Partial unique index di
                // database tetap menjadi penjaga terakhir, tapi pesan dari
                // validasi jauh lebih ramah daripada error SQL mentah.
                function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId): void {
                    if ($value !== TargetKindEnum::PgA->value) {
                        return;
                    }

                    $exists = Target::query()
                        ->where('kind', TargetKindEnum::PgA->value)
                        ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                        ->exists();

                    if ($exists) {
                        $fail('Hanya boleh ada satu PostgreSQL A. Edit target yang sudah ada, bukan membuat yang baru.');
                    }
                },
            ],
            'app_name' => [
                'nullable', 'string', 'max:100',
                'required_if:kind,pg_b',
                Rule::unique('targets', 'app_name')->where('kind', TargetKindEnum::PgB->value)->ignore($ignoreId),
            ],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'sslmode' => ['nullable', 'string', 'max:20'],
            'readonly_username' => ['nullable', 'string', 'max:150'],
            'readonly_password' => ['nullable', 'string', 'max:2000'],
            'auto_provision_readonly' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function connectionAttributes(array $data): array
    {
        $driver = DriverEnum::from($data['driver']);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'driver' => $driver,
            'host' => $data['host'] ?? null,
            'port' => $data['port'] ?? config('oracle-sync.defaults.oracle_port'),
            'service_name' => $data['service_name'] ?? null,
            'sid' => $data['sid'] ?? null,
            'tns_alias' => $data['tns_alias'] ?? null,
            'dsn' => $data['dsn'] ?? null,
            'username' => $data['username'] ?? null,
            // String kosong berarti "tidak diisi".
            'password' => ($data['password'] ?? '') === '' ? null : $data['password'],
            'fixture_set' => $data['fixture_set'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function targetAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'kind' => TargetKindEnum::from($data['kind']),
            'app_name' => ($data['app_name'] ?? '') === '' ? null : $data['app_name'],
            'host' => $data['host'],
            'port' => $data['port'],
            'database' => $data['database'],
            'username' => $data['username'],
            'password' => ($data['password'] ?? '') === '' ? null : $data['password'],
            'sslmode' => ($data['sslmode'] ?? null) ?: 'prefer',
            'readonly_username' => ($data['readonly_username'] ?? '') === '' ? null : $data['readonly_username'],
            'readonly_password' => ($data['readonly_password'] ?? '') === '' ? null : $data['readonly_password'],
            'auto_provision_readonly' => (bool) ($data['auto_provision_readonly'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
