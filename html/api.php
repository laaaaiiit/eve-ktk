<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/api.php
 *
 * REST API router for UNetLab.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @copyright fork 2025 Nikita Hochckov https://github.com/laaaaiiit
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

require_once('/opt/unetlab/html/includes/init.php');
require_once(BASE_DIR . '/html/includes/Slim/Slim.php');
require_once(BASE_DIR . '/html/includes/Slim-Extras/DateTimeFileWriter.php');
require_once(BASE_DIR . '/html/includes/api_authentication.php');
require_once(BASE_DIR . '/html/includes/api_configs.php');
require_once(BASE_DIR . '/html/includes/api_folders.php');
require_once(BASE_DIR . '/html/includes/api_labs.php');
require_once(BASE_DIR . '/html/includes/api_networks.php');
require_once(BASE_DIR . '/html/includes/api_nodes.php');
require_once(BASE_DIR . '/html/includes/api_pictures.php');
require_once(BASE_DIR . '/html/includes/api_status.php');
require_once(BASE_DIR . '/html/includes/api_textobjects.php');
require_once(BASE_DIR . '/html/includes/api_topology.php');
require_once(BASE_DIR . '/html/includes/api_uusers.php');
require_once(BASE_DIR . '/html/includes/api_clouds.php');
\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim(array(
	'mode' => 'production',
	'debug' => True,					// Change to False for production
	'log.level' => \Slim\Log::WARN,		// Change to WARN for production, DEBUG to develop
	'log.enabled' => True,
	'log.writer' => new \Slim\LogWriter(fopen('/opt/unetlab/data/Logs/api.txt', 'a'))
));

$app->hook('slim.after.router', function () use ($app) {
	// Log all requests and responses
	$request = $app->request;
	$response = $app->response;

	$app->log->debug('Request path: ' . $request->getPathInfo());
	$app->log->debug('Response status: ' . $response->getStatus());
});

$app->response->headers->set('Content-Type', 'application/json');
$app->response->headers->set('X-Powered-By', 'Unified Networking Lab API');
$app->response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
$app->response->headers->set('Cache-Control', 'post-check=0, pre-check=0');
$app->response->headers->set('Pragma', 'no-cache');

$app->notFound(function () use ($app) {
	$output['code'] = 404;
	$output['status'] = 'fail';
	$output['message'] = $GLOBALS['messages']['60038'];
	$app->halt($output['code'], json_encode($output));
});

class ResourceNotFoundException extends Exception {}
class AuthenticateFailedException extends Exception {}


