<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/includes/api_labs.php
 *
 * Labs related functions for REST APIs.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @copyright fork 2025 Nikita Hochckov https://github.com/laaaaiiit
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

function apiAddLab($p, $tenant, $author)
{
	$output = [];

	// Check mandatory parameters
	if (!isset($p['path']) || !isset($p['name'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60017];
		return [$output, null];
	}

	// Parent folder must exist
	if (!is_dir(BASE_LAB . $p['path'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60018];
		return [$output, null];
	}

	if ($p['path'] == '/') {
		$lab_file = '/' . $p['name'] . '.unl';
	} else {
		$lab_file = $p['path'] . '/' . $p['name'] . '.unl';
	}

	if (is_file(BASE_LAB . $lab_file)) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60016];
		return [$output, null];
	}

	try {
		// Create the lab
		$lab = new Lab(BASE_LAB . $lab_file, $tenant, $author);
	} catch (ErrorException $e) {
		// Failed to create the lab
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = (string) $e;
		return [$output, null];
	}

	// Set author/description/version
	$rc = $lab->edit($p);
	if ($rc !== 0) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
		return [$output, null];
	}

	// Success
	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][60019];
	return [$output, $lab];
}

/*
 * Function to add a lab.
 *
 * @param	Array		$p				Parameters
 * @return	Array						Return code (JSend data)
 */
function apiCloneLab($p, $tenant, $author)
{
	$rc = checkFolder(BASE_LAB . dirname($p['source']));
	if ($rc === 2) {
		// Folder is not valid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60009];
		return $output;
	} else if ($rc === 1) {
		// Folder does not exist
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60008];
		return $output;
	}

	if (!is_file(BASE_LAB . $p['source'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60000];
		return $output;
	}

	if (!copy(BASE_LAB . $p['source'], BASE_LAB . dirname($p['source']) . '/' . $p['name'] . '.unl')) {
		// Failed to copy
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60037];
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][60037]);
		return $output;
	}

	try {
		$lab = new Lab(BASE_LAB . dirname($p['source']) . '/' . $p['name'] . '.unl', $tenant, $author);
	} catch (Exception $e) {
		// Lab file is invalid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$e->getMessage()];
		$app->response->setStatus($output['code']);
		$app->response->setBody(json_encode($output));
		return;
	}

	$rc = $lab->edit($p);
	$lab->setId();
	if ($rc !== 0) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60036];
	}

	return $output;
}

/*
 * Function to delete a lab.
 *
 * @param	string		$lab_id			Lab ID
 * @param	string		$lab_file		Lab file
 * @return	Array						Return code (JSend data)
 */
function apiDeleteLab(Lab $lab, $user)
{
	$tenant = $lab->getTenant();
	$author = $lab->getAuthor();
	$lab_file = $lab->getPath() . '/' . $lab->getFilename();

	apiStopLabNodes($lab, $tenant, $user['username']);

	$html5_db = html5_checkDatabase();
	$nodes = $lab->getNodes();
	foreach ($nodes as $node) {
		$name = $node->getName() . '_' . $node->getId() . '_' . $lab->getAuthor();
		error_log($name);
		html5DeleteSession($html5_db, $name);
	}
	
	$cmd = 'sudo /opt/unetlab/wrappers/unl_wrapper';
	$cmd .= ' -a delete';
	$cmd .= ' -F "' . $lab_file . '"';
	$cmd .= ' -T ' . $tenant . '';
	$cmd .= ' -U ' . $author . '';
	$cmd .= ' 2>> /opt/unetlab/data/Logs/unl_wrapper.txt';
	error_log($cmd);

	exec($cmd, $o, $rc);	
	if ($rc == 0 && unlink($lab_file)) {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60022];
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60021];
	}
	return $output;
}

/*
 * Helper to check if a lab has running nodes.
 *
 * @param	Lab		$lab	Lab
 * @return	bool
 */
function labHasRunningNodes($lab)
{
	$nodes = $lab->getNodes();

	if (empty($nodes)) {
		return false;
	}

	foreach ($nodes as $node) {
		$status = intval($node->getStatus());
		if ($status === 2 || $status === 3) {
			return true;
		}
	}

	return false;
}

