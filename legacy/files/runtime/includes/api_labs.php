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

if (!defined('LAB_WORK_SUFFIX')) {
	define('LAB_WORK_SUFFIX', '__work');
}

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
function apiDeleteLab(Lab $lab, $user, $db)
{
	$tenant = $lab->getTenant();
	$author = $lab->getAuthor();
	$lab_file = $lab->getPath() . '/' . $lab->getFilename();

	// If lab was shared, remove shared copies and related artifacts from each recipient
	$sharedUsers = normalizeSharedUsers($lab->getSharedWith());
	if (!empty($sharedUsers)) {
		foreach ($sharedUsers as $sharedUser) {
			if ($sharedUser === $user['username']) {
				continue;
			}
			removeSharedArtifactsForUser($lab, $sharedUser, $db);
		}
	}

	apiStopLabNodes($lab, $tenant, $user['username']);

	$html5_db = html5_checkDatabase();
	$nodes = $lab->getNodes();
	foreach ($nodes as $node) {
		$name = $node->getName() . '_' . $node->getId() . '_' . $lab->getAuthor();
		error_log($name);
		html5DeleteSession($html5_db, $name);
	}
	
		$cmd = 'sudo ' . escapeshellarg(unlWrapperPath());
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
		'collaborateAllowed' => $lab->getCollaborateAllowed(),
		'mirrorPath' => $lab->getMirrorPath(),
		'updatedAt' => @filemtime($lab->getPath() . '/' . $lab->getFilename()) ?: time()
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
	if ($shared === false && $lab->getCollaborateAllowed()) {
		$lab->setCollaborateAllowed(false);
	}

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
	if ($collaborateAllowed && !$lab->getShared()) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = 'Collaborate mode requires the lab to be shared.';
		return $output;
	}

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

function normalizeSharedUsers($sharedWith)
{
	if (!is_string($sharedWith) || trim($sharedWith) === '') {
		return array();
	}
	$parts = explode(',', $sharedWith);
	$result = array();
	foreach ($parts as $part) {
		$val = trim($part);
		if ($val !== '' && !in_array($val, $result, true)) {
			$result[] = $val;
		}
	}
	return $result;
}

function ensureSharedCopyForUser($lab, $targetUser, $db)
{
	$targetUser = trim((string) $targetUser);
	if ($targetUser === '') {
		return;
	}
	ensureUserSharedDirectory($targetUser);

	$destDir = BASE_LAB . '/' . $targetUser . '/Shared';
	$destFile = $destDir . '/' . $lab->getFilename();
	$srcFile = $lab->getPath() . '/' . $lab->getFilename();

	@copy($srcFile, $destFile);

	try {
		$sharedLab = new Lab($destFile, $lab->getTenant(), $targetUser, false);
		$sharedLab->setShared(false);
		$sharedLab->setSharedWith('');
		$sharedLab->setIsMirror();
		$sourceRelative = str_replace(BASE_LAB, '', $srcFile);
		$sharedLab->setMirrorPath($sourceRelative);
		$sharedLab->setAuthor($lab->getAuthor());
		$sharedLab->setTenant($lab->getTenant());
		$sharedLab->save();
	} catch (Exception $e) {
		error_log('[ensureSharedCopyForUser] Failed to prepare shared copy for ' . $targetUser . ': ' . $e->getMessage());
	}
}

