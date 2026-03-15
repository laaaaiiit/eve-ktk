#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', ['if:', 'limit::', 'host::', 'proto::', 'quiet::']);
$iface = strtolower(trim((string) ($options['if'] ?? '')));
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;
$host = trim((string) ($options['host'] ?? ''));
$proto = strtolower(trim((string) ($options['proto'] ?? '')));
$quiet = !isset($options['quiet']) || (string) $options['quiet'] !== '0';
if ($limit < 0) {
    $limit = 0;
}
if ($limit > 500000) {
    $limit = 500000;
}

if ($iface === '' || preg_match('/^[a-z0-9_.:-]{1,32}$/', $iface) !== 1) {
    fwrite(STDERR, "invalid interface\n");
    exit(2);
}

if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
    fwrite(STDERR, "invalid host filter\n");
    exit(2);
}

$allowedProto = ['all', 'arp', 'ip', 'ip6', 'icmp', 'icmp6', 'tcp', 'udp', 'stp', 'lldp', 'cdp'];
if ($proto !== '' && !in_array($proto, $allowedProto, true)) {
    fwrite(STDERR, "invalid proto filter\n");
    exit(2);
}

$ifacePath = '/sys/class/net/' . $iface;
if (!is_dir($ifacePath)) {
    fwrite(STDERR, "interface not found\n");
    exit(3);
}

$candidates = ['/usr/sbin/tcpdump', '/usr/bin/tcpdump', '/sbin/tcpdump'];
$tcpdump = '';
foreach ($candidates as $candidate) {
    if (is_file($candidate) && is_executable($candidate)) {
        $tcpdump = $candidate;
        break;
    }
}
if ($tcpdump === '') {
    fwrite(STDERR, "tcpdump not found\n");
    exit(4);
}

$cmd = [$tcpdump, '-l', '-U', '-n', '-tttt', '-i', $iface];
if ($quiet) {
    $cmd[] = '-q';
}
if ($limit > 0) {
    $cmd[] = '-c';
    $cmd[] = (string) $limit;
}
if ($host !== '' || ($proto !== '' && $proto !== 'all')) {
    $filters = [];
    if ($host !== '') {
        $filters[] = 'host ' . $host;
    }
    if ($proto !== '' && $proto !== 'all') {
        $filters[] = $proto;
    }
    $cmd[] = implode(' and ', $filters);
}

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($proc)) {
    fwrite(STDERR, "failed to start tcpdump\n");
    exit(5);
}

@stream_set_blocking($pipes[1], false);
@stream_set_blocking($pipes[2], false);

$started = gmdate('c');
echo '[capture] started ' . $started . ' iface=' . $iface . PHP_EOL;
@ob_flush();
@flush();

$outOpen = true;
$errOpen = true;
$lastHeartbeatTs = microtime(true);
$heartbeatIntervalSec = 10.0;
while ($outOpen || $errOpen) {
    $status = @proc_get_status($proc);
    if (!is_array($status) || empty($status['running'])) {
        $chunkOut = $outOpen ? @stream_get_contents($pipes[1]) : '';
        $chunkErr = $errOpen ? @stream_get_contents($pipes[2]) : '';
        if (is_string($chunkOut) && $chunkOut !== '') {
            echo $chunkOut;
        }
        if (is_string($chunkErr) && $chunkErr !== '') {
            echo $chunkErr;
        }
        break;
    }

    $read = [];
    if ($outOpen) {
        $read[] = $pipes[1];
    }
    if ($errOpen) {
        $read[] = $pipes[2];
    }
    if (empty($read)) {
        break;
    }
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 0, 250000);
    if ($ready === false) {
        usleep(250000);
        continue;
    }
    if ($ready === 0) {
        $nowTs = microtime(true);
        if (($nowTs - $lastHeartbeatTs) >= $heartbeatIntervalSec) {
            echo "[capture] keepalive " . gmdate('c') . PHP_EOL;
            @ob_flush();
            @flush();
            $lastHeartbeatTs = $nowTs;
        }
        continue;
    }

    foreach ($read as $stream) {
        $chunk = @fread($stream, 65536);
        if ($chunk === '' || $chunk === false) {
            if (@feof($stream)) {
                if ($stream === $pipes[1]) {
                    $outOpen = false;
                } elseif ($stream === $pipes[2]) {
                    $errOpen = false;
                }
            }
            continue;
        }
        echo $chunk;
        $lastHeartbeatTs = microtime(true);
    }
    @ob_flush();
    @flush();
}

foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        @fclose($pipe);
    }
}
$rc = @proc_close($proc);
if (!is_int($rc)) {
    $rc = 0;
}

echo PHP_EOL . '[capture] stopped rc=' . $rc . PHP_EOL;
@ob_flush();
@flush();
exit($rc);