/*
 * Function to edit a lab.
 *
 * @param	Lab			$lab			Lab
 * @param	Array		$lab			Parameters
 * @return	Array						Return code (JSend data)
 */
function apiEditLab($lab, $p)
{
	if (labHasRunningNodes($lab)) {
		$output['code'] = 409;
		$output['status'] = 'fail';
		$output['message'] = 'Stop all running nodes before editing the lab.';
		return $output;
	}

	// Set author/description/version
	$rc = $lab->edit($p);
	if ($rc !== 0) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60023];
	}
	return $output;
}

/*
 * Function to export labs.
 *
 * @param	Array		$p				Parameters
 * @return	Array						Return code (JSend data)
 */
function apiExportLabs($p)
{
	$export_url = '/Exports/unetlab_export-' . date('Ymd-His') . '.zip';
	$export_file = '/opt/unetlab/data' . $export_url;
	if (is_file($export_file)) {
		unlink($export_file);
	}

	if (checkFolder(BASE_LAB . $p['path']) !== 0) {
		// Path is not valid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][80077];
		return $output;
	}

	if (!chdir(BASE_LAB . $p['path'])) {
		// Cannot set CWD
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS[80072];
		return $output;
	}

	foreach ($p as $key => $element) {
		if ($key === 'path') {
			continue;
		}

		// Using "element" relative to "path", adding '/' if missing
		$relement = substr($element, strlen($p['path']));
		if ($relement[0] != '/') {
			$relement = '/' . $relement;
		}

		if (is_file(BASE_LAB . $p['path'] . $relement)) {
			// Adding a file
			$cmd = 'zip ' . $export_file . ' ".' . $relement . '"';
			exec($cmd, $o, $rc);
			if ($rc != 0) {
				$output['code'] = 400;
				$output['status'] = 'fail';
				$output['message'] = $GLOBALS['messages'][80073];
				return $output;
			}
		}

		if (checkFolder(BASE_LAB . $p['path'] . $relement) === 0) {
			// Adding a dir
			$cmd = 'zip -r ' . $export_file . ' ".' . $relement . '"';
			exec($cmd, $o, $rc);
			if ($rc != 0) {
				$output['code'] = 400;
				$output['status'] = 'fail';
				$output['message'] = $GLOBALS['messages'][80074];
				return $output;
			}
		}
	}

	// Now remove UUID from labs
	$cmd = BASE_DIR . '/scripts/remove_uuid.sh "' . $export_file . '"';
	exec($cmd, $o, $rc);
	if ($rc != 0) {
		if (is_file($export_file)) {
			unlink($export_file);
		}
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
		return $output;
	}

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][80075];
	$output['data'] = $export_url;
	return $output;
}

/*
 * Function to get a lab.
 *
 * @param	Lab			$lab			Lab
 * @return	Array						Return code (JSend data)
 */
function apiGetLab(Lab $lab)
{
	// Printing info
	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][60020];
	$output['data'] = array(
		'author' => $lab->getAuthor(),
		'description' => $lab->getDescription(),
		'body' => $lab->getBody(),
		'filename' => $lab->getFilename(),
		'id' => $lab->getId(),
		'name' => $lab->getName(),
		'version' => $lab->getVersion(),
		'scripttimeout' => $lab->getScriptTimeout(),
		'lock' => $lab->getLock(),
		'shared' => $lab->getShared(),
		'sharedWith' => $lab->getSharedWith(),
		'isMirror' => $lab->getIsMirror(),
		'collaborateAllowed' => $lab->getCollaborateAllowed()
	);

	return $output;
}

/*
 * Function to get all lab links (networks and serial endpoints).
 *
 * @param	Lab			$lab			Lab file
 * @return	Array						Return code (JSend data)
 */
