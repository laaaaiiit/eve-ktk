<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/includes/api_nodes.php
 *
 * Nodes related functions for REST APIs.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @copyright fork 2025 Nikita Hochckov https://github.com/laaaaiiit
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

/**
 * Function to add a node to a lab.
 *
 * @param   Lab     $lab                Lab
 * @param   Array   $p                  Parameters
 * @param   bool    $o                  True if need to add ID to name
 * @return  Array                       Return code (JSend data)
 */
function apiAddLabNode(Lab $lab, $p, $o)
{
	if (isset($p['numberNodes']))
		$numberNodes = $p['numberNodes'];

	$default_name = $p['name'];
	if ($default_name == "R")
		$o = True;

	$ids = array();
	$no_array = false;
	$initLeft = $p['left'];
	$initTop = $p['top'];
	if (!isset($numberNodes)) {
		$numberNodes = 1;
		$no_array = true;
	}
	for ($i = 1; $i <= $numberNodes; $i++) {
		if ($i > 1) {
			$p['left'] =  $initLeft + (($i - 1) % 5)   * 60;
			$p['top'] =  $initTop + (intval(($i - 1) / 5)  * 80);
		}
		$id = $lab->getFreeNodeId($lab->getAuthor(), $lab->getTenant());
		if ($id > 255) {
			$rc = 20046;
			break;
		}
		// Adding node_id to node_name if required
		if ($o == True && $default_name || $numberNodes > 1) $p['name'] = $default_name . $lab->getFreeNodeId($lab->getAuthor(), $lab->getTenant());

		// Adding the node
		$rc = $lab->addNode($p);
		$ids[] = $id;
	}
	$lab->save();
	if ($rc === 0) {
		$output['code'] = 201;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60023];
		$output['data'] = array(
			'id' => ($no_array ? $id : $ids)
		);
	} else if ($rc = 20046) {
		$output['code'] = 201;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][$rc];
		$output['data'] = array(
			'id' => ($no_array ? $id : $ids)
		);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to delete a lab node.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @return  Array                       Return code (JSend data)
 */