function removeSharedArtifactsForUser($lab, $targetUser, $db)
{
	$targetUser = trim((string) $targetUser);
	if ($targetUser === '') {
		return;
	}
	$destDir = BASE_LAB . '/' . $targetUser . '/Shared';
	$destFile = $destDir . '/' . $lab->getFilename();

	// Stop running nodes in the shared copy for this user
	if (is_file($destFile)) {
		try {
			$sharedLab = new Lab($destFile, $lab->getTenant(), $targetUser, false);
			$sharedTenant = $sharedLab->getTenant() ?: getUserPodByUsername($db, $targetUser);
			if ($sharedTenant) {
				apiStopLabNodes($sharedLab, $sharedTenant, $targetUser);
				$sharedLabId = $sharedLab->getId();
				if ($sharedLabId) {
					$sharedTmp = '/opt/unetlab/tmp/' . $sharedTenant . '/' . $sharedLabId;
					rrmdir($sharedTmp);
					if (is_dir($sharedTmp)) {
						deleteTmpViaWrapper($destFile, $sharedTenant, $targetUser);
					}
				}
			}
		} catch (Exception $e) {
			// ignore stop errors, continue cleanup
		}
	}

	if (is_file($destFile)) {
		@unlink($destFile);
	}

	// Remove work copy and tmp directory if exist
	$workFile = $destDir . '/' . basename($lab->getFilename(), '.unl') . LAB_WORK_SUFFIX . '.unl';
	if (is_file($workFile)) {
		try {
			$workLab = new Lab($workFile, $lab->getTenant(), $targetUser, false);
			$targetPod = $workLab->getTenant() ?: getUserPodByUsername($db, $targetUser);
			if ($targetPod) {
				apiStopLabNodes($workLab, $targetPod, $targetUser);
				$workLabId = $workLab->getId();
				if ($workLabId) {
					$destTmp = '/opt/unetlab/tmp/' . $targetPod . '/' . $workLabId;
					rrmdir($destTmp);
					if (is_dir($destTmp)) {
						deleteTmpViaWrapper($workFile, $targetPod, $targetUser);
					}
				}
			}
		} catch (Exception $e) {
			// ignore
		}
		@unlink($workFile);
	}
}

function syncSharedLabCopies($lab, $prevShared, $prevSharedWith, $db, $currentUser)
{
	$currentShared = $lab->getShared();
	$currentSharedWith = $lab->getSharedWith();

	$prevList = ($prevShared) ? normalizeSharedUsers($prevSharedWith) : array();
	$newList = ($currentShared) ? normalizeSharedUsers($currentSharedWith) : array();

	$removed = array_diff($prevList, $newList);
	$added = $newList;

	foreach ($added as $username) {
		if ($username === $currentUser['username']) {
			continue;
		}
		ensureSharedCopyForUser($lab, $username, $db);
	}

	foreach ($removed as $username) {
		removeSharedArtifactsForUser($lab, $username, $db);
	}

	// If sharing disabled entirely, remove from everyone in prev list
	if (!$currentShared) {
		foreach ($prevList as $username) {
			removeSharedArtifactsForUser($lab, $username, $db);
		}
	}
}

/**
 * Force-refresh shared lab copies for every recipient.
 *
 * @param Lab $lab
 * @param mixed $db
 * @param array $currentUser
 * @return void
 */
function refreshSharedLabCopies($lab, $db, $currentUser)
{
	if (!$lab->getShared()) {
		return;
	}

	$sharedUsers = normalizeSharedUsers($lab->getSharedWith());
	if (empty($sharedUsers)) {
		return;
	}

	foreach ($sharedUsers as $username) {
		if ($username === $currentUser['username']) {
			continue;
		}
		ensureSharedCopyForUser($lab, $username, $db);
	}
}

function buildWorkLabPaths($user, $relativePath, $absolutePath)
{
	$info = pathinfo($absolutePath);
	$workFilename = $info['filename'] . LAB_WORK_SUFFIX . '.unl';
	$absoluteWork = $info['dirname'] . '/' . $workFilename;

	$relative = normalizeUserLabRelativePath($relativePath);
	$relInfo = pathinfo($relative);
	$relDir = isset($relInfo['dirname']) ? $relInfo['dirname'] : '/';
	if ($relDir === '.') {
		$relDir = '/';
	}
	$relativeWork = ($relDir === '/' ? '' : $relDir) . '/' . $workFilename;

	return array(
		'absolute_work' => $absoluteWork,
		'relative_work' => $relativeWork
	);
}

function getDirectorySizeBytes($src)
{
	if (!is_dir($src)) {
		return 0;
	}
	$items = scandir($src);
	if ($items === false) {
		return 0;
	}
	$total = 0;
	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$path = $src . '/' . $item;
		if (is_link($path)) {
			continue;
		}
		if (is_dir($path)) {
			$total += getDirectorySizeBytes($path);
		} else {
			$size = @filesize($path);
			if ($size !== false) {
				$total += $size;
			}
		}
	}
	return $total;
}