function apiGetLabLinks($lab)
{
	$output['data'] = array();

	// Get ethernet links
	$ethernets = array();
	$networks = $lab->getNetworks();
	if (!empty($networks)) {
		foreach ($lab->getNetworks() as $network_id => $network) {
			$ethernets[$network_id] = $network->getName();
		}
	}

	// Get serial links
	$serials = array();
	$nodes = $lab->getNodes();
	if (!empty($nodes)) {
		foreach ($nodes as $node_id => $node) {
			if (!empty($node->getSerials())) {
				$serials[$node_id] = array();
				foreach ($node->getSerials() as $interface_id => $interface) {
					// Print all available serial links
					$serials[$node_id][$interface_id] = $node->getName() . ' ' . $interface->getName();
				}
			}
		}
	}

	// Printing info
	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][60024];
	$output['data']['ethernet'] = $ethernets;
	$output['data']['serial'] = $serials;
	return $output;
}

/*
 * Function to import labs.
 *
 * @param	Array		$p				Parameters
 * @return	Array						Return code (JSend data)
 */
function apiImportLabs($p, $user)
{
	ini_set('max_execution_time', '300');
	ini_set('memory_limit', '64M');

	error_log('[apiImportLabs] Starting import process');

	if (!isset($p['file']) || empty($p['file'])) {
		error_log('[apiImportLabs] File parameter is missing or empty');
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][80081];
		return $output;
	}

	if (!isset($p['path'])) {
		error_log('[apiImportLabs] Path parameter is missing');
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][80076];
		return $output;
	}

	$destPath = BASE_LAB . $p['path'];
	if (checkFolder($destPath) !== 0) {
		error_log('[apiImportLabs] Invalid folder: ' . $destPath);
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][80077];
		return $output;
	}

	$finfo = new finfo(FILEINFO_MIME);
	if (strpos($finfo->file($p['file']), 'application/zip') !== False) {
		error_log('[apiImportLabs] File is recognized as a zip archive');
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][80079];

		$zip = new ZipArchive;
		$importedFiles = array();

		if ($zip->open($p['file']) === TRUE) {
			error_log('[apiImportLabs] Zip archive opened successfully');
			// Сканируем файлы в архиве
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$fileName = $zip->getNameIndex($i);
				error_log('[apiImportLabs] Found file in zip: ' . $fileName);

				// Проверка имени файла на безопасность
				if (escapeshellcmd($fileName) != $fileName) {
					error_log('[apiImportLabs] Unsafe file name detected: ' . $fileName);
					$zip->close();
					return $output;
				}

				// Если файл имеет расширение .unl, добавляем его в массив importedFiles
				if (preg_match('/\.unl$/i', $fileName)) {
					$baseName = basename($fileName);
					$originalBaseName = $baseName;

					// Проверка на существование файла
					$fullPath = $destPath . '/' . $baseName;
					while (file_exists($fullPath)) {
						$randomSuffix = '-' . rand(1000, 9999);
						$baseName = preg_replace('/\.unl$/i', $randomSuffix . '.unl', $originalBaseName);
						$fullPath = $destPath . '/' . $baseName;
					}

					$importedFiles[] = $baseName;
					$renamedFiles[$fileName] = $baseName;
					error_log('[apiImportLabs] Added file to importedFiles (possibly renamed): ' . $baseName);
				}
			}
			$zip->close();
			error_log('[apiImportLabs] Finished scanning zip archive');
		} else {
			error_log('[apiImportLabs] Failed to open zip archive');
			return $output;
		}

		// Извлечение файлов из архива _до_ сканирования каталога
		$tempPath = sys_get_temp_dir() . '/import_' . uniqid();
		mkdir($tempPath);

		$cmd = 'unzip -o -d "' . $tempPath . '" ' . escapeshellarg($p['file']) . ' *.unl';
		exec($cmd, $o, $rc);
		if ($rc != 0) {
			error_log('[apiImportLabs] Unzip to temp failed with code: ' . $rc);
			return $output;
		}

		// Перемещаем и переименовываем
		foreach ($renamedFiles as $original => $newName) {
			rename($tempPath . '/' . $original, $destPath . '/' . $newName);
		}

		// Функция сканирования каталога для обработки импортированных lab файлов
		function scanLabs($dir, &$allNodes, $user, $importedFiles)
		{
			error_log('[scanLabs] Scanning directory: ' . $dir);
			$files = scandir($dir);
			error_log('[scanLabs] Files found: ' . implode(', ', $files));
			foreach ($files as $file) {
				if ($file == '.' || $file == '..') continue;

				$path = $dir . '/' . $file;
				if (is_dir($path)) {
					error_log('[scanLabs] Entering subdirectory: ' . $path);
					scanLabs($path, $allNodes, $user, $importedFiles);
				} elseif (preg_match('/\.unl$/i', $file)) {
					error_log('[scanLabs] Found .unl file: ' . $file . ' at path: ' . $path);
					// Проверяем, что данный файл был в архиве
					if (in_array($file, $importedFiles)) {
						error_log('[scanLabs] File ' . $file . ' is in importedFiles. Processing...');
						try {
							$lab = new Lab($path, $user['tenant'], $user['username']);
							error_log('[scanLabs] Lab object created for file: ' . $file);
							// Применяем настройки для импортированных лаб:
							$lab->setShared(false);           // отключаем общий доступ
							error_log('[scanLabs] Shared set to false for file: ' . $file);
							$lab->setSharedWith('');          // очищаем список общих пользователей
							error_log('[scanLabs] SharedWith cleared for file: ' . $file);
							$lab->setTenant($user['tenant']);
							error_log('[scanLabs] Tenant set to ' . $user['tenant'] . ' for file: ' . $file);
							$lab->setAuthor($user['username']);
							error_log('[scanLabs] Author set to ' . $user['username'] . ' for file: ' . $file);

							// Сохраняем изменения для данной лаборатории
							$rc = $lab->save();
							if ($rc !== 0) {
								error_log('[scanLabs] Failed to save lab (first save) for file: ' . $file . ' Error code: ' . $rc);
								continue;  // переход к следующему файлу
							}
							error_log('[scanLabs] First save successful for file: ' . $file);

							// Пересчитываем и обновляем идентификаторы для данной лаборатории
							$rc = $lab->updatePrivateIds();
							if ($rc !== 0) {
								error_log('[scanLabs] Failed to update lab IDs for file: ' . $file . ' Error code: ' . $rc);
								continue;
							}
							error_log('[scanLabs] updatePrivateIds successful for file: ' . $file);

							// Сохраняем лабораторию с обновлёнными ID
							$rc = $lab->save();
							if ($rc !== 0) {
								error_log('[scanLabs] Failed to save lab (second save) for file: ' . $file . ' Error code: ' . $rc);
								continue;
							}
							error_log('[scanLabs] Second save successful for file: ' . $file);

							// Дополнительная логика для импортированных лаб (если необходимо)
							error_log('[scanLabs] Completed processing lab for file: ' . $file);
						} catch (Exception $e) {
							error_log('[scanLabs] Exception while processing file ' . $file . ': ' . $e->getMessage());
							// Пропускаем невалидные lab файлы
							continue;
						}
					} else {
						error_log('[scanLabs] File ' . $file . ' not found in importedFiles list. Skipping.');
					}
				}
			}
			error_log('[scanLabs] Finished scanning directory: ' . $dir);
		}

		// Инициализируем переменную, если она не определена
		$allNodes = array();
		scanLabs($destPath, $allNodes, $user, $importedFiles);

		error_log('[apiImportLabs] Import completed successfully');
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][80080];

		return $output;
	} else {
		error_log('[apiImportLabs] File is not a zip archive');
		// File is not a Zip
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][80078];
		return $output;
	}
}