function apiDeleteLabNode($lab, $id, $user)
{
	$html5_db = html5_checkDatabase();
	$nodes = $lab->getNodes();
	foreach ($nodes as $node) {
		if ($node->getId() == $id) {
			$name = $node->getName() . '_' . $node->getId() . '_' . $lab->getAuthor();
			error_log($name);
			$runner = $lab->getAuthor();
			if (empty($runner)) {
				$runner = $user['username'];
			}
			apiStopLabNode($lab, $id, $lab->getTenant(), $runner);
			html5DeleteSession($html5_db, $name);
		}
	}
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a delete -U ' . $lab->getAuthor() . ' -T '. $lab->getTenant() .' -D ' . $id . ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	error_log($cmd);

	exec($cmd, $o, $rc);
	$rc = $lab->deleteNode($id);
	if ($rc === 0) {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60023];
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to edit a lab node.
 *
 * @param   Lab     $lab                Lab
 * @param   Array   $p                  Parameters
 * @return  Array                       Return code (JSend data)
 */
function apiEditLabNode($lab, $p)
{
	// Edit node
	$rc = $lab->editNode($p);

	if ($rc === 0) {
		$output['code'] = 201;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60023];
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}
/**
 * Function to edit multiple lab node.
 *
 * @param   Lab     $lab                Lab
 * @param   Array   $p                  Parameters
 * @return  Array                       Return code (JSend data)
 */
function apiEditLabNodes($lab, $p)
{
	// Edit node
	//$rc=$lab->editNode
	foreach ($p as $node) {
		$node['save'] = 0;
		$rc = $lab->editNode($node);
	}
	$rc = $lab->save();
	if ($rc === 0) {
		$output['code'] = 201;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60023];
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}
/**
 * Function to export a single node.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @param   int     $tenant             Tenant ID
 * @return  Array                       Return code (JSend data)
 */
function apiExportLabNode($lab, $id, $tenant, $username)
{
	error_log("apiExportLabNode");
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a export';
	$cmd .= ' -T ' . $tenant;
	$cmd .= ' -U ' . $username;
	$cmd .= ' -D ' . $id;
	$cmd .= ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	error_log($cmd);

	exec($cmd, $o, $rc);
	if ($rc == 0) {
		// Config exported
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80058];
	} else {
		// Failed to export
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to export all nodes.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $tenant             Tenant ID
 * @return  Array                       Return code (JSend data)
 */
function apiExportLabNodes($lab, $tenant, $username)
{
	error_log("apiExportLabNodes");
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a export';
	$cmd .= ' -T ' . $tenant;
	$cmd .= ' -U ' . $username;
	$cmd .= ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	error_log($cmd);

	exec($cmd, $o, $rc);
	if ($rc == 0) {
		// Nodes started
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80057];
	} else {
		// Failed to start
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/*
 * Function to get a single lab node.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @param   Array   $p                  Parameters
 * @return  Array                       Lab node (JSend data)
 */
function apiEditLabNodeInterfaces($lab, $id, $p)
{
	if (!isset($lab->getNodes()[$id])) {
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][20032];
		return $output;
	}

	$node = $lab->getNodes()[$id];
	$old_ethernets = array();
	if (is_array($p)) {
		foreach ($p as $interface_id => $interface_link) {
			if (isset($node->getEthernets()[$interface_id])) {
				$old_ethernets[$interface_id] = (int) $node->getEthernets()[$interface_id]->getNetworkId();
			}
		}
	}

	// Edit node interfaces
	$rc = $lab->connectNode($id, $p);

	if ($rc === 0) {
		$hotplug_rc = apiHotplugNodeInterfaces($lab, $id, $old_ethernets);
		if ($hotplug_rc === 0) {
			$output['code'] = 201;
			$output['status'] = 'success';
			$output['message'] = $GLOBALS['messages'][60023];
		} else {
			$output['code'] = 400;
			$output['status'] = 'fail';
			if (isset($GLOBALS['messages'][$hotplug_rc])) {
				$output['message'] = $GLOBALS['messages'][$hotplug_rc];
			} else {
				$output['message'] = $GLOBALS['messages'][80092];
			}
		}
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to rewire ethernet interfaces for running nodes without requiring a reboot.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @param   array   $old_ethernets      Map of ethernet_id => old network id
 * @return  int                         0 means ok
 */
function apiHotplugNodeInterfaces($lab, $id, $old_ethernets)
{
	if (empty($old_ethernets)) {
		return 0;
	}

	if (!isset($lab->getNodes()[$id])) {
		return 0;
	}

	$node = $lab->getNodes()[$id];
	if ($node->getStatus() < 2) {
		// Node is not running, wiring will be applied on next boot
		return 0;
	}

	$author = $lab->getAuthor();
	if ($author === '' || $author === null) {
		$author = 'admin';
	}
	$lab_file = $lab->getPath() . '/' . $lab->getFilename();
	$interfaces = $node->getEthernets();
	foreach ($old_ethernets as $interface_id => $old_network_id) {
		if (!isset($interfaces[$interface_id])) {
			continue;
		}
		$new_network_id = (int) $interfaces[$interface_id]->getNetworkId();
		if ($new_network_id == $old_network_id) {
			continue;
		}
		$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
		$cmd .= ' -a link';
		$cmd .= ' -T ' . (int) $lab->getTenant();
		$cmd .= ' -U ' . escapeshellarg($author);
		$cmd .= ' -D ' . (int) $id;
		$cmd .= ' -F ' . escapeshellarg($lab_file);
		$cmd .= ' -i ' . (int) $interface_id;
		if ($old_network_id > 0) {
			$cmd .= ' -b ' . (int) $old_network_id;
		}
		$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
		exec($cmd, $o, $wrapper_rc);
		if ($wrapper_rc !== 0) {
			return $wrapper_rc;
		}
	}
	return 0;
}

/**
 * Function to get a single lab node.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @return  Array                       Lab node (JSend data)
 */
function apiGetLabNode($lab, $id, $html5, $user)
{
	// Getting node
	if (isset($lab->getNodes()[$id])) {
		$node = $lab->getNodes()[$id];

		// Printing node
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60025];
		$output['data'] = array(
			'console' => $node->getConsole(),
			'config' => $node->getConfig(),
			'delay' => $node->getDelay(),
			'left' => $node->getLeft(),
			'icon' => $node->getIcon(),
			'image' => $node->getImage(),
			'name' => $node->getName(),
			'status' => $node->getStatus(),
			'template' => $node->getTemplate(),
			'type' => $node->getNType(),
			'top' => $node->getTop(),
			'url' => $node->getConsoleUrl($html5, $user, $lab->getAuthor())
		);

		if ($node->getNType() == 'iol') {
			$output['data']['ethernet'] = $node->getEthernetCount();
			$output['data']['nvram'] = $node->getNvram();
			$output['data']['ram'] = $node->getRam();
			$output['data']['serial'] = $node->getSerialCount();
		}

		if ($node->getNType() == 'dynamips') {
			$output['data']['idlepc'] = $node->getIdlePc();
			$output['data']['nvram'] = $node->getNvram();
			$output['data']['ram'] = $node->getRam();
			foreach ($node->getSlot() as $slot_id => $module) {
				$output['data']['slot' . $slot_id] = $module;
			}
		}

		if ($node->getNType() == 'qemu') {
			$output['data']['cpulimit'] = $node->getCpuLimit();
			$output['data']['cpu'] = $node->getCpu();
			$output['data']['ethernet'] = $node->getEthernetCount();
			$output['data']['ram'] = $node->getRam();
			$output['data']['uuid'] = $node->getUuid();
			if ($node->getTemplate() == "bigip" || $node->getTemplate() == "firepower6" || $node->getTemplate() == "firepower" || $node->getTemplate() == "linux") {
				$output['data']['firstmac'] = $node->getFirstMac();
			}
			if ($node->getTemplate() == "timos" || strtok($node->getTemplate(), "-") == "timos") {
				$output['data']['management_address'] = $node->getManagement_address();
				$output['data']['timos_line'] = $node->getTimos_Line();
				$output['data']['timos_license'] = $node->getLicense_File();
			}
			if ($node->getTemplate() == "timoscpm" || strtok($node->getTemplate(), "-") == "timoscpm") {
				$output['data']['management_address'] = $node->getManagement_address();
				$output['data']['timos_line'] = $node->getTimos_Line();
				$output['data']['timos_license'] = $node->getLicense_File();
			}
			if ($node->getTemplate() == "timosiom" || strtok($node->getTemplate(), "-") == "timosiom") {
				$output['data']['timos_line'] = $node->getTimos_Line();
			}
			$output['data']['qemu_options'] = $node->getQemu_options();
			$output['data']['qemu_version'] = $node->getQemu_version();
			$output['data']['qemu_arch'] = $node->getQemu_arch();
			$output['data']['qemu_nic'] = $node->getQemu_nic();
		}

		if ($node->getNType() == 'docker') {
			$output['data']['ethernet'] = $node->getEthernetCount();
			$output['data']['ram'] = $node->getRam();
		}
		if ($node->getNType() == 'vpcs') {
			$output['data']['ethernet'] = $node->getEthernetCount();
		}
	} else {
		// Node not found
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][20024];
	}
	return $output;
}

/**
 * Function to get all lab nodes.
 *
 * @param   Lab     $lab                Lab
 * @return  Array                       Lab nodes (JSend data)
 */
function apiGetLabNodes($lab, $html5, $user)
{
	// Getting node(s)
	$nodes = $lab->getNodes();

	// Printing nodes
	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][60026];
	$output['data'] = array();
	if (!empty($nodes)) {
		foreach ($nodes as $node_id => $node) {
			$output['data'][$node_id] = array(
				'console' => $node->getConsole(),
				'delay' => $node->getDelay(),
				'id' => $node_id,
				'left' => $node->getLeft(),
				'icon' => $node->getIcon(),
				'image' => $node->getImage(),
				'name' => $node->getName(),
				'ram' => $node->getRam(),
				'status' => $node->getStatus(),
				'template' => $node->getTemplate(),
				'type' => $node->getNType(),
				'top' => $node->getTop(),
				'url' => $node->getConsoleUrl($html5, $user, $lab->getAuthor()),
				'config_list' => listNodeConfigTemplates(),
				'config' => $node->getConfig()
			);

			if ($node->getNType() == 'iol') {
				$output['data'][$node_id]['ethernet'] = $node->getEthernetCount();
				$output['data'][$node_id]['nvram'] = $node->getNvram();
				$output['data'][$node_id]['ram'] = $node->getRam();
				$output['data'][$node_id]['serial'] = $node->getSerialCount();
			}

			if ($node->getNType() == 'dynamips') {
				$output['data'][$node_id]['idlepc'] = $node->getIdlePc();
				$output['data'][$node_id]['nvram'] = $node->getNvram();
				$output['data'][$node_id]['ram'] = $node->getRam();
				foreach ($node->getSlot() as $slot_id => $module) {
					$output['data'][$node_id]['slot' . $slot_id] = $module;
				}
			}

			if ($node->getNType() == 'qemu') {
				$output['data'][$node_id]['cpu'] = $node->getCpu();
				$output['data'][$node_id]['ethernet'] = $node->getEthernetCount();
				$output['data'][$node_id]['ram'] = $node->getRam();
				$output['data'][$node_id]['uuid'] = $node->getUuid();
				if ($node->getTemplate() == "bigip" || $node->getTemplate() == "firepower6" || $node->getTemplate() == "firepower" | $node->getTemplate() == "linux") {
					$output['data'][$node_id]['firstmac'] = $node->getFirstMac();
				}
			}

			if ($node->getNType() == 'docker') {
				$output['data'][$node_id]['ethernet'] = $node->getEthernetCount();
				$output['data'][$node_id]['ram'] = $node->getRam();
			}
			if ($node->getNType() == 'vpcs') {
				$output['data'][$node_id]['ethernet'] = $node->getEthernetCount();
			}
		}
	}
	return $output;
}

/**
 * Function to get all node interfaces.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @return  Array                       Node interfaces (JSend data)
 */
function apiGetLabNodeInterfaces($lab, $id)
{
	// Getting node
	if (isset($lab->getNodes()[$id])) {
		$node = $lab->getNodes()[$id];

		// Printing node
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60025];
		$output['data'] = array();
		// Addint node type to properly sort IOL interfaces
		$output['data']['id'] = (int)$id;
		$output['data']['sort'] = $lab->getNodes()[$id]->getNType();

		// Getting interfaces
		$ethernets = array();
		foreach ($lab->getNodes()[$id]->getEthernets() as $interface_id => $interface) {
			$ethernets[$interface_id] = array(
				'name' => $interface->getName(),
				'network_id' => $interface->getNetworkId()
			);
		}
		$serials = array();
		foreach ($lab->getNodes()[$id]->getSerials() as $interface_id => $interface) {
			$remoteId = $interface->getRemoteId();
			$remoteIf = $interface->getRemoteIf();
			$serials[$interface_id] = array(
				'name' => $interface->getName(),
				'remote_id' => $remoteId,
				'remote_if' => $remoteIf,
				'remote_if_name' => $remoteId ? $lab->getNodes()[$remoteId]->getSerials()[$remoteIf]->getName() : '',
			);
		}

		$output['data']['ethernet'] = $ethernets;
		$output['data']['serial'] = $serials;

		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60030];
	} else {
		// Node not found
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][20024];
	}
	return $output;
}