function publishCopyProgress(&$state, $progressCallback, $force = false)
{
	if (!$progressCallback || !is_callable($progressCallback)) {
		return;
	}
	$total = isset($state['total']) ? (int) $state['total'] : 0;
	if ($total <= 0) {
		return;
	}
	$copied = isset($state['copied']) ? (int) $state['copied'] : 0;
	$percent = (int) floor(($copied / $total) * 100);
	$capPercent = isset($state['cap_percent']) ? (int) $state['cap_percent'] : 100;
	if ($percent > 100) {
		$percent = 100;
	}
	if ($percent > $capPercent) {
		$percent = $capPercent;
	}
	$now = microtime(true);
	$lastPercent = isset($state['last_percent']) ? (int) $state['last_percent'] : -1;
	$lastUpdate = isset($state['last_update']) ? (float) $state['last_update'] : 0;
	if ($force || ($percent !== $lastPercent && ($now - $lastUpdate) >= 0.5)) {
		$state['last_percent'] = $percent;
		$state['last_update'] = $now;
		call_user_func($progressCallback, $percent);
	}
}

function copyDirectoryWithProgress($src, $dst, $ownerUser, $ownerGroup, &$progressState, $progressCallback)
{
	if (!is_dir($src)) {
		return false;
	}
	if (!is_dir($dst)) {
		$parent = dirname($dst);
		if (!is_dir($parent)) {
			@mkdir($parent, 0777, true);
		}
		if (!@mkdir($dst, 0777, true)) {
			error_log('[copyDirectoryWithProgress] mkdir failed: ' . $dst);
			return false;
		}
		@chown($dst, $ownerUser);
		@chgrp($dst, $ownerGroup);
	}
	$items = scandir($src);
	if ($items === false) {
		return false;
	}
	$result = true;
	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$srcPath = $src . '/' . $item;
		$dstPath = $dst . '/' . $item;
		if (is_link($srcPath)) {
			$target = readlink($srcPath);
			if (file_exists($dstPath) || is_link($dstPath)) {
				@unlink($dstPath);
			}
			$tmpRes = @symlink($target, $dstPath);
			if ($tmpRes === false) {
				error_log('[copyDirectoryWithProgress] symlink failed: ' . $dstPath . ' -> ' . $target);
			}
			$result = ($tmpRes !== false) && $result;
		} else if (is_dir($srcPath)) {
			$tmpRes = copyDirectoryWithProgress($srcPath, $dstPath, $ownerUser, $ownerGroup, $progressState, $progressCallback);
			if ($tmpRes === false) {
				error_log('[copyDirectoryWithProgress] recurse failed: ' . $srcPath . ' -> ' . $dstPath);
			}
			$result = ($tmpRes !== false) && $result;
		} else {
			$tmpRes = @copy($srcPath, $dstPath);
			if ($tmpRes === false) {
				error_log('[copyDirectoryWithProgress] copy failed: ' . $srcPath . ' -> ' . $dstPath);
			} else {
				$size = @filesize($srcPath);
				if ($size !== false && $size > 0) {
					$progressState['copied'] += $size;
					publishCopyProgress($progressState, $progressCallback);
				}
			}
			$result = ($tmpRes !== false) && $result;
		}
		if (is_file($dstPath) || is_link($dstPath)) {
			@chown($dstPath, $ownerUser);
			@chgrp($dstPath, $ownerGroup);
		}
	}
	return $result;
}

function copyDirectory($src, $dst, $ownerUser = 'www-data', $ownerGroup = 'unl')
{
	if (!is_dir($src)) {
		return false;
	}
	if (!is_dir($dst)) {
		$parent = dirname($dst);
		if (!is_dir($parent)) {
			@mkdir($parent, 0777, true);
		}
		if (!@mkdir($dst, 0777, true)) {
			error_log('[copyDirectory] mkdir failed: ' . $dst);
			return false;
		}
		@chown($dst, $ownerUser);
		@chgrp($dst, $ownerGroup);
	}
	$items = scandir($src);
	if ($items === false) {
		return false;
	}
	$result = true;
	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$srcPath = $src . '/' . $item;
		$dstPath = $dst . '/' . $item;
		if (is_link($srcPath)) {
			$target = readlink($srcPath);
			if (file_exists($dstPath) || is_link($dstPath)) {
				@unlink($dstPath);
			}
			$tmpRes = @symlink($target, $dstPath);
			if ($tmpRes === false) {
				error_log('[copyDirectory] symlink failed: ' . $dstPath . ' -> ' . $target);
			}
			$result = ($tmpRes !== false) && $result;
		} else if (is_dir($srcPath)) {
			$tmpRes = copyDirectory($srcPath, $dstPath, $ownerUser, $ownerGroup);
			if ($tmpRes === false) {
				error_log('[copyDirectory] recurse failed: ' . $srcPath . ' -> ' . $dstPath);
			}
			$result = ($tmpRes !== false) && $result;
		} else {
			$tmpRes = @copy($srcPath, $dstPath);
			if ($tmpRes === false) {
				error_log('[copyDirectory] copy failed: ' . $srcPath . ' -> ' . $dstPath);
			}
			$result = ($tmpRes !== false) && $result;
		}
		if (is_file($dstPath) || is_link($dstPath)) {
			@chown($dstPath, $ownerUser);
			@chgrp($dstPath, $ownerGroup);
		}
	}
	return $result;
}

