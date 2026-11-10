<?php

namespace App\Console\Commands;

use App\Models\OfficeNetwork;
use App\Models\ScanRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class ScanNetworks extends Command
{
    protected $signature = 'network:scan';
    protected $description = 'I-scan ang matag office subnet ug i-save ang online nga mga device';

    public function handle(): int
    {
        $nmap = config('services.nmap.binary');

        if (blank($nmap)) {
            $this->error('Walay NMAP_BINARY sa config. Susiha ang .env ug config/services.php, unya i-run ang php artisan config:clear.');
            return self::FAILURE;
        }

        // Kung full path ang gigamit, i-check nga naa gyud ang file
        if ((str_contains($nmap, '\\') || str_contains($nmap, '/')) && ! file_exists($nmap)) {
            $this->error("Wala makita ang nmap sa: {$nmap}");
            return self::FAILURE;
        }

        foreach (OfficeNetwork::all() as $net) {
            $started = microtime(true);

            $result = Process::timeout(180)->run([$nmap, '-sn', '-oX', '-', $net->cidr]);

            if ($result->failed()) {
                $this->warn("{$net->office}: " . trim($result->errorOutput()));
                continue;
            }

            $xml = @simplexml_load_string($result->output());
            if ($xml === false) {
                $this->warn("{$net->office}: dili ma-basa ang output sa nmap");
                continue;
            }

            $hosts = [];
            foreach ($xml->host as $host) {
                if ((string) $host->status['state'] !== 'up') {
                    continue;
                }

                $ip = $mac = $vendor = null;
                foreach ($host->address as $addr) {
                    $type = (string) $addr['addrtype'];
                    if ($type === 'ipv4') {
                        $ip = (string) $addr['addr'];
                    } elseif ($type === 'mac') {
                        $mac = (string) $addr['addr'];
                        $vendor = ((string) $addr['vendor']) ?: null;
                    }
                }

                if (! $ip) {
                    continue;
                }

                $hosts[] = [
                    'ip'         => $ip,
                    'mac'        => $mac,
                    'vendor'     => $vendor,
                    'hostname'   => ((string) $host->hostnames->hostname['name']) ?: null,
                    'random_mac' => $this->isRandomMac($mac),
                ];
            }

            DB::transaction(function () use ($net, $hosts, $started) {
                $run = ScanRun::create([
                    'office'      => $net->office,
                    'cidr'        => $net->cidr,
                    'hosts_up'    => count($hosts),
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                    'scanned_at'  => now(),
                ]);

                $run->hosts()->createMany($hosts);
            });

            $this->info("{$net->office}: " . count($hosts) . ' ka device ang online.');
        }

        return self::SUCCESS;
    }

    // Locally-administered bit: ang ikaduha nga karakter sa MAC kay 2, 6, A, o E
    private function isRandomMac(?string $mac): bool
    {
        return $mac !== null && in_array(strtoupper($mac[1]), ['2', '6', 'A', 'E'], true);
    }
}