/**
 * Function to get node template.
 *
 * @param   Array   $p                  Parameters
 * @return  Array                       Node template (JSend data)
 */
function apiGetLabNodeTemplate($p)
{
	// Check mandatory parameters
	if (!isset($p['type']) || !isset($p['template'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60033];
		return $output;
	}

	// TODO must check lot of parameters
	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][60032];
	$output['data'] = array();
	$output['data']['options'] = array();

	// Name
	$output['data']['description'] = $GLOBALS['node_templates'][$p['template']];

	// Type
	$output['data']['type'] = $p['type'];

	// Image
	if ($p['type'] != 'vpcs') {
		$node_images = listNodeImages($p['type'], $p['template']);
		if (empty($node_images)) {
			$output['data']['options']['image'] = array(
				'name' => $GLOBALS['messages'][70002],
				'type' => 'list',
				'value' => '',
				'list' => array()
			);
		} else {
			$output['data']['options']['image'] = array(
				'name' => $GLOBALS['messages'][70002],
				'type' => 'list',
				'list' => $node_images
			);
			if (isset($p['image'])) {
				$output['data']['options']['image']['value'] =  $p['image'];
			} else {
				$output['data']['options']['image']['value'] =  end($node_images);
			}
		}
	}
	// Node Name/Prefix
	$output['data']['options']['name'] = array(
		'name' => $GLOBALS['messages'][70000],
		'type' => 'input',
		'value' => $p['name']
	);

	// Icon
	$output['data']['options']['icon'] = array(
		'name' => $GLOBALS['messages'][70001],
		'type' => 'list',
		'value' => $p['icon'],
		'list' => listNodeIcons()
	);

	// UUID
	if ($p['type'] == 'qemu') $output['data']['options']['uuid'] = array(
		'name' => $GLOBALS['messages'][70008],
		'type' => 'input',
		'value' => ''
	);
	// CPULimit
	if ($p['type'] == 'qemu') $output['data']['options']['cpulimit'] = array(
		'name' => $GLOBALS['messages'][70037],
		'type' => 'checkbox',
		'value' => $p['cpulimit']
	);
	// CPU
	if ($p['type'] == 'qemu') $output['data']['options']['cpu'] = array(
		'name' => $GLOBALS['messages'][70003],
		'type' => 'input',
		'value' => $p['cpu']
	);

	// Idle PC
	if ($p['type'] == 'dynamips') $output['data']['options']['idlepc'] = array(
		'name' => $GLOBALS['messages'][70009],
		'type' => 'input',
		'value' => $p['idlepc']
	);

	// NVRAM
	if (in_array($p['type'], array('dynamips', 'iol'))) $output['data']['options']['nvram'] = array(
		'name' => $GLOBALS['messages'][70010],
		'type' => 'input',
		'value' => $p['nvram']
	);

	// RAM
	if (in_array($p['type'], array('dynamips', 'iol', 'qemu', 'docker'))) $output['data']['options']['ram'] = array(
		'name' => $GLOBALS['messages'][70011],
		'type' => 'input',
		'value' => $p['ram']
	);

	// Slots
	if ($p['type'] == 'dynamips') {
		foreach ($p as $key => $module) {
			if (preg_match('/^slot[0-9]+$/', $key)) {
				// Found a slot
				$slot_id = substr($key, 4);
				$output['data']['options']['slot' . $slot_id] = array(
					'name' => $GLOBALS['messages'][70016] . ' ' . $slot_id,
					'type' => 'list',
					'value' => $p['slot' . $slot_id],
					'list' => $p['modules']
				);
			}
		}
	}

	// Ethernet
	if (in_array($p['type'], array('qemu', 'docker'))) $output['data']['options']['ethernet'] = array(
		'name' => $GLOBALS['messages'][70012],
		'type' => 'input',
		'value' => $p['ethernet']
	);
	if ($p['type'] == 'iol') $output['data']['options']['ethernet'] = array(
		'name' => $GLOBALS['messages'][70018],
		'type' => 'input',
		'value' => $p['ethernet']
	);

	// First Mac
	if ($p['template'] == "bigip" || $p['template'] == "firepower6" || $p['template'] == "firepower" || $p['template'] == "linux") $output['data']['options']['firstmac'] =  array(
		'name' => $GLOBALS['messages'][70021],
		'type' => 'input',
		'value' => (isset($p['firstmac']) ? $p['firstmac'] : "")
	);


	// Timos Options
	if ($p['template'] == "oldtimos") {
		$output['data']['options']['management_address'] =  array(
			'name' => $GLOBALS['messages'][70031],
			'type' => 'input',
			'value' => (isset($p['management_address']) ? $p['management_address'] : "")
		);
	};

	// Timos Options CPM
	if ($p['template'] == "timoscpm" || $p['template'] == "timos" || strtok($p['template'], "-") == "timos" || strtok($p['template'], "-") == "timoscpm") {
		$output['data']['options']['management_address'] =  array(
			'name' => $GLOBALS['messages'][70031],
			'type' => 'input',
			'value' => (isset($p['management_address']) ? $p['management_address'] : "")
		);

		$output['data']['options']['timos_line'] =  array(
			'name' => $GLOBALS['messages'][70032],
			'type' => 'input',
			'value' => (isset($p['timos_line']) ? $p['timos_line'] : "")
		);

		$output['data']['options']['timos_license'] =  array(
			'name' => $GLOBALS['messages'][70033],
			'type' => 'input',
			'value' => (isset($p['timos_license']) ? $p['timos_license'] : "")
		);
	};
	// Timos Options IOM
	if ($p['template'] == "timosiom" || strtok($p['template'], "-") == "timosiom") {
		$output['data']['options']['timos_line'] =  array(
			'name' => $GLOBALS['messages'][70032],
			'type' => 'input',
			'value' => (isset($p['timos_line']) ? $p['timos_line'] : "")
		);
	};
	// Qemu Options
	if ($p['type'] == "qemu") {

		$output['data']['options']['qemu_version'] =  array(
			'name' => $GLOBALS['messages'][70036],
			'type' => 'list',
			'value' => (isset($p['qemu_version']) ? $p['qemu_version'] : ""),
			'list'  => array(
				'1.3.1' => '1.3.1',
				'2.0.2' => '2.0.2',
				'2.2.0' => '2.2.0',
				'2.4.0' => '2.4.0',
				'2.5.0' => '2.5.0',
				'2.6.2' => '2.6.2',
				'2.12.0' => '2.12.0',
				'3.1.0' => '3.1.0',
				'4.1.0' => '4.1.0',
				'5.2.0' => '5.2.0',
				'6.0.0' => '6.0.0',
				'' => 'tpl' . (isset($p['qemu_version']) ? '(' . $p['qemu_version'] . ')' : "(default 2.4.0)")
			)
		);
		$output['data']['options']['qemu_arch'] =  array(
			'name' => $GLOBALS['messages'][70034],
			'type' => 'list',
			'value' => (isset($p['qemu_arch']) ? $p['qemu_arch'] : ""),
			'list'  => array('i386' => 'i386', 'x86_64' => 'x86_64', '' => 'tpl' . (isset($p['qemu_arch']) ? '(' . $p['qemu_arch'] . ')' : ""))
		);
		$output['data']['options']['qemu_nic'] =  array(
			'name' => $GLOBALS['messages'][70035],
			'type' => 'list',
			'value' => (isset($p['qemu_nic']) ? $p['qemu_nic'] : ""),
			'list' => array('virtio-net-pci' => 'virtio-net-pci', 'e1000' => 'e1000', 'i82559er' => 'i82559er', 'rtl8139' => 'rtl8139', 'e1000-82545em' => 'e1000-82545em', 'vmxnet3' => 'vmxnet3', '' => 'tpl' . (isset($p['qemu_nic']) ? '(' . $p['qemu_nic'] . ')' : "(e1000)"))
		);

		$output['data']['options']['qemu_options'] =  array(
			'name' => $GLOBALS['messages'][70030],
			'type' => 'input',
			'value' => (isset($p['qemu_options']) ? $p['qemu_options'] : "")
		);
	};
	// Serial
	if ($p['type'] == 'iol') $output['data']['options']['serial'] = array(
		'name' => $GLOBALS['messages'][70017],
		'type' => 'input',
		'value' => $p['serial']
	);

	// Startup configs
	if (in_array($p['type'], array('dynamips', 'iol', 'qemu', 'docker', 'vpcs'))) {
		$output['data']['options']['config'] = array(
			'name' => $GLOBALS['messages'][70013],
			'type' => 'list',
			'value' => '0',	// None
			'list' => listNodeConfigTemplates()
		);
		$output['data']['options']['config']['list'][0] = $GLOBALS['messages'][70020];	// None
		$output['data']['options']['config']['list'][1] = $GLOBALS['messages'][70019];	// Exported
	}

	// Delay
	$output['data']['options']['delay'] = array(
		'name' => $GLOBALS['messages'][70014],
		'type' => 'input',
		'value' => 0
	);

	// Console
	if ($p['type'] == 'qemu') {
		$output['data']['options']['console'] = array(
			'name' => $GLOBALS['messages'][70015],
			'type' => 'list',
			'value' => $p['console'],
			'list' => array('telnet' => 'telnet', 'vnc' => 'vnc', 'rdp' => 'rdp')
		);
	}

	// Dynamips options
	if ($p['type'] == 'dynamips') {
		$output['data']['dynamips'] = array();
		if (isset($p['dynamips_options'])) $output['data']['dynamips']['options'] = $p['dynamips_options'];
	}

	// QEMU options
	if ($p['type'] == 'qemu') {
		$output['data']['qemu'] = array();
		if (isset($p['qemu_arch'])) $output['data']['qemu']['arch'] = $p['qemu_arch'];
		if (isset($p['qemu_version'])) $output['data']['qemu']['version'] = $p['qemu_version'];
		if (isset($p['qemu_nic'])) $output['data']['qemu']['nic'] = $p['qemu_nic'];
		if (isset($p['qemu_options'])) $output['data']['qemu']['options'] = $p['qemu_options'];
	}
	return $output;
}