function copyDirectoryShellFallback($src, $dst, $ownerUser = 'www-data', $ownerGroup = 'unl')
{
	$srcArg = escapeshellarg(rtrim($src, '/'));
	$dstArg = escapeshellarg(rtrim($dst, '/'));
	$owner = escapeshellarg($ownerUser . ':' . $ownerGroup);
	$cmds = array(
		"/bin/mkdir -p $dstArg",
		"/bin/cp -a $srcArg/. $dstArg/",
		"/bin/chown -R $owner $dstArg"
	);

	foreach ($cmds as $cmd) {
		$output = array();
		$rc = 0;
		@exec($cmd . ' 2>&1', $output, $rc);
		if ($rc !== 0) {
			error_log('[copyDirectoryShellFallback] failed cmd: ' . $cmd . ' rc=' . $rc . ' out=' . implode(';', $output));
			return false;
		}
	}
	return true;
}

function renameNodeArtifacts($nodeDir, $oldId, $newId, $ownerUser, $ownerGroup)
{
	if (!is_dir($nodeDir)) {
		return;
	}
	if ((int) $oldId === (int) $newId) {
		return;
	}
	$oldSuffix = sprintf('%05d', $oldId);
	$newSuffix = sprintf('%05d', $newId);
	$patterns = array(
		'nvram_%s',
		'vlan.dat-%s',
		'vlan.dat_%s'
	);
	foreach ($patterns as $pattern) {
		$oldFile = $nodeDir . '/' . sprintf($pattern, $oldSuffix);
		$newFile = $nodeDir . '/' . sprintf($pattern, $newSuffix);
		if (is_file($oldFile)) {
			if (is_file($newFile)) {
				@unlink($newFile);
			}
			if (!renameWithShellFallback($oldFile, $newFile, $ownerUser, $ownerGroup)) {
				error_log('[renameNodeArtifacts] failed rename: ' . $oldFile . ' -> ' . $newFile);
			}
		}
	}
}

function remapWorkLabClouds($lab, $username)
{
	$available = array_keys(listNetworkTypes($username));
	if (empty($available)) {
		return;
	}
	$available = array_values($available);
	sort($available);
	$assigned = array();
	$index = 0;
	foreach ($lab->getNetworks() as $network) {
		$type = $network->getNType();
		if (!preg_match('/^pnet[0-9]+$/', $type)) {
			continue;
		}
		if (in_array($type, $available, true)) {
			continue;
		}
		if (isset($assigned[$type])) {
			$target = $assigned[$type];
		} else {
			$target = $available[$index % count($available)];
			$assigned[$type] = $target;
			$index++;
		}
		$network->edit(array(
			'type' => $target,
			'username' => $username,
			'visibility' => 1
		));
	}
}

function renameWithShellFallback($src, $dst, $ownerUser = 'www-data', $ownerGroup = 'unl')
{
	if (@rename($src, $dst)) {
		return true;
	}

	$srcArg = escapeshellarg($src);
	$dstArg = escapeshellarg($dst);
	$owner = escapeshellarg($ownerUser . ':' . $ownerGroup);

	$cmds = array(
		"/bin/mkdir -p " . escapeshellarg(dirname($dst)),
		"/bin/mv $srcArg $dstArg",
		"/bin/chown -R $owner $dstArg"
	);

	foreach ($cmds as $cmd) {
		$output = array();
		$rc = 0;
		@exec($cmd . ' 2>&1', $output, $rc);
		if ($rc !== 0) {
			error_log('[renameWithShellFallback] failed cmd: ' . $cmd . ' rc=' . $rc . ' out=' . implode(';', $output));
			return false;
		}
	}

	return true;
}

