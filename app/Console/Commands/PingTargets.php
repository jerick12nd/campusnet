<?php

namespace App\Console\Commands;

use App\Models\MonitoredDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class PingTargets extends Command
{
    protected $signature = 'network:ping';
    protected $description = 'Ping the real monitored devices and the internet, and store latency and packet loss';

    public function handle(): int
    {
        $targets = MonitoredDevice::query()
            ->enabled()
            ->where('is_demo', false)
            ->get(['name', 'management_ip'])
            ->map(fn ($d) => ['label' => $d->name, 'target' => $d->management_ip])
            ->push(['label' => 'Internet (Google DNS)', 'target' => '8.8.8.8'])
            ->all();

        foreach ($targets as $t) {
            $result = $this->ping($t['target']);

            DB::table('link_checks')->insert([
                'label'        => $t['label'],
                'target'       => $t['target'],
                'is_up'        => $result['loss'] < 100,
                'loss_percent' => $result['loss'],
                'avg_ms'       => $result['avg'],
                'max_ms'       => $result['max'],
                'checked_at'   => now(),
            ]);

            $this->line(sprintf(
                '%s (%s): %s, loss %d%%, avg %s ms',
                $t['label'],
                $t['target'],
                $result['loss'] < 100 ? 'up' : 'DOWN',
                $result['loss'],
                $result['avg'] ?? '-',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{loss: int, avg: ?int, max: ?int}
     */
    private function ping(string $host): array
    {
        $windows = PHP_OS_FAMILY === 'Windows';

        $command = $windows
            ? ['ping', '-n', '4', '-w', '1000', $host]
            : ['ping', '-c', '4', '-W', '1', $host];

        $output = Process::timeout(20)->run($command)->output();

        if ($windows) {
            $loss = preg_match('/\((\d+)% loss\)/', $output, $m) ? (int) $m[1] : 100;
            $avg = preg_match('/Average = (\d+)ms/', $output, $m) ? (int) $m[1] : null;
            $max = preg_match('/Maximum = (\d+)ms/', $output, $m) ? (int) $m[1] : null;
        } else {
            $loss = preg_match('/([\d.]+)% packet loss/', $output, $m) ? (int) round((float) $m[1]) : 100;
            $avg = null;
            $max = null;

            if (preg_match('#= [\d.]+/([\d.]+)/([\d.]+)#', $output, $m)) {
                $avg = (int) round((float) $m[1]);
                $max = (int) round((float) $m[2]);
            }
        }

        return ['loss' => $loss, 'avg' => $avg, 'max' => $max];
    }
}