/*
 * Function to move a lab inside another folder.
 *
 * @param	Lab			$lab			Lab
 * @param	string		$path			Destination path
 * @return	Array						Return code (JSend data)
 */
function apiMoveLab($lab, $path)
{
	$rc = checkFolder(BASE_LAB . $path);
	if ($rc === 2) {
		// Folder is not valid
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60009];
		return $output;
	} else if ($rc === 1) {
		// Folder does not exist
		$output['code'] = 404;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60008];
		return $output;
	}

	if (is_file(BASE_LAB . $path . '/' . $lab->getFilename())) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60016];
		return $output;
	}

	if (rename($lab->getPath() . '/' . $lab->getFilename(), BASE_LAB . $path . '/' . $lab->getFilename())) {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60035];
	} else {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][60034];
		error_log(date('M d H:i:s ') . 'ERROR: ' . $GLOBALS['messages'][60034]);
	}
	return $output;
}

/*
 * Function to Lock  a lab 
 *
 * @param       Lab                     $lab                    Lab
 * @return      Array                                           Return code (JSend data)
 */

function apiLockLab($lab)
{
	$rc = $lab->lockLab();
	if ($rc !== 0) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60023];
	}
	return $output;
}

/*
 * Function to Unlock  a lab
 *
 * @param       Lab                     $lab                    Lab
 * @return      Array                                           Return code (JSend data)
 */

