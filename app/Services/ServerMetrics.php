<?php

namespace App\Services;

class ServerMetrics
{
    public function snapshot(): array
    {
        // Desafio opcional: el mismo service funciona en Windows y en Linux.
        $sys = match (PHP_OS_FAMILY) {
            'Windows' => $this->readWindows(),
            'Linux'   => $this->readLinux(),
            default   => null,
        };

        return [
            'hostname'   => gethostname(),
            'os'         => php_uname('s') . ' ' . php_uname('r'),
            'php'        => PHP_VERSION,
            'disk'       => $this->disk(),
            'memory'     => $this->memory($sys),
            'cpu_pct'    => $sys['cpu_pct'] ?? null,
            'uptime_s'   => $sys['uptime_s'] ?? null,
            'cpu_temp_c' => $sys['temp_c'] ?? null,
            'at'         => now()->toIso8601String(),
        ];
    }

    private function disk(): array
    {
        $path  = base_path();
        $total = disk_total_space($path);
        $free  = disk_free_space($path);

        return [
            'total_gb' => round($total / 1e9, 1),
            'free_gb'  => round($free / 1e9, 1),
            'used_pct' => round(100 * ($total - $free) / $total, 1),
        ];
    }

    private function memory(?array $sys): ?array
    {
        if (empty($sys['mem_total_kb'])) {
            return null;
        }

        $total = $sys['mem_total_kb'];
        $free  = $sys['mem_free_kb'];

        return [
            'total_mb'     => intdiv($total, 1024),
            'available_mb' => intdiv($free, 1024),
            'used_pct'     => round(100 * ($total - $free) / $total, 1),
        ];
    }

    // Ejecuta un script de PowerShell y devuelve sus datos como array
    private function readWindows(): ?array
    {
        // El script es fijo: nunca se arma con datos que vengan del usuario.
        $script = <<<'PS'
            $os  = Get-CimInstance Win32_OperatingSystem
            $cpu = (Get-CimInstance Win32_Processor |
                Measure-Object -Property LoadPercentage -Average).Average
            $temp = $null
            try {
                $ns  = 'root/wmi'
                $cls = 'MSAcpi_ThermalZoneTemperature'
                $t = Get-CimInstance -Namespace $ns -ClassName $cls -ErrorAction Stop |
                    Select-Object -First 1
                if ($t) { $temp = [math]::Round($t.CurrentTemperature / 10 - 273.15, 1) }
            } catch {}
            [pscustomobject]@{
                mem_total_kb = [int64]$os.TotalVisibleMemorySize
                mem_free_kb  = [int64]$os.FreePhysicalMemory
                cpu_pct      = $cpu
                uptime_s     = [int64]((Get-Date) - $os.LastBootUpTime).TotalSeconds
                temp_c       = $temp
            } | ConvertTo-Json -Compress
            PS;

        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $output  = shell_exec("powershell -NoProfile -NonInteractive -EncodedCommand $encoded");

        if (!$output) {
            return null;
        }

        $datos = json_decode(trim($output), true);

        return is_array($datos) ? $datos : null;
    }

    // Desafio opcional: lee los mismos datos desde /proc y /sys en Linux
    private function readLinux(): ?array
    {
        $datos = [];

        // Memoria (kB): MemTotal y MemAvailable
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo !== false) {
            preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $total);
            preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $libre);
            if ($total && $libre) {
                $datos['mem_total_kb'] = (int) $total[1];
                $datos['mem_free_kb']  = (int) $libre[1];
            }
        }

        // Tiempo encendido: primer numero de /proc/uptime
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime !== false) {
            $datos['uptime_s'] = (int) explode(' ', trim($uptime))[0];
        }

        // Uso de CPU: dos lecturas de /proc/stat separadas 200 ms
        $a = $this->cpuStat();
        usleep(200000);
        $b = $this->cpuStat();
        if ($a && $b && $b['total'] > $a['total']) {
            $ocupado = ($b['total'] - $b['idle']) - ($a['total'] - $a['idle']);
            $datos['cpu_pct'] = round(100 * $ocupado / ($b['total'] - $a['total']), 1);
        }

        $temp = $this->linuxTemp();
        if ($temp !== null) {
            $datos['temp_c'] = $temp;
        }

        return $datos ?: null;
    }

    // Temperatura de la CPU en °C. Los archivos la informan en miligrados.
    private function linuxTemp(): ?float
    {
        // 1. Zona térmica ACPI (muchas notebooks y placas Intel)
        $temp = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
        if ($temp !== false && is_numeric(trim($temp))) {
            return round((int) trim($temp) / 1000, 1);
        }

        // 2. Sensores hwmon del procesador: k10temp/zenpower (AMD) o coretemp (Intel)
        foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $dir) {
            $nombre = trim((string) @file_get_contents("$dir/name"));
            if (! in_array($nombre, ['k10temp', 'zenpower', 'coretemp'], true)) {
                continue;
            }

            $temp = @file_get_contents("$dir/temp1_input");
            if ($temp !== false && is_numeric(trim($temp))) {
                return round((int) trim($temp) / 1000, 1);
            }
        }

        return null;
    }

    private function cpuStat(): ?array
    {
        $linea = @file('/proc/stat')[0] ?? null;
        if (!$linea || !str_starts_with($linea, 'cpu ')) {
            return null;
        }

        $v = array_map('intval', preg_split('/\s+/', trim($linea)));
        array_shift($v); // quita la palabra "cpu"

        // idle + iowait cuentan como tiempo libre
        return ['total' => array_sum($v), 'idle' => $v[3] + ($v[4] ?? 0)];
    }
}
