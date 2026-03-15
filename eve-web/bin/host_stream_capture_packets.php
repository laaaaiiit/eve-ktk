#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', ['if:', 'limit::', 'host::', 'proto::']);
$iface = strtolower(trim((string) ($options['if'] ?? '')));
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;
$host = trim((string) ($options['host'] ?? ''));
$proto = strtolower(trim((string) ($options['proto'] ?? '')));

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
if (function_exists('ob_implicit_flush')) {
	@ob_implicit_flush(true);
}
while (ob_get_level() > 0) {
	@ob_end_flush();
}

function captureStreamFlush(): void
{
	@ob_flush();
	@flush();
}

function captureStreamEmitPadding(int $bytes = 4096): void
{
	if ($bytes < 1) {
		return;
	}
	echo str_repeat(' ', $bytes) . PHP_EOL;
	captureStreamFlush();
}

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

$candidates = ['/usr/bin/tshark', '/usr/sbin/tshark', '/bin/tshark'];
$tshark = '';
foreach ($candidates as $candidate) {
	if (is_file($candidate) && is_executable($candidate)) {
		$tshark = $candidate;
		break;
	}
}
if ($tshark === '') {
	fwrite(STDERR, "tshark not found\n");
	exit(4);
}

$cmd = [
	$tshark,
	'-l',
	'-n',
	'-Q',
	'-i',
	$iface,
	'-T',
	'fields',
	'-E',
	'separator=/t',
	'-E',
	'quote=n',
	'-E',
	'header=n',
	'-E',
	'occurrence=f',
	'-e',
	'frame.number',
	'-e',
	'frame.time_epoch',
	'-e',
	'frame.time_relative',
	'-e',
	'ip.src',
	'-e',
	'ipv6.src',
	'-e',
	'arp.src.proto_ipv4',
	'-e',
	'eth.src',
	'-e',
	'ip.dst',
	'-e',
	'ipv6.dst',
	'-e',
	'arp.dst.proto_ipv4',
	'-e',
	'eth.dst',
	'-e',
	'_ws.col.Protocol',
	'-e',
	'frame.len',
	'-e',
	'_ws.col.Info',
	'-e',
	'frame.protocols',
	'-e',
	'tcp.srcport',
	'-e',
	'tcp.dstport',
	'-e',
	'udp.srcport',
	'-e',
	'udp.dstport',
	'-e',
	'icmp.type',
	'-e',
	'icmp.code',
	'-e',
	'icmpv6.type',
	'-e',
	'icmpv6.code',
	'-e',
	'arp.opcode',
	'-e',
	'data.data',
	'-e',
	'tcp.payload',
	'-e',
	'udp.payload',
];

if ($limit > 0) {
	$cmd[] = '-c';
	$cmd[] = (string) $limit;
}

$captureFilter = [];
if ($host !== '') {
	$captureFilter[] = 'host ' . $host;
}
if ($proto !== '' && $proto !== 'all') {
	$captureFilter[] = $proto;
}
if (!empty($captureFilter)) {
	$cmd[] = '-f';
	$cmd[] = implode(' and ', $captureFilter);
}

$descriptors = [
	0 => ['file', '/dev/null', 'r'],
	1 => ['pipe', 'w'],
	2 => ['pipe', 'w'],
];

$proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($proc)) {
	fwrite(STDERR, "failed to start tshark\n");
	exit(5);
}

@stream_set_blocking($pipes[1], false);
@stream_set_blocking($pipes[2], false);

/**
 * @param string $line
 * @param string $fallback
 * @return string
 */
function pickEndpoint(string $line, string $fallback = ''): string
{
	$line = trim($line);
	if ($line !== '') {
		return $line;
	}
	return $fallback;
}

/**
 * @param string $line
 * @param int $fieldCount
 * @return array<int, string>
 */
function splitFields(string $line, int $fieldCount): array
{
	$parts = explode("\t", rtrim($line, "\r\n"));
	$count = count($parts);
	if ($count < $fieldCount) {
		for ($i = $count; $i < $fieldCount; $i++) {
			$parts[$i] = '';
		}
	}
	return $parts;
}

/**
 * @param array<int, string> $fields
 * @param int $packetSeq
 * @return array<string, int|string>
 */
function buildPacket(array $fields, int $packetSeq): array
{
	$protocols = trim((string) $fields[14]);
	$protoLabel = trim((string) $fields[11]);
	if ($protoLabel === '' && $protocols !== '') {
		$segments = explode(':', $protocols);
		$protoLabel = strtoupper((string) end($segments));
	}
	$src = pickEndpoint((string) $fields[3]);
	if ($src === '') {
		$src = pickEndpoint((string) $fields[4]);
	}
	if ($src === '') {
		$src = pickEndpoint((string) $fields[5]);
	}
	if ($src === '') {
		$src = pickEndpoint((string) $fields[6]);
	}
	$dst = pickEndpoint((string) $fields[7]);
	if ($dst === '') {
		$dst = pickEndpoint((string) $fields[8]);
	}
	if ($dst === '') {
		$dst = pickEndpoint((string) $fields[9]);
	}
	if ($dst === '') {
		$dst = pickEndpoint((string) $fields[10]);
	}

	return [
		'type' => 'packet',
		'seq' => $packetSeq,
		'no' => (string) $fields[0] !== '' ? (int) $fields[0] : $packetSeq,
		'time_epoch' => (string) $fields[1],
		'time_relative' => (string) $fields[2],
		'src' => $src,
		'dst' => $dst,
		'proto' => $protoLabel !== '' ? $protoLabel : '?',
		'len' => is_numeric((string) $fields[12]) ? (int) $fields[12] : 0,
		'info' => (string) $fields[13],
		'protocols' => $protocols,
		'tcp_src_port' => (string) $fields[15],
		'tcp_dst_port' => (string) $fields[16],
		'udp_src_port' => (string) $fields[17],
		'udp_dst_port' => (string) $fields[18],
		'icmp_type' => (string) $fields[19],
		'icmp_code' => (string) $fields[20],
		'icmpv6_type' => (string) $fields[21],
		'icmpv6_code' => (string) $fields[22],
		'arp_opcode' => (string) $fields[23],
		'data_hex' => (string) ($fields[24] !== '' ? $fields[24] : ($fields[25] !== '' ? $fields[25] : $fields[26])),
	];
}

