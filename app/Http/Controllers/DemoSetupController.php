<?php

namespace App\Http\Controllers;

use App\Enums\DriverEnum;
use App\Enums\RunKindEnum;
use App\Enums\RunTriggerEnum;
use App\Enums\TargetKindEnum;
use App\Models\Connection;
use App\Models\Dataset;
use App\Models\Target;
use App\Services\Source\Exceptions\SyncException;
use App\Services\Sync\DatasetManager;
use App\Services\Sync\SyncRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tombol "Coba dengan data contoh" di Beranda.
 *
 * Menyiapkan satu alur lengkap tanpa Oracle sungguhan: koneksi Data contoh
 * (driver mock), Database Penampung, dan satu dataset, lalu langsung
 * mengambil datanya. Aman diklik berulang: yang sudah ada dipakai ulang.
 */
class DemoSetupController extends Controller
{
    public const CONNECTION_NAME = 'contoh-oracle';

    public const DATASET_NAME = 'contoh-pesanan';

    public const PG_A_DATABASE = 'ods_contoh_penampung';

    private const SAMPLE_SQL = 'SELECT PO_HEADER_ID, PO_NUMBER, STATUS_CODE, ORDER_DATE FROM APPS.PO_HEADERS';

    public function __invoke(Request $request, DatasetManager $datasets, SyncRunner $runner): RedirectResponse
    {
        $existing = Dataset::where('name', self::DATASET_NAME)->first();

        if ($existing !== null) {
            return redirect()->route('datasets.show', $existing->id)
                ->with('success', 'Dataset contoh sudah ada. Tekan "Ambil data sekarang" untuk mencobanya lagi.');
        }

        // PG A lebih dulu: CREATE DATABASE tidak boleh berada di dalam transaksi.
        $target = Target::where('kind', TargetKindEnum::PgA)->first() ?? $this->createPgA($request);

        if ($target === null) {
            return redirect('/drivers?tab=targets')->with(
                'error',
                'Database Penampung belum ada dan tidak bisa dibuat otomatis. Tambahkan Database Penampung dulu, lalu klik "Coba dengan data contoh" lagi.',
            );
        }

        try {
            // Satu transaksi: bila dataset gagal dibuat, koneksi contoh ikut batal.
            $result = DB::transaction(function () use ($request, $datasets, $target) {
                $connection = Connection::firstOrCreate(
                    ['name' => self::CONNECTION_NAME],
                    [
                        'description' => 'Oracle tiruan berisi data contoh.',
                        'driver' => DriverEnum::Mock,
                        'fixture_set' => 'armors',
                        'is_active' => true,
                        'created_by' => $request->user()?->id,
                    ],
                );

                return $datasets->create([
                    'name' => self::DATASET_NAME,
                    'description' => 'Contoh: daftar pesanan pembelian dari data contoh.',
                    'connection_id' => $connection->id,
                    'target_id' => $target->id,
                    'sql' => self::SAMPLE_SQL,
                    'sync_mode' => 'full',
                    'schedule_cron' => '',
                    'note' => 'Dibuat dari tombol data contoh.',
                ], $request->user()?->id);
            });
        } catch (SyncException $exception) {
            // Paling sering: tabel tujuan sudah dimiliki dataset lain.
            return redirect()->route('datasets.index')->with(
                'error',
                'Dataset contoh tidak bisa dibuat: '.$exception->getMessage(),
            );
        }

        $dataset = $result['dataset'];

        $run = $runner->run($dataset, RunTriggerEnum::Manual, RunKindEnum::Sync, $request->user()?->id);

        return redirect()->route('datasets.show', $dataset->id)->with(
            $run->status->value === 'success' ? 'success' : 'error',
            $run->status->value === 'success'
                ? 'Data contoh siap: '.$run->row_count.' baris berhasil diambil dan diperiksa. Silakan jelajahi!'
                : 'Dataset contoh dibuat, tetapi pengambilan data gagal: '.$run->error_message,
        );
    }

    /**
     * Buat database penampung di server PostgreSQL yang sama dengan
     * database aplikasi ini. Gagal (mis. akun tidak boleh CREATE DATABASE)
     * berarti admin harus menambahkannya sendiri.
     */
    private function createPgA(Request $request): ?Target
    {
        $default = (string) config('database.default');
        $config = (array) config('database.connections.'.$default);

        if (($config['driver'] ?? null) !== 'pgsql') {
            return null;
        }

        try {
            $exists = DB::connection($default)
                ->selectOne('SELECT 1 AS ada FROM pg_database WHERE datname = ?', [self::PG_A_DATABASE]);

            if ($exists === null) {
                DB::connection($default)->statement('CREATE DATABASE "'.self::PG_A_DATABASE.'"');
            }
        } catch (Throwable) {
            return null;
        }

        return Target::create([
            'name' => 'penampung-contoh',
            'description' => 'Dibuat otomatis oleh tombol data contoh.',
            'kind' => TargetKindEnum::PgA,
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => (int) ($config['port'] ?? 5432),
            'database' => self::PG_A_DATABASE,
            'username' => $config['username'] ?? 'postgres',
            'password' => ($config['password'] ?? '') === '' ? null : $config['password'],
            'sslmode' => $config['sslmode'] ?? 'prefer',
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);
    }
}
