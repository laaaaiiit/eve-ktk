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

	$relativePath = normalizeUserLabRelativePath('/' . implode('/', $path));
	$absolutePath = buildUserLabAbsolutePath($user, $relativePath);
	$output = apiGetFolders($absolutePath);

	if ($output['status'] === 'success') {
		$output['data'] = transformUserLabListing($user, $output['data'], $relativePath);
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
		$rc = updateUserFolder($db, $app->getCookie('unetlab_session'), $relativePath);
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
	$sRelative = normalizeUserLabRelativePath('/' . implode('/', $path));
	$p = json_decode(json_encode($event), True);
	$dRelative = normalizeUserLabRelativePath(isset($p['path']) ? $p['path'] : '/');
	$sAbsolute = buildUserLabAbsolutePath($user, $sRelative);
	$dAbsolute = buildUserLabAbsolutePath($user, $dRelative);
	$output = apiEditFolder($sAbsolute, $dAbsolute);

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
	if (!in_array($user['role'], array('admin', 'editor'))) {
		$app->response->setStatus($GLOBALS['forbidden']['code']);
		$app->response->setBody(json_encode($GLOBALS['forbidden']));
		return;
	}

	// TODO must check before using p name and p path

	$event = json_decode($app->request()->getBody());
	$p = json_decode(json_encode($event), True);
	$targetRelative = normalizeUserLabRelativePath(isset($p['path']) ? $p['path'] : '/');
	$targetAbsolute = buildUserLabAbsolutePath($user, $targetRelative);
	$output = apiAddFolder($p['name'], $targetAbsolute);

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
	$targetRelative = normalizeUserLabRelativePath($s);
	$targetAbsolute = buildUserLabAbsolutePath($user, $targetRelative);
	$output = apiDeleteFolder($targetAbsolute);

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

	$labFileRelative = normalizeUserLabRelativePath('/' . implode('/', $path));

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $labFileRelative);
	$id = preg_replace($patterns[1], $replacements[1], $labFileRelative);	// Interfere after lab_file.unl
	$lab_file_absolute = buildUserLabAbsolutePath($user, $lab_file);
	$lab_file_full = BASE_LAB . $lab_file_absolute;

	if (!is_file($lab_file_full)) {
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
		$relativePrefix = ($dirname == '/' ? '' : $dirname);
		$privateLabRelative = normalizeUserLabRelativePath($relativePrefix . '/' . $privateFilename);
		$privateLabAbsolute = buildUserLabAbsolutePath($user, $privateLabRelative);
		$privateLabAbsoluteFull = BASE_LAB . $privateLabAbsolute;

		// Если приватная копия не существует, клонируем её
		if (!is_file($privateLabAbsoluteFull)) {
			$cloneResult = apiCloneLabPrivate($lab_file_absolute, $privateLabAbsolute, $user);
			if ($cloneResult['code'] !== 200) {
				$app->response->setStatus($cloneResult['code']);
				$app->response->setBody(json_encode($cloneResult));
				return;
			}
		}

		// Используем приватную копию
		$labPathToUse = $privateLabAbsoluteFull;

		// Обновляем в БД текущую лабораторию – приватная копия
		$rc = updatePodLab($db, $user['tenant'], $privateLabRelative);
	} else {
		// Используем общую лабораторию
		$labPathToUse = $lab_file_full;
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
		if (!lockFile($lab_file_full)) {
			// Failed to lockFile within the time
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages'][60061];
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			return;
		}
		$output = apiStartLabNodes($lab, $user['tenant'], $user['username']);
		unlockFile($lab_file_full);
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
	if (isset($p['path'])) {
		$p['path'] = buildUserLabAbsolutePath($user, normalizeUserLabRelativePath($p['path']));
	}
	$s = normalizeUserLabRelativePath('/' . implode('/', $path));

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl
	$lab_file_absolute = buildUserLabAbsolutePath($user, $lab_file);
	$lab_file_full = BASE_LAB . $lab_file_absolute;

	if (!is_file($lab_file_full)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	// Locking
	if (!lockFile($lab_file_full)) {
		// Failed to lockFile within the time
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60061];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab($lab_file_full, $user['tenant'], $user['username'], false);
	} catch (Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$e->getMessage()];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		unlockFile($lab_file_full);
		return;
	}

	// Sharing
	// shared status
	if (isset($p['shared'])) {
		// Проверка, является ли пользователь автором или администратором
		if ($lab->getAuthor() != $user['username'] && $user['role'] != 'admin') {
			$app->response->setStatus(403); // Forbidden
			$app->response->setBody(json_encode(['code' => 403, 'status' => 'fail', 'message' => 'Forbidden: You are not the author or an admin.']));
			unlockFile($lab_file_full);
			return;
		}

		$output = apiEditLabShared($lab, $p['shared']);
		if ($output['code'] !== 200) {
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			unlockFile($lab_file_full);
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
			unlockFile($lab_file_full);
			return;
		}

		$output = apiEditLabSharedWith($lab, $p['sharedWith']);
		if ($output['code'] !== 200) {
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			unlockFile($lab_file_full);
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
			unlockFile($lab_file_full);
			return;
		}

		$output = apiEditLabCollaborateAllowed($lab, $p['collaborateAllowed']);
		if ($output['code'] !== 200) {
			$app->response->setStatus($output['code']);
			$app->response->setBody(json_encode($output));
			unlockFile($lab_file_full);
			return;
		}
		unset($p['collaborateAllowed']);
	}
	// Sharing

	// if (empty($p)) {
	// 	unlockFile($lab_file_full);
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
	unlockFile($lab_file_full);
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
	$basePathRelative = isset($p['path']) ? $p['path'] : '/';
	$p['path'] = buildUserLabAbsolutePath($user, normalizeUserLabRelativePath($basePathRelative));
	if (isset($p['source'])) {
		$p['source'] = buildUserLabAbsolutePath($user, normalizeUserLabRelativePath($p['source']));
	}

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
	$lab_file_full = BASE_LAB . $lab_file;

	// shared
	if (isset($p['shared'])) {
		$sharedOutput = apiEditLabShared($lab, $p['shared']);
		if ($sharedOutput['code'] !== 200) {
			unlockFile($lab_file_full);
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
			unlockFile($lab_file_full);
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
			unlockFile($lab_file_full);
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
	$s = normalizeUserLabRelativePath('/' . implode('/', $path));
	$o = False;

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl
	$lab_file_absolute = buildUserLabAbsolutePath($user, $lab_file);
	$lab_file_full = BASE_LAB . $lab_file_absolute;

	// Reading options from POST/PUT
	if (isset($event->postfix) && $event->postfix == True) $o = True;

	if (!is_file($lab_file_full)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	// Locking
	if (!lockFile($lab_file_full)) {
		// Failed to lockFile within the time
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60061];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab($lab_file_full, $user['tenant'], $user['username']);
	} catch (Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$e->getMessage()];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		unlockFile($lab_file_full);
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
	unlockFile($lab_file_full);
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
	$s = normalizeUserLabRelativePath('/' . implode('/', $path));

	$patterns[0] = '/(.+).unl.*$/';			// Drop after lab file (ending with .unl)
	$replacements[0] = '$1.unl';
	$patterns[1] = '/.+\/([0-9]+)\/*.*$/';	// Drop after lab file (ending with .unl)
	$replacements[1] = '$1';

	$lab_file = preg_replace($patterns[0], $replacements[0], $s);
	$id = preg_replace($patterns[1], $replacements[1], $s);	// Intefer after lab_file.unl
	$lab_file_absolute = buildUserLabAbsolutePath($user, $lab_file);
	$lab_file_full = BASE_LAB . $lab_file_absolute;

	if (!is_file($lab_file_full)) {
		// Lab file does not exists
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages']['60000'];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	try {
		$lab = new Lab($lab_file_full, $user['tenant'], $user['username']);
	} catch (Exception $e) {
		// Lab file is invalid
		if (preg_match('/^\/[A-Za-z0-9_+\/\\s-]+\.unl$/', $s)) {
			// Delete the lab
			if (unlink($lab_file_full)) {
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
		unlockFile($lab_file_full);
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
	if (isset($p['path'])) {
		$baseRelative = normalizeUserLabRelativePath($p['path']);
		$p['path'] = buildUserLabAbsolutePath($user, $baseRelative);
		foreach ($p as $key => $value) {
			if ($key === 'path') {
				continue;
			}
			if (!is_string($value)) {
				continue;
			}
			$itemRelative = normalizeUserLabRelativePath($value);
			$p[$key] = buildUserLabAbsolutePath($user, $itemRelative);
		}
	}

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
	if (isset($p['path'])) {
		$p['path'] = buildUserLabAbsolutePath($user, normalizeUserLabRelativePath($p['path']));
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
$app->get('/api/logs/files', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$baseDir = '/opt/unetlab/data/Logs';
	$baseReal = realpath($baseDir);
	$result = array();

	if ($baseReal !== false && is_dir($baseReal)) {
		$entries = scandir($baseReal);
		if (is_array($entries)) {
			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..') continue;
				if (strpos($entry, "\0") !== false) continue;
				$path = $baseReal . '/' . $entry;
				if (!is_file($path)) continue;
				$result[] = array(
					'name' => $entry,
					'size' => @filesize($path),
					'modified' => @date('c', @filemtime($path))
				);
			}
		}
	}

	usort($result, function ($a, $b) {
		return strcasecmp($a['name'], $b['name']);
	});

	$app->response->setStatus(200);
	$app->response->setBody(json_encode($result));
});

function getLogsPayload($file, $lines, $search) {
	$baseDir = '/opt/unetlab/data/Logs';
	$baseReal = realpath($baseDir);
	$safeLines = intval($lines);
	if ($safeLines <= 0) $safeLines = 200;
	if ($safeLines > 5000) $safeLines = 5000;

	if ($baseReal === false || !$file) {
		return array(200, array());
	}

	$target = $baseReal . '/' . basename($file);
	$targetReal = realpath($target);
	if ($targetReal === false || strpos($targetReal, $baseReal) !== 0 || !is_file($targetReal)) {
		return array(404, array());
	}

	$content = @file($targetReal, FILE_IGNORE_NEW_LINES);
	if (!is_array($content)) {
		return array(200, array());
	}

	$content = array_reverse($content);
	if ($search !== "") {
		$filtered = array();
		foreach ($content as $line) {
			if (strpos($line, $search) !== false) {
				$filtered[] = $line;
			}
		}
		$content = $filtered;
	}

	$content = array_slice($content, 0, $safeLines);
	return array(200, $content);
}

$app->get('/api/logs', function () use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$request = $app->request;
	$file = $request->params('file');
	$lines = $request->params('lines');
	$search = $request->params('search') ?: "";

	list($status, $payload) = getLogsPayload($file, $lines, $search);
	$app->response->setStatus($status);
	$app->response->setBody(json_encode($payload));
});

$app->get('/api/logs/(:file)/(:lines)/(:search)', function ($file = false, $lines = 200, $search = "") use ($app, $db) {
	list($user, $output) = apiAuthorization($db, $app->getCookie('unetlab_session'));
	if ($user === False) {
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	list($status, $payload) = getLogsPayload($file, $lines, $search);
	$app->response->setStatus($status);
	$app->response->setBody(json_encode($payload));
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