function copyTmpViaWrapper($sourceTmp, $destTmp, $targetPod)
{
	$cmd = '/usr/bin/sudo ' . escapeshellarg(unlWrapperPath()) . ' -a copytmp';
	$cmd .= ' -T ' . escapeshellarg($targetPod);
	$cmd .= ' -o ' . escapeshellarg($sourceTmp);
	$cmd .= ' -F ' . escapeshellarg($destTmp);
	$output = array();
	$rc = 0;
	@exec($cmd . ' 2>&1', $output, $rc);
	if ($rc !== 0) {
		error_log('[copyTmpViaWrapper] failed cmd: ' . $cmd . ' rc=' . $rc . ' out=' . implode(';', $output));
		return false;
	}
	return true;
}

function deleteTmpViaWrapper($workFile, $targetPod, $username)
{
	$cmd = '/usr/bin/sudo ' . escapeshellarg(unlWrapperPath()) . ' -a delete';
	$cmd .= ' -T ' . escapeshellarg($targetPod);
	$cmd .= ' -U ' . escapeshellarg($username);
	$cmd .= ' -F ' . escapeshellarg($workFile);
	$output = array();
	$rc = 0;
	@exec($cmd . ' 2>&1', $output, $rc);
	if ($rc !== 0) {
		error_log('[deleteTmpViaWrapper] failed cmd: ' . $cmd . ' rc=' . $rc . ' out=' . implode(';', $output));
		return false;
	}
	return true;
}

function renameTmpViaWrapper($src, $dst, $targetPod)
{
	$cmd = '/usr/bin/sudo ' . escapeshellarg(unlWrapperPath()) . ' -a renametmp';
	$cmd .= ' -T ' . escapeshellarg($targetPod);
	$cmd .= ' -o ' . escapeshellarg($src);
	$cmd .= ' -F ' . escapeshellarg($dst);
	$output = array();
	$rc = 0;
	@exec($cmd . ' 2>&1', $output, $rc);
	if ($rc !== 0) {
		error_log('[renameTmpViaWrapper] failed cmd: ' . $cmd . ' rc=' . $rc . ' out=' . implode(';', $output));
		return false;
	}
	return true;
}

