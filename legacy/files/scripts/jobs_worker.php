#!/usr/bin/env php
<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

require_once('/opt/unetlab/html/includes/init.php');
require_once(BASE_DIR . '/html/includes/api_nodes.php');
require_once(BASE_DIR . '/html/includes/api_networks.php');
require_once(BASE_DIR . '/html/includes/api_labs.php');

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
			case 'lab_operation':
				$result = runLabOperationJob($db, $job, $payload);
				break;
			case 'work_copy':
				$result = runWorkCopyJob($db, $job, $payload);
				break;
			default:
				throw new Exception('Unsupported job action: ' . $job['action']);
		}
		$finalStatus = isset($result['final_status']) ? $result['final_status'] : 'success';
		updateJobStatus($db, $job['id'], $finalStatus, $result);
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

function runWorkCopyJob($db, $job, $payload)
{
	$action = isset($payload['action']) ? strtolower($payload['action']) : 'start';
	if (!in_array($action, array('start', 'reset'))) {
		$action = 'start';
	}
	$relativePath = isset($payload['relative_path']) ? $payload['relative_path'] : '';
	if ($relativePath === '') {
		$relativePath = $job['lab_path'];
	}
	$labFileFull = BASE_LAB . $job['lab_path'];
	if (!is_file($labFileFull)) {
		throw new Exception('Lab file missing: ' . $job['lab_path']);
	}

	$userContext = array(
		'username' => $job['username'],
		'tenant' => $job['tenant'],
		'role' => $job['user_role']
	);
	$progressCallback = function ($percent) use ($db, $job) {
		updateJobProgress($db, $job['id'], $percent);
	};

	$result = apiCreateWorkLab($db, $userContext, $labFileFull, $relativePath, $action, $progressCallback);
	if (!isset($result['final_status'])) {
		$result['final_status'] = isset($result['status']) && $result['status'] === 'success' ? 'success' : 'failed';
	}
	return $result;
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
		$cancelCallback = function () use ($db, $job) {
			return jobCancellationRequested($db, $job['id']);
		};
		$maxParallel = getMaxParallelNodesLimit($db);
		$runner = isset($payload['runner']) && $payload['runner'] ? $payload['runner'] : $job['username'];
		$result = apiBatchNodeAction(
			$lab,
			$ids,
			$payload['operation'],
			$job['tenant'],
			$job['username'],
			$userContext,
			$progressUpdater,
			$cancelCallback,
			array('max_parallel' => $maxParallel, 'runner' => $runner)
		);
	} finally {
		unlockFile($labFileFull);
	}

	if (!isset($result['final_status'])) {
		$result['final_status'] = isset($result['status']) ? $result['status'] : 'success';
	}

	return $result;
}

function runLabOperationJob($db, $job, $payload)
{
	$operation = isset($payload['operation']) ? strtolower($payload['operation']) : '';
	if ($operation === '') {
		throw new Exception('Missing lab operation type.');
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
		switch ($operation) {
			case 'add_nodes':
				$result = executeAddNodesOperation($lab, $payload);
				break;
			case 'connect_node_network':
				$result = executeNodeToNetworkOperation($lab, $payload);
				break;
			case 'connect_nodes_bridge':
				$result = executeNodesBridgeOperation($lab, $payload);
				break;
			case 'connect_nodes_serial':
				$result = executeNodesSerialOperation($lab, $payload);
				break;
			default:
				throw new Exception('Unsupported lab operation: ' . $operation);
		}

		if (isset($result['status']) && $result['status'] === 'success') {
			$userContext = array(
				'username' => $job['username'],
				'tenant' => $job['tenant'],
				'role' => $job['user_role']
			);
			refreshSharedLabCopies($lab, $db, $userContext);
		}
	} finally {
		unlockFile($labFileFull);
	}

	if (!isset($result['final_status'])) {
		$result['final_status'] = isset($result['status']) ? $result['status'] : 'success';
	}

	return $result;
}

