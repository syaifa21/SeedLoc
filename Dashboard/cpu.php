<?php

class AdvancedMonitor {
    private $config;

    public function __construct() {
        $this->config = [
            'refresh'  => $_GET['interval'] ?? 10, // Default 10 detik
            'services' => ['mysql', 'nginx', 'apache2', 'redis-server'],
            'db_path'  => '/var/lib/mysql',
            'domains'  => ['https://facebook.com'],
            'logs'     => '/var/log/nginx/access.log' // Path log akses
        ];
    }

    public function get_stats() {
        return [
            'uptime'   => trim(shell_exec("uptime -p")),
            'cpu'      => $this->get_cpu(),
            'ram'      => $this->get_ram(),
            'disk'     => $this->get_disk(),
            'services' => $this->get_services(),
            'ssl'      => $this->get_ssl(),
            'db_size'  => $this->get_db_size(),
            'network'  => $this->get_network(),
            'top_proc' => $this->get_top_processes(),
            'traffic'  => $this->get_web_traffic()
        ];
    }

    private function get_cpu() {
        $load = sys_getloadavg();
        $cores = (int)shell_exec('nproc') ?: 1;
        return round(($load[0] / $cores) * 100, 2);
    }

    private function get_ram() {
        $free = shell_exec('free');
        $lines = explode("\n", trim($free));
        $mem = array_values(array_filter(explode(" ", $lines[1])));
        return round(($mem[2] / $mem[1]) * 100, 2);
    }

    private function get_disk() {
        return round(100 - ((disk_free_space("/") / disk_total_space("/") * 100)), 2);
    }

    private function get_services() {
        $res = [];
        foreach ($this->config['services'] as $s) {
            $check = shell_exec("systemctl is-active $s 2>&1");
            $res[$s] = trim($check) === 'active';
        }
        return $res;
    }

    private function get_ssl() {
        $res = [];
        foreach ($this->config['domains'] as $d) {
            $oranda = stream_context_create(["ssl" => ["capture_peer_cert" => true]]);
            $read = @stream_socket_client("ssl://$d:443", $err, $errs, 5, STREAM_CLIENT_CONNECT, $oranda);
            if ($read) {
                $cert = openssl_x509_parse(stream_context_get_params($read)["options"]["ssl"]["peer_certificate"]);
                $res[$d] = floor(($cert['validTo_time_t'] - time()) / 86400); // Sisa hari
            }
        }
        return $res;
    }

    private function get_db_size() {
        return explode("\t", shell_exec("du -sh " . $this->config['db_path'] . " 2>/dev/null"))[0] ?: "N/A";
    }

    private function get_network() {
        return [
            'est' => (int)shell_exec("netstat -an | grep ESTABLISHED | wc -l"),
            'wait' => (int)shell_exec("netstat -an | grep TIME_WAIT | wc -l")
        ];
    }

    private function get_top_processes() {
        $out = shell_exec("ps -eo pcpu,pmem,comm --sort=-pcpu | head -n 6 | tail -n 5");
        return explode("\n", trim($out));
    }

    private function get_web_traffic() {
        if (!file_exists($this->config['logs'])) return "No Log Found";
        return (int)shell_exec("tail -n 1000 " . $this->config['logs'] . " | awk '{print $1}' | sort | uniq | wc -l");
    }

    public function get_interval() { return $this->config['refresh']; }
}

$monitor = new AdvancedMonitor();
$data = $monitor->get_stats();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Server Monitor Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta http-equiv="refresh" content="<?= $monitor->get_interval() ?>">
</head>
<body class="bg-black text-gray-200 p-6 font-mono">
    <div class="max-w-7xl mx-auto">
        
        <div class="flex flex-wrap justify-between items-center mb-6 bg-gray-900 p-4 rounded-lg border border-gray-800">
            <div>
                <h1 class="text-xl font-bold text-green-500">SYSTEM_MONITOR::<?= gethostname() ?></h1>
                <p class="text-xs text-gray-500">Uptime: <?= $data['uptime'] ?></p>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <label class="text-xs">Interval (s):</label>
                <input type="number" name="interval" value="<?= $monitor->get_interval() ?>" class="bg-black border border-gray-700 rounded px-2 py-1 w-16 text-center text-sm">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded text-xs">SET</button>
            </form>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
            <?php foreach(['CPU' => 'cpu', 'RAM' => 'ram', 'DISK' => 'disk'] as $l => $k): ?>
            <div class="bg-gray-900 p-4 rounded border border-gray-800">
                <div class="flex justify-between text-xs mb-2"><span><?= $l ?></span><span><?= $data[$k] ?>%</span></div>
                <div class="w-full bg-gray-800 h-1.5 rounded-full">
                    <div class="h-full <?= $data[$k] > 85 ? 'bg-red-500' : ($data[$k] > 60 ? 'bg-yellow-500' : 'bg-green-500') ?>" style="width: <?= $data[$k] ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="bg-gray-900 p-4 rounded border border-gray-800 text-center">
                <div class="text-xs text-gray-500 mb-1">DB SIZE</div>
                <div class="text-xl font-bold text-yellow-500"><?= $data['db_size'] ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="bg-gray-900 p-4 rounded border border-gray-800">
                <h3 class="text-xs font-bold mb-3 border-b border-gray-800 pb-2 text-blue-400">TOP PROCESSES (CPU%)</h3>
                <div class="text-[10px] space-y-1">
                    <?php foreach($data['top_proc'] as $proc): ?>
                    <div class="flex justify-between font-mono">
                        <span class="text-gray-400"><?= substr($proc, 12) ?></span>
                        <span class="text-green-500"><?= substr($proc, 0, 5) ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-gray-900 p-4 rounded border border-gray-800">
                <h3 class="text-xs font-bold mb-3 border-b border-gray-800 pb-2 text-blue-400">SERVICES & NETWORK</h3>
                <div class="grid grid-cols-2 gap-2 mb-4">
                    <?php foreach($data['services'] as $s => $a): ?>
                    <div class="text-[10px] p-1 bg-black rounded flex justify-between border border-gray-800">
                        <span><?= $s ?></span>
                        <span class="<?= $a ? 'text-green-500' : 'text-red-500' ?>"><?= $a ? '●' : '○' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-[10px] text-gray-500">
                    Connections: <span class="text-white"><?= $data['network']['est'] ?> EST</span> | 
                    Traffic (Uniq IP): <span class="text-white"><?= $data['traffic'] ?></span>
                </div>
            </div>

            <div class="bg-gray-900 p-4 rounded border border-gray-800">
                <h3 class="text-xs font-bold mb-3 border-b border-gray-800 pb-2 text-blue-400">SSL STATUS (DAYS LEFT)</h3>
                <?php foreach($data['ssl'] as $dom => $days): ?>
                <div class="flex justify-between text-[10px] mb-1">
                    <span><?= $dom ?></span>
                    <span class="<?= $days < 7 ? 'text-red-500' : 'text-green-500' ?>"><?= $days ?> Days</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>