function apiCreateWorkLab($db, $user, $absoluteLabPath, $relativePath, $action = 'start', $progressCallback = null)
{
	if (!is_file($absoluteLabPath)) {
		return array('code' => 404, 'status' => 'fail', 'message' => 'Lab file not found');
	}

	try {
		$sourceLab = new Lab($absoluteLabPath, $user['tenant'], $user['username'], false);
	} catch (Exception $e) {
		return array('code' => 400, 'status' => 'fail', 'message' => 'Invalid lab file');
	}

	$paths = buildWorkLabPaths($user, $relativePath, $absoluteLabPath);
	$workFile = $paths['absolute_work'];

	$targetPod = getUserPodByUsername($db, $user['username']);
	if ($targetPod === false) {
		return array('code' => 400, 'status' => 'fail', 'message' => 'Cannot resolve user POD');
	}

	$copyDebug = array(
		'action' => $action,
		'source_tmp' => '',
		'dest_tmp' => '',
		'source_exists' => false,
		'copy_ok' => false
	);
	$progressState = null;

	// Reset existing work copy if needed
	if ($action === 'reset' && is_file($workFile)) {
		try {
			$existingWork = new Lab($workFile, $targetPod, $user['username'], false);
			// Stop all running nodes before wiping work copy
			$stopResult = apiStopLabNodes($existingWork, $targetPod, $user['username']);
			if (!isset($stopResult['status']) || $stopResult['status'] !== 'success') {
				error_log('[apiCreateWorkLab] failed to stop nodes before reset: ' . json_encode($stopResult));
			}
			$existingId = $existingWork->getId();
			if ($existingId) {
				$tmpPath = '/opt/unetlab/tmp/' . $targetPod . '/' . $existingId;
				rrmdir($tmpPath);
				if (is_dir($tmpPath)) {
					deleteTmpViaWrapper($workFile, $targetPod, $user['username']);
				}
			}
		} catch (Exception $e) {
			// ignore
		}
		@unlink($workFile);
	}

	// If work already exists and action=start, reuse existing
	if ($action === 'start' && is_file($workFile)) {
		return array(
			'code' => 200,
			'status' => 'success',
			'message' => 'Work copy already exists',
			'data' => array(
				'work_path' => $paths['relative_work'],
				'work_exists' => true,
				'copy_debug' => $copyDebug
			)
		);
	}

	// Copy lab file into work copy
	@copy($absoluteLabPath, $workFile);
	@chown($workFile, 'unl' . $targetPod);
	@chgrp($workFile, 'unl');

	try {
		$workLab = new Lab($workFile, $targetPod, $user['username'], false);
	} catch (Exception $e) {
		return array('code' => 400, 'status' => 'fail', 'message' => 'Cannot load work copy');
	}

	$sourceLabId = $sourceLab->getId();
	if (empty($sourceLabId)) {
		$sourceLab->setId();
		$sourceLab->save();
		$sourceLabId = $sourceLab->getId();
	}

	$workLab->setAuthor($user['username']);
	$workLab->setShared(false);
	$workLab->setSharedWith('');
	$workLab->setCollaborateAllowed(false);
	$workLab->setIsMirror();
	$workLab->setTenant($targetPod);
	$workLab->setId();

	try {
		$nodeMap = $workLab->updatePrivateIdsWithMap();
	} catch (Exception $e) {
		@unlink($workFile);
		$copyDebug['error'] = $e->getMessage();
		return array('code' => 400, 'status' => 'fail', 'message' => $e->getMessage(), 'data' => array('copy_debug' => $copyDebug));
	}

	if (!is_array($nodeMap)) {
		@unlink($workFile);
		$copyDebug['error'] = 'Failed to update node IDs';
		return array('code' => 400, 'status' => 'fail', 'message' => 'Failed to update node IDs', 'data' => array('copy_debug' => $copyDebug));
	}

	remapWorkLabClouds($workLab, $user['username']);

	$rc = $workLab->save();
	if ($rc !== 0) {
		@unlink($workFile);
		return array('code' => 400, 'status' => 'fail', 'message' => $GLOBALS['messages'][$rc]);
	}

	$destLabId = $workLab->getId();
	$sourcePod = $sourceLab->getTenant();
	if ($sourcePod === false || $sourcePod === null || $sourcePod < 0) {
		$sourcePod = getUserPodByUsername($db, $sourceLab->getAuthor());
	}
	if ($sourcePod === false) {
		// Still return success with work file only
		return array(
			'code' => 200,
			'status' => 'success',
			'message' => 'Work copy created (no source pod data)',
			'data' => array(
				'work_path' => $paths['relative_work'],
				'work_exists' => true
			)
		);
	}

	$sourceTmp = '/opt/unetlab/tmp/' . $sourcePod . '/' . $sourceLabId;
	$destTmp = '/opt/unetlab/tmp/' . $targetPod . '/' . $destLabId;
	$copyDebug['source_tmp'] = $sourceTmp;
	$copyDebug['dest_tmp'] = $destTmp;
	$copyDebug['source_exists'] = is_dir($sourceTmp);

	if ($sourceLab->getIsMirror() && $sourceLab->getMirrorPath()) {
		$mirrorRelative = normalizeUserLabRelativePath($sourceLab->getMirrorPath());
		$mirrorAbsolute = BASE_LAB . $mirrorRelative;
		if (is_file($mirrorAbsolute)) {
			try {
				$ownerPod = getUserPodByUsername($db, $sourceLab->getAuthor());
				if ($ownerPod !== false) {
					$mirrorLab = new Lab($mirrorAbsolute, $ownerPod, $sourceLab->getAuthor(), false);
					$mirrorLabId = $mirrorLab->getId();
					$mirrorPod = $mirrorLab->getTenant();
					if (!empty($mirrorLabId) && $mirrorPod !== false) {
						$sourcePod = $mirrorPod;
						$sourceLabId = $mirrorLabId;
						$sourceTmp = '/opt/unetlab/tmp/' . $sourcePod . '/' . $sourceLabId;
						$copyDebug['source_tmp'] = $sourceTmp;
						$copyDebug['source_exists'] = is_dir($sourceTmp);
						$copyDebug['mirror_source'] = true;
					}
				}
			} catch (Exception $e) {
				$copyDebug['mirror_source'] = false;
				$copyDebug['mirror_error'] = $e->getMessage();
			}
		}
	}

	error_log('[apiCreateWorkLab] action=' . $action . ' copy tmp from ' . $sourceTmp . ' to ' . $destTmp);

	$labSize = is_file($absoluteLabPath) ? (int) @filesize($absoluteLabPath) : 0;
	$totalBytes = $labSize;
	if (is_dir($sourceTmp)) {
		$totalBytes += getDirectorySizeBytes($sourceTmp);
	}
	if (is_callable($progressCallback) && $totalBytes > 0) {
		$progressState = array(
			'total' => $totalBytes,
			'copied' => max(0, $labSize),
			'last_percent' => -1,
			'last_update' => 0,
			'cap_percent' => 99
		);
		publishCopyProgress($progressState, $progressCallback, true);
	}

	if (is_dir($destTmp)) {
		rrmdir($destTmp);
	}
	if (!is_dir(dirname($destTmp))) {
		@mkdir(dirname($destTmp), 0777, true);
		@chown(dirname($destTmp), 'unl' . $targetPod);
		@chgrp(dirname($destTmp), 'unl');
	}

	if (is_dir($sourceTmp)) {
		if ($progressState !== null && is_callable($progressCallback)) {
			$copied = copyDirectoryWithProgress($sourceTmp, $destTmp, 'unl' . $targetPod, 'unl', $progressState, $progressCallback);
		} else {
			$copied = copyDirectory($sourceTmp, $destTmp, 'unl' . $targetPod, 'unl');
		}
		if (!$copied) {
			error_log('[apiCreateWorkLab] fallback copy via shell');
			if ($progressState !== null && is_callable($progressCallback)) {
				$progressState['copied'] = (int) floor($progressState['total'] * 0.95);
				publishCopyProgress($progressState, $progressCallback, true);
			}
			$copied = copyTmpViaWrapper($sourceTmp, $destTmp, $targetPod);
		}
		if (!$copied) {
			error_log('[apiCreateWorkLab] fallback copy via shell cp');
			if ($progressState !== null && is_callable($progressCallback)) {
				$progressState['copied'] = (int) floor($progressState['total'] * 0.95);
				publishCopyProgress($progressState, $progressCallback, true);
			}
			$copied = copyDirectoryShellFallback($sourceTmp, $destTmp, 'unl' . $targetPod, 'unl');
		}
		$copyDebug['copy_ok'] = $copied ? true : false;
		error_log('[apiCreateWorkLab] copy result: ' . ($copied ? 'ok' : 'fail'));
		// Rename node directories to temp_* first to avoid collisions, then to new IDs
		$staged = array();
		foreach ($nodeMap as $oldId => $newId) {
			$oldDir = $destTmp . '/' . $oldId;
			if (!is_dir($oldDir)) {
				continue;
			}
			$tmpDir = $destTmp . '/temp_' . $oldId;
			if (is_dir($tmpDir)) {
				rrmdir($tmpDir);
			}
			$renamed = renameWithShellFallback($oldDir, $tmpDir, 'unl' . $targetPod, 'unl');
			if (!$renamed) {
				$renamed = renameTmpViaWrapper($oldDir, $tmpDir, $targetPod);
			}
			if (!$renamed) {
				error_log('[apiCreateWorkLab] staging rename failed: ' . $oldDir . ' -> ' . $tmpDir);
				continue;
			}
			$staged[] = array(
				'old_id' => $oldId,
				'new_id' => $newId,
				'tmp_dir' => $tmpDir,
				'new_dir' => $destTmp . '/' . $newId
			);
		}

		foreach ($staged as $entry) {
			$tmpDir = $entry['tmp_dir'];
			$newDir = $entry['new_dir'];
			$renamed = renameWithShellFallback($tmpDir, $newDir, 'unl' . $targetPod, 'unl');
			if (!$renamed) {
				$renamed = renameTmpViaWrapper($tmpDir, $newDir, $targetPod);
			}
			if (!$renamed) {
				error_log('[apiCreateWorkLab] rename failed: ' . $tmpDir . ' -> ' . $newDir);
				continue;
			}
			renameNodeArtifacts($newDir, $entry['old_id'], $entry['new_id'], 'unl' . $targetPod, 'unl');
		}
	} else {
		error_log('[apiCreateWorkLab] source tmp dir not found: ' . $sourceTmp);
	}
	if ($progressState !== null && is_callable($progressCallback)) {
		$progressState['cap_percent'] = 100;
		$progressState['copied'] = $progressState['total'];
		publishCopyProgress($progressState, $progressCallback, true);
	}

	return array(
		'code' => 200,
		'status' => 'success',
		'message' => 'Work copy created',
		'data' => array(
			'work_path' => $paths['relative_work'],
			'work_exists' => true,
			'copy_debug' => $copyDebug
		)
	);
}
