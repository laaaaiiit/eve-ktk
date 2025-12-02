#!/usr/bin/env php
<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

require_once('/opt/unetlab/html/includes/init.php');
require_once(BASE_DIR . '/html/includes/api_nodes.php');

$options = getopt('', array('once', 'sleep::'));
$loop = !isset($options['once']);
$sleepInterval = isset($options['sleep']) ? max(1, (int) $options['sleep']) : 2;

$db = checkDatabase();
if ($db === False) {
	fwrite(STDERR, "Unable to connect to database.\n");
	exit(1);
}

do {
	$job = claimPendingJob($db);
	if ($job === null) {
		if ($loop) {
			sleep($sleepInterval);
			continue;
		}
		break;
	}
	processJob($db, $job);
	if (!$loop) {
		break;
	}
} while (true);

function processJob($db, $job)
{
	$payload = isset($job['payload']) ? $job['payload'] : array();
	$result = array(
		'code' => 500,
		'status' => 'fail',
		'message' => 'Unhandled job.'
	);

	try {
		switch ($job['action']) {
			case 'nodes_batch':
				$result = runNodeBatchJob($db, $job, $payload);
				break;
			default:
				throw new Exception('Unsupported job action: ' . $job['action']);
		}
		updateJobStatus($db, $job['id'], 'success', $result);
	} catch (Exception $e) {
		$errorPayload = array(
			'code' => 500,
			'status' => 'fail',
			'message' => $e->getMessage()
		);
		updateJobStatus($db, $job['id'], 'failed', $errorPayload);
		error_log(date('M d H:i:s ') . 'ERROR: job #' . $job['id'] . ' failed - ' . $e->getMessage());
	}
}

function runNodeBatchJob($db, $job, $payload)
{
	if (empty($payload['ids']) || empty($payload['operation'])) {
		throw new Exception('Invalid batch payload.');
	}
	$labRelative = $job['lab_path'];
	$labFileFull = BASE_LAB . $labRelative;
	if (!is_file($labFileFull)) {
		throw new Exception('Lab file missing: ' . $labRelative);
	}

	if (!lockFile($labFileFull)) {
		throw new Exception('Unable to lock lab file for job #' . $job['id']);
	}

	try {
		$lab = new Lab($labFileFull, $job['tenant'], $job['username']);
		$userContext = array(
			'username' => $job['username'],
			'tenant' => $job['tenant'],
			'role' => $job['user_role']
		);
		$ids = isset($payload['ids']) ? $payload['ids'] : array();
		$total = count($ids);
		$progressUpdater = function ($processed, $count) use ($db, $job, $total) {
			$denominator = $count > 0 ? $count : ($total > 0 ? $total : 1);
			$percent = (int) floor(($processed / $denominator) * 100);
			updateJobProgress($db, $job['id'], $percent);
		};
		$result = apiBatchNodeAction(
			$lab,
			$ids,
			$payload['operation'],
			$job['tenant'],
			$job['username'],
			$userContext,
			$progressUpdater
		);
	} finally {
		unlockFile($labFileFull);
	}

	return $result;
}