function apiUnlockLab($lab)
{
	$rc = $lab->unlockLab();
	if ($rc !== 0) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][60023];
	}
	return $output;
}

/**
 * Function for update shared settings
 * 
 * @param Lab $lab Lab
 * @param string $sharedWith users line
 * @return array ...
 */
function apiEditLabSharedWith(Lab $lab, string $sharedWith)
{
	$lab->setSharedWith($sharedWith);

	$rc = $lab->save();

	if ($rc !== 0) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = "Shared access updated";
	}

	return $output;
}

function apiEditLabShared(Lab $lab, bool $shared)
{
	$lab->setShared($shared);

	$rc = $lab->save();

	if ($rc !== 0) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = "Shared updated";
	}

	return $output;
}

function apiEditLabCollaborateAllowed(Lab $lab, bool $collaborateAllowed)
{
	$lab->setCollaborateAllowed($collaborateAllowed);

	$rc = $lab->save();

	if ($rc !== 0) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][$rc];
	} else {
		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = "Collaborate allowed updated";
	}

	return $output;
}

/**
 * Wrapper function to clone a lab for private use using the existing cloning mechanism.
 *
 * @param string $originalRelative      The relative path of the source lab (e.g., "/shared/lab1.unl")
 * @param string $destinationRelative   The relative path of the private copy to be created (e.g., "/shared/lab1_alice.unl")
 * @param array  $user                  The current user information array (must include tenant and username)
 *
 * @return array Returns an associative array with a code, status, and message.
 */
function apiCloneLabPrivate($originalRelative, $destinationRelative, $user)
{
	// Prepare parameters for the existing clone:
	$params = [];
	$params['source'] = $originalRelative;
	// Generate the name for the private copy by appending '_' and the username.
	$basename = basename($originalRelative, '.unl');
	$params['name'] = $basename . '_' . $user['username'];

	// Call the existing clone function.
	$result = apiCloneLab($params, $user['tenant'], $user['username']);

	// Define the full path to the private lab.
	$privateLabPath = BASE_LAB . dirname($originalRelative) . '/' . $params['name'] . '.unl';

	try {
		// Load the cloned lab.
		$privateLab = new Lab($privateLabPath, $user['tenant'], $user['username']);
	} catch (Exception $e) {
		return [
			'code' => 400,
			'status' => 'fail',
			'message' => 'Error loading private lab: ' . $e->getMessage()
		];
	}

	// Set properties for the private lab.
	$privateLab->setIsMirror();            // Set as mirror (private copy)
	$privateLab->setShared(false);           // Disable shared access
	$privateLab->setSharedWith('');          // Clear shared users list
	$privateLab->setTenant($user['tenant']); // Update tenant to current user's tenant
	$privateLab->setAuthor($user['username']); // Update author to current username

	// Save changes before updating IDs.
	$rc = $privateLab->save();
	if ($rc !== 0) {
		return [
			'code' => 400,
			'status' => 'fail',
			'message' => $GLOBALS['messages'][$rc]
		];
	}

	try {
		// Update node and network IDs to new private values.
		$rc = $privateLab->updatePrivateIds();
	} catch (Exception $e) {
		// If updatePrivateIds fails, remove the cloned lab file.
		if (file_exists($privateLabPath)) {
			@unlink($privateLabPath);
		}
		return [
			'code' => 400,
			'status' => 'fail',
			'message' => $e->getMessage()
		];
	}

	if ($rc !== 0) {
		return [
			'code' => 400,
			'status' => 'fail',
			'message' => $GLOBALS['messages'][$rc]
		];
	}

	// Save the lab with updated IDs.
	$rc = $privateLab->save();
	if ($rc !== 0) {
		return [
			'code' => 400,
			'status' => 'fail',
			'message' => $GLOBALS['messages'][$rc]
		];
	}

	return $result;
}