$db = checkDatabase();
if ($db === False) {
	// Database is not available
	$app->map('/api/(:path+)', function () use ($app) {
		$output['code'] = 500;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['90003'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
	})->via('DELETE', 'GET', 'POST');
	$app->run();
}

$html5_db = html5_checkDatabase();
if ($html5_db === False) {
	// Database is not available
	$app->map('/api/(:path+)', function () use ($app) {
		$output['code'] = 500;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['90003'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
	})->via('DELETE', 'GET', 'POST');
	$app->run();
}


if (updateDatabase($db) == False) {
	// Failed to update database
	// TODO should run una tantum
	$app->map('/api/(:path+)', function () use ($app) {
		$output['code'] = 500;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['90006'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
	})->via('DELETE', 'GET', 'POST');
	$app->run();
}

// Define output for unprivileged requests
$forbidden = array(
	'code' => 401,
	'status' => 'forbidden',
	'message' => $GLOBALS['messages']['90032']
);

/***************************************************************************
 * Authentication
 **************************************************************************/
$app->post('/api/auth/login', function () use ($app, $db, $html5_db) {
	// Login
	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT
	$cookie = genUuid();
	$output = apiLogin($db, $html5_db, $p, $cookie);
	if ($output['code'] == 200) {
		// User is authenticated, need to set the cookie httpOnly to avoid identity stealth
		$app->setCookie('unetlab_session', $cookie, SESSION, '/api/', $_SERVER['SERVER_NAME'], False, True);
	}
	$http_code = $output['code'];
	if ($http_code == 400 && $output['status'] == 'fail') {
		// Keep payload code for clients but respond with 200 to avoid console errors on failed login attempts
		$http_code = 200;
	}
	$app->response->setStatus($http_code);
	$app->response->setBody(json_encode($output));
});

$app->get('/api/auth/logout', function () use ($app, $db) {
	// Logout (DELETE request does not work with cookies)
	$cookie = $app->getCookie('unetlab_session');
	$app->deleteCookie('unetlab_session');
	$output = apiLogout($db, $cookie);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

$app->get('/api/auth', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		// Set 401 not 412 for this page only->used to refresh after a logout
		$output['code'] = 401;
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (checkFolder(BASE_LAB . $user['folder']) !== 0) {
		// User has an invalid last viewed folder
		$user['folder'] = '/';
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['90002'];
	$output['data'] = $user;

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

/*
 * TODO
$app->put('/api/auth', function() use ($app, $db) {
	// Set tenant
	// TODO should be used by admin user on single-user mode only
});
 */

/***************************************************************************
 * Status
 **************************************************************************/
// Get system stats
$app->get('/api/status', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['60001'];
	$output['data'] = array();
	$output['data']['version'] = VERSION;
	$cmd = '/opt/qemu/bin/qemu-system-x86_64 -version | sed \'s/.* \([0-9]*\.[0-9.]*\.[0-9.]*\).*/\1/g\'';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][60044]);
		$output['data']['qemu_version'] = '';
	} else {
		$output['data']['qemu_version'] = $o[0];
	}
	$o = "";
	$cmd = " grep '[01]' /sys/kernel/mm/uksm/run";
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		$output['data']['uksm'] = 'unsupported';
	} else {
		if ($o[0] == "1") {
			$output['data']['uksm'] = "enabled";
		} else {
			$output['data']['uksm'] = "disabled";
		}
	}
	$o = "";
	$cmd = 'grep -q \'[01]\'  /sys/kernel/mm/ksm/run && systemctl is-active ksmtuned.service';
	exec($cmd, $o, $rc);
	if ($rc == 2) {
		$output['data']['ksm'] = 'unsupported';
	} else {
		if ($o[0] == "active") {
			$output['data']['ksm'] = "enabled";
		} else {
			$output['data']['ksm'] = "disabled";
		}
	}
	$o = "";
	$cmd = 'systemctl is-active cpulimit.service';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][60044]);
		$output['data']['cpulimit'] = 'disabled';
	} else {
		if ($o[0] == "active") {
			$output['data']['cpulimit'] = 'enabled';
		} else {
			$output['data']['cpulimit'] = 'disabled';
		}
	}
	$output['data']['cpu'] = apiGetCPUUsage();
	$output['data']['disk'] = apiGetDiskUsage();
	list($output['data']['cached'], $output['data']['mem']) = apiGetMemUsage();
	$output['data']['swap'] = apiGetSwapUsage();
	list(
		$output['data']['iol'],
		$output['data']['dynamips'],
		$output['data']['qemu'],
		$output['data']['docker'],
		$output['data']['vpcs']
	) = apiGetRunningWrappers();

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Stop all nodes and clear the system
$app->delete('/api/status', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a stopall';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][60044]);
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60050'];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages']['60051'];
	}

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

/***************************************************************************
 * List Objects
 **************************************************************************/
// Node templates
$app->get('/api/list/templates/(:template)', function ($template = '') use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (!isset($template) || $template == '') {
		// Print all available templates
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages']['60003'];
		$output['data'] = $GLOBALS['node_templates'];
	} else if (isset($GLOBALS['node_templates'][$template]) && is_file(BASE_DIR . '/html/' . TPL_DIR . '/' . $template . '.yml')) {
		// Template found
		$p = yaml_parse_file(BASE_DIR . '/html/' . TPL_DIR . '/' . $template . '.yml');
		$p['template'] = $template;
		$output = apiGetLabNodeTemplate($p);
	} else {
		// Template not found (or not available)
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60031'];
	}

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Network types
$app->get('/api/list/networks', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['60002'];
	$output['data'] = listNetworkTypes($user['username']);
	$output['icons'] = listNetworkIcons();

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Network icons
$app->get('/api/list/networkicons', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['60002'];
	$output['data'] = listNetworkIcons();

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});


// Get roles available
$app->get('/api/list/roles', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['60041'];
	$output['data'] = listRoles();

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});


