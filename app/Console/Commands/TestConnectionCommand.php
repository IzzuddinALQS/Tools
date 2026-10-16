<?php

namespace App\Console\Commands;

use App\Models\Connection;
use App\Services\Source\Exceptions\SyncException;
use App\Services\Source\SourceReaderFactory;
use Illuminate\Console\Command;
use Throwable;

/**
 * "Tes Koneksi" dari UI, dipanggil lewat artisan.
 *
 * Password tidak pernah dicetak, tidak pernah masuk log.
 */
class TestConnectionCommand extends Command
{
    protected $signature = 'oracle-sync:test-connection {connection : ID atau nama koneksi}
        {--json : Keluarkan hasil dalam JSON}';

    protected $description = 'Uji koneksi sumber data (mock atau Oracle) tanpa menyimpan apa pun.';

    public function handle(SourceReaderFactory $readers): int
    {
        $connection = Connection::query()
            ->where('name', $this->argument('connection'))
            ->orWhere('id', is_numeric($this->argument('connection')) ? (int) $this->argument('connection') : 0)
            ->first();

        if ($connection === null) {
            $this->report('error', (string) $this->argument('connection'), 'Koneksi tidak ditemukan.');

            return self::FAILURE;
        }

        try {
            $readers->for($connection)->test();

            $connection->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'ok',
                'last_test_message' => 'Koneksi berhasil.',
            ])->save();

            $this->report('ok', $connection->safeLabel(), 'Koneksi berhasil.');

            return self::SUCCESS;
        } catch (SyncException $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);

            $connection->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'failed',
                'last_test_message' => $message,
            ])->save();

            $this->report('error', $connection->safeLabel(), $message);

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->report('error', $connection->safeLabel(), mb_substr($exception->getMessage(), 0, 300));

            return self::FAILURE;
        }
    }

    /**
     * Keluarkan JSON bila --json dipakai, supaya bisa dipakai oleh pemanggil
     * non-manual (mis. health check server).
     */
    private function report(string $status, string $label, string $message): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $status,
                'connection' => $label,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        if ($status === 'ok') {
            $this->info('Koneksi berhasil: '.$label);

            return;
        }

        $this->error($label.': '.$message);
    }
}