/**
 * Function to start a single node.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @param   int     $tenant             Tenant ID
 * @return  Array                       Return code (JSend data)
 */
function apiStartLabNode($lab, $id, $tenant, $username)
{
	ensureNodeConsolePortFree($lab, $id);
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a start';
	$cmd .= ' -T ' . $tenant;
	$cmd .= ' -U ' . $username;
	$cmd .= ' -D ' . $id;
	$cmd .= ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		// Nodes started
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80049];
	} else {
		// Failed to start
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to start all nodes.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $tenant             Tenant ID
 * @return  Array                       Return code (JSend data)
 */
function apiStartLabNodes($lab, $tenant, $username)
{
	$nodeIds = array_keys($lab->getNodes());
	if (!empty($nodeIds)) {
		ensureNodeConsolePortFree($lab, $nodeIds);
	}
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a start';
	$cmd .= ' -T ' . $tenant;
	$cmd .= ' -U ' . $username;
	$cmd .= ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		// Nodes started
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80048];
	} else {
		// Failed to start
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to stop a single node.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @param   int     $tenant             Tenant ID
 * @return  Array                       Return code (JSend data)
 */
function apiStopLabNode($lab, $id, $tenant, $username)
{
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a stop';
	$cmd .= ' -T ' . $tenant;
	$cmd .= ' -D ' . $id;
	$cmd .= ' -U ' . $username;
	$cmd .= ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	error_log($cmd);

	exec($cmd, $o, $rc);
	if ($rc == 0) {
		// Nodes started
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80051];
	} else {
		// Failed to stop
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to stop all nodes.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $tenant             Tenant ID
 * @return  Array                       Return code (JSend data)
 */
function apiStopLabNodes($lab, $tenant, $username)
{
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a stop';
	$cmd .= ' -T ' . $tenant;
	$cmd .= ' -U ' . $username;
	$cmd .= ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	error_log($cmd);

	exec($cmd, $o, $rc);
	if ($rc == 0) {
		// Nodes started
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80050];
	} else {
		// Failed to start
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to wipe a single node.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $id                 Node ID
 * @param   int     $tenant             Tenant ID
 * @return  Array                       Return code (JSend data)
 */
function apiWipeLabNode($lab, $id, $tenant, $username)
{
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a wipe';
	$cmd .= ' -T ' . $tenant;
	$cmd .= ' -U' . $username;
	$cmd .= ' -D ' . $id;
	$cmd .= ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		// Nodes started
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80053];
	} else {
		// Failed to start
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to wipe all nodes.
 *
 * @param   Lab     $lab                Lab
 * @param   int     $tenant             Tenant ID
 * @return  Array                       Return code (JSend data)
 */
function apiWipeLabNodes($lab, $tenant, $username)
{
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a wipe';
	$cmd .= ' -T ' . $tenant;
	$cmd .= ' -U' . $username;
	$cmd .= ' -F "' . $lab->getPath() . '/' . $lab->getFilename() . '"';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	exec($cmd, $o, $rc);
	if ($rc == 0) {
		// Nodes started
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80052];
	} else {
		// Failed to start
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	}
	return $output;
}

/**
 * Function to apply a batch action to multiple nodes.
 *
 * @param   Lab     $lab                Lab
 * @param   array   $ids                Node IDs
 * @param   string  $action             Action name (start|stop|delete)
 * @param   int     $tenant             Tenant ID
 * @param   string  $username           Username for wrappers
 * @param   array   $user               Full user record (for delete operations)
 * @return  Array                       Aggregated result (JSend data)
 */
function apiBatchNodeAction($lab, $ids, $action, $tenant, $username, $user, $progressCallback = null, $cancelCallback = null, $options = array())
{
	$action = strtolower($action);
	$supportedActions = array('start', 'stop', 'delete', 'wipe');
	if (!in_array($action, $supportedActions, True)) {
		return array(
			'code' => 400,
			'status' => 'fail',
			'message' => 'Unsupported node action.'
		);
	}

	$ids = array_values(array_unique(array_map('intval', $ids)));
	$results = array();
	$allSucceeded = True;
	$total = count($ids);
	$processed = 0;

	$cancelled = False;
	$maxParallel = null;
	if (isset($options['max_parallel'])) {
		$maxParallel = max(1, (int)$options['max_parallel']);
	}
	$currentBatch = 0;
	$runAs = $username;
	if (isset($options['runner']) && !empty($options['runner'])) {
		$runAs = $options['runner'];
	} else if (isset($user['role']) && $user['role'] === 'admin') {
		$author = $lab->getAuthor();
		if (!empty($author)) {
			$runAs = $author;
		}
	}
	foreach ($ids as $nodeId) {
		if ($cancelCallback !== null && call_user_func($cancelCallback) === True) {
			$cancelled = True;
			break;
		}
		switch ($action) {
			case 'start':
				$result = apiStartLabNode($lab, $nodeId, $tenant, $runAs);
				break;
			case 'stop':
				$result = apiStopLabNode($lab, $nodeId, $tenant, $runAs);
				break;
			case 'delete':
				$result = apiDeleteLabNode($lab, $nodeId, $user);
				break;
			case 'wipe':
				$result = apiWipeLabNode($lab, $nodeId, $tenant, $runAs);
				break;
			default:
				$result = array(
					'code' => 400,
					'status' => 'fail',
					'message' => 'Unsupported node action.'
				);
				break;
		}

		if ($result['code'] !== 200) {
			$allSucceeded = False;
		}

		$results[] = array(
			'id' => $nodeId,
			'code' => $result['code'],
			'status' => $result['status'],
			'message' => $result['message']
		);
		$processed++;
		if ($progressCallback !== null && $total > 0) {
			call_user_func($progressCallback, $processed, $total);
		}

		if ($action === 'start' && $maxParallel !== null) {
			$currentBatch++;
			if ($currentBatch >= $maxParallel && $processed < $total) {
				sleep(2);
				$currentBatch = 0;
			}
		}
	}

	$statusText = array(
		'start' => 'Start command sent to selected nodes.',
		'stop' => 'Stop command sent to selected nodes.',
		'delete' => 'Selected nodes deleted.',
		'wipe' => 'Wipe command sent to selected nodes.',
		'cancelled' => 'Job cancelled by user.'
	);

	$output = array(
		'code' => $allSucceeded ? 200 : 207,
		'status' => $allSucceeded ? 'success' : 'partial',
		'message' => isset($statusText[$action]) ? $statusText[$action] : 'Batch action executed.',
		'final_status' => $allSucceeded ? 'success' : 'partial',
		'data' => array(
			'results' => $results
		)
	);

	if ($cancelled) {
		$output['code'] = 200;
		$output['status'] = 'cancelled';
		$output['final_status'] = 'cancelled';
		$output['message'] = $statusText['cancelled'];
	} else if (!$allSucceeded) {
		$output['message'] .= ' Some operations failed.';
	}

	return $output;
}

/**
 * Ensure console ports are free before launching nodes.
 *
 * @param   Lab     $lab                Lab instance
 * @param   array   $nodeIds            Node IDs to check
 * @return  void
 */
function ensureNodeConsolePortFree($lab, $nodeIds)
{
	if (!is_array($nodeIds)) {
		$nodeIds = array($nodeIds);
	}
	$nodes = $lab->getNodes();
	foreach ($nodeIds as $nodeId) {
		if (!isset($nodes[$nodeId])) {
			continue;
		}
		$port = (int) $nodes[$nodeId]->getPort();
		if ($port <= 0) {
			continue;
		}
		$checkCmd = 'sudo fuser -n tcp ' . $port . ' 2>/dev/null';
		$output = array();
		exec($checkCmd, $output, $rc);
		if ($rc === 0) {
			$killCmd = 'sudo fuser -k -n tcp ' . $port . ' > /dev/null 2>&1';
			exec($killCmd);
			usleep(250000);
		}
	}
}