/***************************************************************************
 * Clouds
 **************************************************************************/
// Get clouds
$app->get('/api/clouds', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$data = listCloudsNew($db);

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages']['60041'] ?? 'Cloud list fetched';
	$output['data'] = $data;

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Get cloud by ID
$app->get('/api/clouds/:id', function ($id) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	// Получаем данные облака по ID
	$output = apiGetCloudById($db, $id);
	if ($output['code'] === 200) {
		$app->response->setStatus(200);
		$app->response->setBody(json_encode($output));
	} else {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
	}
});

// Create clouds
$app->post('/api/clouds', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), true);
	$output = apiAddCloud($db, $p);

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Delete clouds
$app->delete('/api/clouds/:id', function ($id) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$output = apiDeleteCloud($db, $id);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Edit clouds
$app->put('/api/clouds/:id', function ($id) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), true);

	$output = apiUpdateCloud($db, $id, $p); // Вызов функции обновления облака

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});


/***************************************************************************
 * Folders
 **************************************************************************/
// Get folder content
$app->get('/api/folders/(:path+)', function ($path = array()) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$s = '/' . implode('/', $path);
	$output = apiGetFolders($s);

	if ($output['status'] === 'success') {
		// Setting folder as last viewed
		if (isset($user['role']) && strtolower($user['role']) === 'editor') {
			if (isset($output['data']['labs']) && is_array($output['data']['labs'])) {
				$output['data']['labs'] = array_values(array_filter($output['data']['labs'], function ($lab) use ($user) {
					$labAuthor   = isset($lab['author']) ? trim(strtolower($lab['author'])) : '';
					$username    = trim(strtolower($user['username']));
					$isShared    = isset($lab['shared']) && ($lab['shared'] === true || $lab['shared'] === 'true');
					$sharedWith  = isset($lab['sharedWith']) ? array_map('trim', explode(',', strtolower($lab['sharedWith']))) : [];

					if ($labAuthor === $username) {
						return true;
					}

					if (!$isShared) {
						return false;
					}

					return in_array($username, $sharedWith);
				}));
			}
		}
		$rc = updateUserFolder($db, $app->getCookie('unetlab_session'), $s);
		if ($rc !== 0) {
			// Cannot update user folder
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
		}
	}

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Edit (move and rename) a folder
$app->put('/api/folders/(:path+)', function ($path = array()) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	// TODO must check before using p name and p path

	$event = json_decode($app->request()->getBody());
	$s = '/' . implode('/', $path);
	$p = json_decode(json_encode($event), True);
	$output = apiEditFolder($s, $p['path']);

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Add a new folder
$app->post('/api/folders', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	// TODO must check before using p name and p path

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);
	$output = apiAddFolder($p['name'], $p['path']);

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Delete an existing folder
$app->delete('/api/folders/(:path+)', function ($path = array()) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$s = '/' . implode('/', $path);
	$output = apiDeleteFolder($s);

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

/***************************************************************************
 * Labs
 **************************************************************************/

// Get all nodes from all labs
$app->get('/api/nodes', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$allNodes = array();

	// Рекурсивная функция для сканирования всех .unl файлов
	function scanLabs($dir, &$allNodes, $user)
	{
		$files = scandir($dir);
		foreach ($files as $file) {
			if ($file == '.' || $file == '..') continue;

			$path = $dir . '/' . $file;
			if (is_dir($path)) {
				scanLabs($path, $allNodes, $user);
			} elseif (preg_match('/\.unl$/', $file)) {
				try {
					$lab = new Lab($path, $user['tenant'], $user['username']);
					$nodes = $lab->getNodes();

					foreach ($nodes as $nodeId => $node) {
						$allNodes[] = array(
							'lab' => str_replace(BASE_LAB, '', $path),
							'id' => $nodeId,
							'user' => $lab->getAuthor(),
							'name' => $node->getName(),
							'type' => $node->getNType(),
							'template' => $node->getTemplate(),
							'status' => $node->getStatus(),
							'cpulimit' => $node->getCpuLimit(),
							'cpucount' => $node->getCpu(),
							'image' => $node->getImage(),
							'nvram' => $node->getNvram(),
							'ram' => $node->getRam(),
							'eth' => $node->getEthernetCount(),
							'ser' => $node->getSerialCount(),
							'console' => $node->getConsole(),
							'url' => $node->getConsoleUrl($user['html5'], $user, $lab->getAuthor()),
							'port' => $node->getPort()
						);
					}
				} catch (Exception $e) {
					// Пропускаем невалидные lab файлы
					continue;
				}
			}
		}
	}

	// Запускаем сканирование с корня лабораторий
	scanLabs(BASE_LAB, $allNodes, $user);

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][60020];
	$output['data'] = $allNodes;

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Get an object
$app->get('/api/labs/(:path+)', function ($path = array()) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$labFileRelative = '/' . implode('/', $path);

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $labFileRelative);
	$id = preg_replace($patterns[1], $replacements[1], $labFileRelative);	// Interfere after lab_file.unl

	if (!is_file(BASE_LAB . $lab_file)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60000];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$mode = $app->request()->params('mode');

	if ($mode == 'private') {
		// Используем $lab_file (а не $labFileRelative), чтобы не попасть в ситуацию с лишними сегментами
		$dirname  = dirname($lab_file);
		$basename = basename($lab_file, ".unl");
		$privateFilename = $basename . '_' . $user['username'] . '.unl';
		$privateLabRelative = ($dirname == '/' ? '' : $dirname) . '/' . $privateFilename;
		$privateLabAbsolute = BASE_LAB . $privateLabRelative;

		// Если приватная копия не существует, клонируем её
		if (!is_file($privateLabAbsolute)) {
			$cloneResult = apiCloneLabPrivate($lab_file, $privateLabRelative, $user);
			if ($cloneResult['code'] !== 200) {
				$app->response->setStatus($cloneResult['code']);
				$app->response->setBody(json_encode($cloneResult));
				return;
			}
		}

		// Используем приватную копию
		$labPathToUse = $privateLabAbsolute;

		// Обновляем в БД текущую лабораторию – приватная копия
		$rc = updatePodLab($db, $user['tenant'], $privateLabRelative);
	} else {
		// Используем общую лабораторию
		$labPathToUse = BASE_LAB . $lab_file;
	}

	try {
		$lab = new Lab($labPathToUse, $user['tenant'], $user['username'], false);
	} catch (Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60056];
		$output['message'] = $e->getMessage();
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/html$/', $labFileRelative)) {
		$Parsedown = new Parsedown();
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages']['60054'];
		$output['data'] = $Parsedown->text($lab->getBody());
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/configs$/', $labFileRelative)) {
		$output = apiGetLabConfigs($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/configs\/[0-9]+$/', $labFileRelative)) {
		$output = apiGetLabConfig($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks$/', $labFileRelative)) {
		$output = apiGetLabNetworks($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks\/[0-9]+$/', $labFileRelative)) {
		$output = apiGetLabNetwork($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/links$/', $labFileRelative)) {
		$output = apiGetLabLinks($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes$/', $labFileRelative)) {
		$output = apiGetLabNodes($lab, $user['html5'], $user);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/start$/', $labFileRelative)) {
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}

		// Locking to avoid "device vnet12_20 already exists; can't create bridge with the same name"
		if (!lockFile(BASE_LAB . $lab_file)) {
			// Failed to lockFile within the time
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages'][60061];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiStartLabNodes($lab, $user['tenant'], $user['username']);
		unlockFile(BASE_LAB . $lab_file);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/stop$/', $labFileRelative)) {
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiStopLabNodes($lab, $user['tenant'], $user['username']);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/wipe$/', $labFileRelative)) {
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiWipeLabNodes($lab, $user['tenant'], $user['username']);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+$/', $labFileRelative)) {
		$output = apiGetLabNode($lab, $id, $user['html5'], $user);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/interfaces$/', $labFileRelative)) {
		$output = apiGetLabNodeInterfaces($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/start$/', $labFileRelative)) {
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiStartLabNode($lab, $id, $lab->getTenant(), $lab->getAuthor());
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/stop$/', $labFileRelative)) {
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiStopLabNode($lab, $id, $user['tenant'], $user['username']);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/wipe$/', $labFileRelative)) {
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiWipeLabNode($lab, $id, $user['tenant'], $user['username']);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/topology$/', $labFileRelative)) {
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		// Setting lab as last viewed
		$rc = updatePodLab($db, $user['tenant'], $lab_file);
		if ($rc !== 0) {
			// Cannot update user lab
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
		} else {
			$output = apiGetLabTopology($lab);
		}
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects$/', $labFileRelative)) {
		$output = apiGetLabTextObjects($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects\/[0-9]+$/', $labFileRelative)) {
		$output = apiGetLabTextObject($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures$/', $labFileRelative)) {
		$output = apiGetLabPictures($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+$/', $labFileRelative)) {
		$output = apiGetLabPicture($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/picturesmapped\/[0-9]+$/', $labFileRelative)) {
		$output = apiGetLabPictureMapped($lab, $id, $user['html5'], $user);
		//$output = apiGetLabPicture($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+\/data$/', $labFileRelative)) {
		$height = 0;
		$width = 0;
		if ($app->request()->params('width') > 0) {
			$width = $app->request()->params('width');
		}
		if ($app->request()->params('height')) {
			$height = $app->request()->params('height');
		}
		$output = apiGetLabPictureData($lab, $id, $width, $height);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+\/data\/[0-9]+\/[0-9]+$/', $labFileRelative)) {
		// Get Thumbnail
		$height = preg_replace('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+\/data\/\([0-9]+\)\/\([0-9]+\)$/', '$1', $labFileRelative);
		$width = preg_replace('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+\/data\/\([0-9]+\)\/\([0-9]+\)$/', '$1', $labFileRelative);
		$output = apiGetLabPictureData($lab, $id, $width, $height);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $labFileRelative)) {
		$output = apiGetLab($lab);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60027];
	}

	$app->response->setStatus($output['code']);
	if (isset($output['encoding'])) {
		// Custom encoding
		$app->response->headers->set('Content-Type', $output['encoding']);
		$app->response->setBody($output['data']);
	} else {
		// Default encoding
		$app->response->setBody(json_encode($output));
	}
});

// Edit an existing object
$app->put('/api/labs/(:path+)', function ($path = array()) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin', 'editor'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT
	$s = '/' . implode('/', $path);

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl

	if (!is_file(BASE_LAB . $lab_file)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	// Locking
	if (!lockFile(BASE_LAB . $lab_file)) {
		// Failed to lockFile within the time
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60061];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab(BASE_LAB . $lab_file, $user['tenant'], $user['username'], false);
	} catch (Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$e->getMessage()];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		unlockFile(BASE_LAB . $lab_file);
		return;
	}

	// Sharing
	// shared status
	if (isset($p['shared'])) {
		// Проверка, является ли пользователь автором или администратором
		if ($lab->getAuthor() != $user['username'] && $user['role'] != 'admin') {
			$app->response->setStatus(403); // Forbidden
			$app->response->setBody(json_encode(['code' => 403, 'status' => 'fail', 'message' => 'Forbidden: You are not the author or an admin.']));
			unlockFile(BASE_LAB . $lab_file);
			return;
		}

		$output = apiEditLabShared($lab, $p['shared']);
		if ($output['code'] !== 200) {
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			unlockFile(BASE_LAB . $lab_file);
			return;
		}
		unset($p['shared']);
	}

	// shared with
	if (isset($p['sharedWith'])) {
		// Проверка, является ли пользователь автором или администратором
		if ($lab->getAuthor() != $user['username'] && $user['role'] != 'admin') {
			$app->response->setStatus(403); // Forbidden
			$app->response->setBody(json_encode(['code' => 403, 'status' => 'fail', 'message' => 'Forbidden: You are not the author or an admin.']));
			unlockFile(BASE_LAB . $lab_file);
			return;
		}

		$output = apiEditLabSharedWith($lab, $p['sharedWith']);
		if ($output['code'] !== 200) {
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			unlockFile(BASE_LAB . $lab_file);
			return;
		}
		unset($p['sharedWith']);
	}

	// collaborateAllowed
	if (isset($p['collaborateAllowed'])) {
		// Проверка, является ли пользователь автором или администратором
		if ($lab->getAuthor() != $user['username'] && $user['role'] != 'admin') {
			$app->response->setStatus(403); // Forbidden
			$app->response->setBody(json_encode(['code' => 403, 'status' => 'fail', 'message' => 'Forbidden: You are not the author or an admin.']));
			unlockFile(BASE_LAB . $lab_file);
			return;
		}

		$output = apiEditLabCollaborateAllowed($lab, $p['collaborateAllowed']);
		if ($output['code'] !== 200) {
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			unlockFile(BASE_LAB . $lab_file);
			return;
		}
		unset($p['collaborateAllowed']);
	}
	// Sharing

	// if (empty($p)) {
	// 	unlockFile(BASE_LAB . $lab_file);
	// 	$app->response->setStatus($output['code']);
	// 	$app->response->setBody(json_encode($output));
	// 	return;
	// }

	if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$p['username'] = $lab->getAuthor();

		if (isset($p['count'])) {
			// count cannot be set from API
			unset($p['count']);
		}
		$output = apiEditLabNetwork($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks$/', $s)) {
		$output = apiEditLabNetworks($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/configs\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$p['username'] = $lab->getAuthor();

		$output = apiEditLabConfig($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/export$/', $s)) {
		if (!in_array($user['role'], array('admin', 'editor'))) {
			$app->response->setStatus($GLOBALS['forbidden']['code']);
			$app->response->setBody(json_encode($GLOBALS['forbidden']));
			return;
		}
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiExportLabNodes($lab, $user['tenant'], $user['username']);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$output = apiEditLabNode($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes$/', $s)) {
		$output = apiEditLabNodes($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/export$/', $s)) {
		if ($user['tenant'] < 0) {
			// User does not have an assigned tenant
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages']['60052'];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiExportLabNode($lab, $id, $user['tenant'], $user['username']);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+\/interfaces$/', $s)) {
		$output = apiEditLabNodeInterfaces($lab, $id, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$output = apiEditLabTextObject($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects$/', $s)) {
		$output = apiEditLabTextObjects($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+$/', $s)) {
		$p['id'] = $id;
		$output = apiEditLabPicture($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $s)) {
		$output = apiEditLab($lab, $p);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/Lock$/', $s)) {
		$output = apiLockLab($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/Unlock$/', $s)) {
		$output = apiUnlockLab($lab);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/move$/', $s)) {
		$output = apiMoveLab($lab, $p['path']);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60027];
	}

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
	unlockFile(BASE_LAB . $lab_file);
});

// Add new lab
$app->post('/api/labs', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin', 'editor'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);

	if (isset($p['source'])) {
		$output = apiCloneLab($p, $user['tenant'], $user['username']);
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	// Добавляем
	list($output, $lab) = apiAddLab($p, $user['tenant'], $user['username']);

	// Если не удалось создать - сразу возвращаем ошибку
	if ($lab === null) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	// === 🔽 ДОПОЛНИТЕЛЬНЫЕ СВОЙСТВА ===
	$lab_file = $p['path'] . '/' . $p['name'] . '.unl';

	// shared
	if (isset($p['shared'])) {
		$sharedOutput = apiEditLabShared($lab, $p['shared']);
		if ($sharedOutput['code'] !== 200) {
			unlockFile(BASE_LAB . $lab_file);
			$app->response->setStatus($sharedOutput['code']);
			$app->response->setBody(json_encode($sharedOutput));
			return;
		}
		unset($p['shared']);
	}

	// sharedWith
	if (isset($p['sharedWith'])) {
		$sharedWithOutput = apiEditLabSharedWith($lab, $p['sharedWith']);
		if ($sharedWithOutput['code'] !== 200) {
			unlockFile(BASE_LAB . $lab_file);
			$app->response->setStatus($sharedWithOutput['code']);
			$app->response->setBody(json_encode($sharedWithOutput));
			return;
		}
		unset($p['sharedWith']);
	}

	// collaborateAllowed
	if (isset($p['collaborateAllowed'])) {
		$collabOutput = apiEditLabCollaborateAllowed($lab, $p['collaborateAllowed']);
		if ($collabOutput['code'] !== 200) {
			unlockFile(BASE_LAB . $lab_file);
			$app->response->setStatus($collabOutput['code']);
			$app->response->setBody(json_encode($collabOutput));
			return;
		}
		unset($p['collaborateAllowed']);
	}

	// Всё ок
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Add new object inside a lab
$app->post('/api/labs/(:path+)', function ($path = array()) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin', 'editor'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT
	$s = '/' . implode('/', $path);
	$o = False;

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl

	// Reading options from POST/PUT
	if (isset($event->postfix) && $event->postfix == True) $o = True;

	if (!is_file(BASE_LAB . $lab_file)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	// Locking
	if (!lockFile(BASE_LAB . $lab_file)) {
		// Failed to lockFile within the time
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60061];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab(BASE_LAB . $lab_file, $user['tenant'], $user['username']);
	} catch (Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$e->getMessage()];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		unlockFile(BASE_LAB . $lab_file);
		return;
	}

	if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks$/', $s)) {
		$output = apiAddLabNetwork($lab, $p, $o);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes$/', $s)) {
		if (isset($p['count'])) {
			// count cannot be set from API
			unset($p['count']);
		}
		$output = apiAddLabNode($lab, $p, $o);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects$/', $s)) {
		$output = apiAddLabTextObject($lab, $p, $o);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures$/', $s)) {
		// Cannot use $app->request()->getBody()
		$p = $_POST;
		if (!empty($_FILES)) {
			foreach ($_FILES as $file) {
				if (file_exists($file['tmp_name'])) {
					$fp = fopen($file['tmp_name'], 'r');
					$size = filesize($file['tmp_name']);
					if ($fp !== False) {
						$finfo = new finfo(FILEINFO_MIME);
						$p['data'] = fread($fp, $size);
						$p['type'] = $finfo->buffer($p['data'], FILEINFO_MIME_TYPE);
					}
				}
			}
		}
		$output = apiAddLabPicture($lab, $p);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60027];
	}

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
	unlockFile(BASE_LAB . $lab_file);
});

// Close a lab
$app->delete('/api/labs/close', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if ($user['tenant'] < 0) {
		// User does not have an assigned tenant
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60052'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$rc = updatePodLab($db, $user['tenant'], null);
	if ($rc !== 0) {
		// Cannot update user lab
		$output['code'] = 500;
		$output['status'] = 'error';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60053];
	}

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Delete an object
$app->delete('/api/labs/(:path+)', function ($path = array()) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin', 'editor'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$s = '/' . implode('/', $path);

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl

	if (!is_file(BASE_LAB . $lab_file)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab(BASE_LAB . $lab_file, $user['tenant'], $user['username']);
	} catch (Exception $e) {
		// Lab file is invalid
		if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $s)) {
			// Delete the lab
			if (unlink(BASE_LAB . $lab_file)) {
				$output['code'] = 200;
				$output['status'] = 'success';
			} else {
				$output['code'] = 400;
				$output['status'] = 'fail';
				$output['message'] = $GLOBALS['messages'][60021];
			}
		} else {
			// Cannot delete objects on non-valid lab
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages'][$e->getMessage()];
		}
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if ($lab->getAuthor() != $user['username'] && $user['role'] != 'admin') {
		unlockFile(BASE_LAB . $lab_file);
		$output['code'] = 403;
		$output['status'] = 'fail';
		$output['message'] = 'Недостаточно прав для удаления лаборатории';
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/networks\/[0-9]+$/', $s)) {
		$output = apiDeleteLabNetwork($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/nodes\/[0-9]+$/', $s)) {
		$output = apiDeleteLabNode($lab, $id, $user);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/textobjects\/[0-9]+$/', $s)) {
		$output = apiDeleteLabTextObject($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl\/pictures\/[0-9]+$/', $s)) {
		$output = apiDeleteLabPicture($lab, $id);
	} else if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $s)) {
		$output = apiDeleteLab($lab, $user);
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60027];
	}

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

/***************************************************************************
 * Users
 **************************************************************************/
// Get a user
$app->get('/api/users/(:uuser)', function ($uuser = False) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	if (empty($uuser)) {
		$output = apiGetUUsers($db);
	} else {
		$output = apiGetUUser($db, $uuser);
	}
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Edit a user
$app->put('/api/users/(:uuser)', function ($uuser = False) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT

	$output = apiEditUUser($db, $uuser, $p);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Add a user
$app->post('/api/users', function ($uuser = False) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);	// Reading options from POST/PUT

	$output = apiAddUUser($db, $p);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Delete a user
$app->delete('/api/users/(:uuser)', function ($uuser = False) use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$output = apiDeleteUUser($db, $uuser);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Change cpulimit

$app->post('/api/cpulimit', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);    // Reading options from POST/PUT

	$output = apiSetCpuLimit($p);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Change uksm

$app->post('/api/uksm', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);    // Reading options from POST/PUT

	$output = apiSetUksm($p);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});
// Change ksm

$app->post('/api/ksm', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);    // Reading options from POST/PUT

	$output = apiSetKsm($p);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

/***************************************************************************
 * Export/Import
 **************************************************************************/
// Export labs
$app->post('/api/export', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin', 'editor'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);;

	$output = apiExportLabs($p);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

// Import labs
$app->post('/api/import', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin', 'editor'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	// Cannot use $app->request()->getBody()
	$p = $_POST;
	if (!empty($_FILES)) {
		foreach ($_FILES as $file) {
			$p['name'] = $file['name'];
			$p['file'] = $file['tmp_name'];
			$p['error'] = $file['name'];
		}
	}
	$output = apiImportLabs($p, $user);
	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});

/***************************************************************************
 * Update
 **************************************************************************/
$app->get('/api/update', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}
	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper -a update';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][60059]);
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60059'];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages']['60060'];
	}

	$app->response->setStatus($output['code']);
	$app->response->setBody(json_encode($output));
});


/***************************************************************************
 * LOGS
 **************************************************************************/
$app->get('/api/logs/(:file)/(:lines)/(:search)', function ($file = False, $lines = 10, $search = "") use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$f = @file_get_contents("/opt/unetlab/data/Logs/" . $file);
	if ($f) {
		$arr = explode("\n", $f);
		if (!is_array($arr))
			$arr = array();
		$arr = array_reverse($arr);

		if ($search) {
			foreach ($arr as $k => $v) {
				if (strstr($v, $search) === false)
					unset($arr[$k]);
			}
		}

		$arr = array_slice($arr, 0, $lines);
	} else
		$arr = array();

	$app->response->setStatus(200);
	$app->response->setBody(json_encode($arr));
});

/***************************************************************************
 * ICONS
 **************************************************************************/
$app->get('/api/icons', function () use ($app, $db) {
	$arr = listNodeIcons();
	$app->response->setStatus(200);
	$app->response->setBody(json_encode($arr));
});


$app->get('/api/token', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));

	if ($user === false) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));

		return;
	}

	if (!in_array($user['role'], array('admin'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	//updateUserToken($db, $user['username'], $user['tenant']);

	$token = getHtml5Token($user['tenant']);
	if (!$token) {
		$response = [
			'code'    => 500,
			'status'  => 'fail',
			'message' => 'Ошибка генерации токена'
		];
		$app->response->setStatus(500);
		$app->response->setBody(json_encode($response));

		return;
	}

	$response = [
		'code'    => 200,
		'status'  => 'success',
		'message' => 'Guacamole токен получен успешно',
		'data'    => ['token' => $token]
	];

	$app->response->setStatus(200);
	$app->response->setBody(json_encode($response));
});

/***************************************************************************
 * Run
 **************************************************************************/
$app->run();