$startup = [
	'type' => 'status',
	'status' => 'started',
	'timestamp' => gmdate('c'),
	'iface' => $iface,
];
echo json_encode($startup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
captureStreamFlush();
// Force first chunk through proxy/FastCGI buffers even when capture is idle.
captureStreamEmitPadding(4096);

$outOpen = true;
$errOpen = true;
$packetSeq = 0;
$lastActivityTs = microtime(true);
$heartbeatIntervalSec = 3.0;
$fieldCount = 27;
$stdoutBuffer = '';
$stderrBuffer = '';

$emitPacketLine = function (string $line) use (&$packetSeq, $fieldCount): void {
	$line = trim($line);
	if ($line === '') {
		return;
	}
	$fields = splitFields($line, $fieldCount);
	$packetSeq++;
	$packet = buildPacket($fields, $packetSeq);
	echo json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
};

$emitStatusLine = function (string $line): void {
	$line = trim($line);
	if ($line === '') {
		return;
	}
	if (stripos($line, 'Running as user') === 0) {
		return;
	}
	if (stripos($line, 'Capturing on') === 0) {
		return;
	}
	if (stripos($line, 'Capture session') !== false) {
		return;
	}
	if (stripos($line, 'Error in input stream') !== false) {
		return;
	}
	$event = [
		'type' => 'status',
		'status' => 'stderr',
		'message' => $line,
	];
	echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
};

while ($outOpen || $errOpen) {
	$status = @proc_get_status($proc);
	if (!is_array($status) || empty($status['running'])) {
		if ($outOpen) {
			$chunkOut = (string) @stream_get_contents($pipes[1]);
			if ($chunkOut !== '') {
				$stdoutBuffer .= $chunkOut;
			}
		}
		if ($errOpen) {
			$chunkErr = (string) @stream_get_contents($pipes[2]);
			if ($chunkErr !== '') {
				$stderrBuffer .= $chunkErr;
			}
		}
		$outOpen = false;
		$errOpen = false;
	} else {
		$read = [];
		if ($outOpen) {
			$read[] = $pipes[1];
		}
		if ($errOpen) {
			$read[] = $pipes[2];
		}
		if (!empty($read)) {
			$write = null;
			$except = null;
			$ready = @stream_select($read, $write, $except, 0, 250000);
			if ($ready === false) {
				usleep(250000);
				continue;
			}
			if ($ready === 0) {
				$nowTs = microtime(true);
				if (($nowTs - $lastActivityTs) >= $heartbeatIntervalSec) {
					$heartbeat = [
						'type' => 'heartbeat',
						'timestamp' => gmdate('c'),
					];
					echo json_encode($heartbeat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
					// Keep long-lived stream active through upstream idle timeouts.
					captureStreamEmitPadding(4096);
					$lastActivityTs = $nowTs;
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
				$lastActivityTs = microtime(true);
				if ($stream === $pipes[1]) {
					$stdoutBuffer .= $chunk;
				} else {
					$stderrBuffer .= $chunk;
				}
			}
		}
	}

	$nlPos = strpos($stdoutBuffer, "\n");
	while ($nlPos !== false) {
		$line = substr($stdoutBuffer, 0, $nlPos);
		$stdoutBuffer = substr($stdoutBuffer, $nlPos + 1);
		$emitPacketLine($line);
		$nlPos = strpos($stdoutBuffer, "\n");
	}

	$errNlPos = strpos($stderrBuffer, "\n");
	while ($errNlPos !== false) {
		$line = substr($stderrBuffer, 0, $errNlPos);
		$stderrBuffer = substr($stderrBuffer, $errNlPos + 1);
		$emitStatusLine($line);
		$errNlPos = strpos($stderrBuffer, "\n");
	}

	captureStreamFlush();

	if (!$outOpen && !$errOpen) {
		break;
	}
}

if (trim($stdoutBuffer) !== '') {
	$emitPacketLine($stdoutBuffer);
}
if (trim($stderrBuffer) !== '') {
	$emitStatusLine($stderrBuffer);
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

$shutdown = [
	'type' => 'status',
	'status' => 'stopped',
	'timestamp' => gmdate('c'),
	'rc' => $rc,
	'packets' => $packetSeq,
];
echo json_encode($shutdown, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
captureStreamFlush();
exit($rc);