function executeAddNodesOperation($lab, $payload)
{
	if (!isset($payload['node']) || !is_array($payload['node'])) {
		return array(
			'code' => 400,
			'status' => 'fail',
			'message' => 'Node payload missing for add_nodes operation.'
		);
	}
	$postfix = !empty($payload['postfix']);
	return apiAddLabNode($lab, $payload['node'], $postfix);
}

function executeNodeToNetworkOperation($lab, $payload)
{
	$nodeId = isset($payload['node_id']) ? (int)$payload['node_id'] : 0;
	$interfaceId = isset($payload['interface_id']) ? (string)$payload['interface_id'] : '';
	$networkId = isset($payload['network_id']) ? (int)$payload['network_id'] : 0;
	if ($nodeId <= 0 || $networkId <= 0 || $interfaceId === '') {
		return array(
			'code' => 400,
			'status' => 'fail',
			'message' => 'Invalid parameters for connect_node_network operation.'
		);
	}
	$map = array(
		$interfaceId => (string)$networkId
	);
	return apiEditLabNodeInterfaces($lab, $nodeId, $map);
}

function executeNodesBridgeOperation($lab, $payload)
{
	$srcNodeId = isset($payload['src_node_id']) ? (int)$payload['src_node_id'] : 0;
	$dstNodeId = isset($payload['dst_node_id']) ? (int)$payload['dst_node_id'] : 0;
	$srcIf = isset($payload['src_interface_id']) ? (string)$payload['src_interface_id'] : '';
	$dstIf = isset($payload['dst_interface_id']) ? (string)$payload['dst_interface_id'] : '';
	$network = isset($payload['network']) && is_array($payload['network']) ? $payload['network'] : array();

	if ($srcNodeId <= 0 || $dstNodeId <= 0 || $srcIf === '' || $dstIf === '') {
		return array(
			'code' => 400,
			'status' => 'fail',
			'message' => 'Invalid parameters for connect_nodes_bridge operation.'
		);
	}

	if (!isset($network['type']) || $network['type'] === '') {
		$network['type'] = 'bridge';
	}
	if (!isset($network['count'])) {
		$network['count'] = 1;
	}
	if (!isset($network['postfix'])) {
		$network['postfix'] = 0;
	}

	$networkResult = apiAddLabNetwork($lab, $network, false);
	if (!isset($networkResult['status']) || $networkResult['status'] !== 'success') {
		return $networkResult;
	}

	$networkId = isset($networkResult['data']['id']) ? (int)$networkResult['data']['id'] : 0;
	if ($networkId <= 0) {
		return array(
			'code' => 500,
			'status' => 'fail',
			'message' => 'Unable to determine new network identifier.'
		);
	}

	$srcResult = apiEditLabNodeInterfaces($lab, $srcNodeId, array(
		$srcIf => (string)$networkId
	));
	if (!isset($srcResult['status']) || $srcResult['status'] !== 'success') {
		return $srcResult;
	}

	$dstResult = apiEditLabNodeInterfaces($lab, $dstNodeId, array(
		$dstIf => (string)$networkId
	));
	if (!isset($dstResult['status']) || $dstResult['status'] !== 'success') {
		return $dstResult;
	}

	return array(
		'code' => 200,
		'status' => 'success',
		'message' => 'Nodes connected via network.',
		'data' => array(
			'network_id' => $networkId
		)
	);
}

function executeNodesSerialOperation($lab, $payload)
{
	$srcNodeId = isset($payload['src_node_id']) ? (int)$payload['src_node_id'] : 0;
	$dstNodeId = isset($payload['dst_node_id']) ? (int)$payload['dst_node_id'] : 0;
	$srcIf = isset($payload['src_interface_id']) ? (string)$payload['src_interface_id'] : '';
	$dstIf = isset($payload['dst_interface_id']) ? (string)$payload['dst_interface_id'] : '';

	if ($srcNodeId <= 0 || $dstNodeId <= 0 || $srcIf === '' || $dstIf === '') {
		return array(
			'code' => 400,
			'status' => 'fail',
			'message' => 'Invalid parameters for connect_nodes_serial operation.'
		);
	}

	$map = array(
		$srcIf => $dstNodeId . ':' . $dstIf
	);
	return apiEditLabNodeInterfaces($lab, $srcNodeId, $map);
}
