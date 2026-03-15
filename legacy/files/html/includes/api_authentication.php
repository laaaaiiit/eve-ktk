<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/includes/api_authentication.php
 *
 * Users related functions for REST APIs.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @copyright fork 2025 Nikita Hochckov https://github.com/laaaaiiit
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

/*
 * Function to login a user.
 *
 * @param	PDO			$db				PDO object for database connection
 * @param	Array		$p				Parameters
 * @param	String		$cookie			Session cookie
 * @return	bool						True if valid
 */
function apiLogin($db, $html5_db, $p, $cookie)
{
	if (!isset($p['username'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][90011];
		return $output;
	} else {
		$username = $p['username'];
	}

	if (!isset($p['password'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = $GLOBALS['messages'][90012];
		return $output;
	}
	$plainPassword = $p['password'];

	if (!isset($p['html5'])) $p['html5'] = 1;

	$query = 'SELECT username, role, password FROM users WHERE username = :username LIMIT 1;';
	$statement = $db->prepare($query);
	$statement->bindParam(':username', $username, PDO::PARAM_STR);
	$statement->execute();
	$result = $statement->fetch(PDO::FETCH_ASSOC);

	if ($result) {
		$storedHash = $result['password'];
		$passwordValid = password_verify($plainPassword, $storedHash);
		$legacyHashMatch = False;
		if (!$passwordValid && hash('sha256', $plainPassword) === $storedHash) {
			$passwordValid = True;
			$legacyHashMatch = True;
		}

		if (!$passwordValid) {
			$output['code'] = 400;
			$output['status'] = 'fail';
			$output['message'] = $GLOBALS['messages'][90014];
			return $output;
		}

		if ($legacyHashMatch || password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
			$newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
			$rehashStatement = $db->prepare('UPDATE users SET password = :password WHERE username = :username;');
			$rehashStatement->bindParam(':password', $newHash, PDO::PARAM_STR);
			$rehashStatement->bindParam(':username', $username, PDO::PARAM_STR);
			$rehashStatement->execute();
		}

		// User/Password match
		if (checkUserExpiration($db, $username) === False) {
			$output['code'] = 401;
			$output['status'] = 'unauthorized';
			$output['message'] = $GLOBALS['messages'][90018];
			return $output;
		}

		// UNetLab is running in multi-user mode
		$rc = configureUserPod($db, $username);
		if ($rc !== 0) {
			// Cannot configure a POD
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
			return $output;
		}

		$rc = updateUserCookie($db, $username, $cookie);
		if ($rc !== 0) {
			// Cannot update user cookie
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
			return $output;
		}

		$role = isset($result['role']) ? $result['role'] : 'user';

		if ($p['html5'] == 1) {
			//enable on database
			$statement = $db->prepare('UPDATE users SET html5 = 1 WHERE username = :username;');
			$statement->bindParam(':username', $username, PDO::PARAM_STR);
			$statement->execute();

			$statement = $db->prepare('SELECT id FROM pods WHERE username = :username;');
			$statement->bindParam(':username', $username, PDO::PARAM_STR);
			$statement->execute();
			$result = $statement->fetch();
			$pod = isset($result['id']) ? (int) $result['id'] : null;
			$guacUserId = $pod !== null ? $pod + 1000 : null;

			if ($guacUserId !== null) {
				$statement = $html5_db->prepare('DELETE FROM guacamole_user WHERE user_id = :user_id;');
				$statement->bindParam(':user_id', $guacUserId, PDO::PARAM_INT);
				$statement->execute();

				$statement = $html5_db->prepare('DELETE FROM guacamole_entity WHERE entity_id = :entity_id;');
				$statement->bindParam(':entity_id', $guacUserId, PDO::PARAM_INT);
				$statement->execute();

				$statement = $html5_db->prepare('REPLACE INTO guacamole_entity(entity_id, name, type) VALUES (:entity_id, :name, :type);');
				$statement->bindParam(':entity_id', $guacUserId, PDO::PARAM_INT);
				$statement->bindParam(':name', $username, PDO::PARAM_STR);
				$type = 'USER';
				$statement->bindParam(':type', $type, PDO::PARAM_STR);
				$statement->execute();

				$passwordSalt = function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32);
				$hexSalt = strtoupper(bin2hex($passwordSalt));
				$passwordHash = hex2bin(hash('sha256', $plainPassword . $hexSalt));

				$statement = $html5_db->prepare('REPLACE INTO guacamole_user(user_id, entity_id, password_salt, password_hash, password_date ) VALUES  ( :user_id , :entity_id, :password_salt, :password_hash, :password_date);');
				$statement->bindParam(':user_id', $guacUserId, PDO::PARAM_INT);
				$statement->bindParam(':entity_id', $guacUserId, PDO::PARAM_INT);
				$statement->bindParam(':password_salt', $passwordSalt, PDO::PARAM_LOB);
				$statement->bindParam(':password_hash', $passwordHash, PDO::PARAM_LOB);
				$passwordDate = date("Y-m-d H:i:s");
				$statement->bindParam(':password_date', $passwordDate, PDO::PARAM_STR);
				$statement->execute();

				$statement = $html5_db->prepare('DELETE FROM guacamole_user_permission WHERE entity_id = :entity_id ;');
				$statement->bindParam(':entity_id', $guacUserId, PDO::PARAM_INT);
				$statement->execute();
				if ($role === 'admin') {
					$statement = $html5_db->prepare('INSERT INTO guacamole_user_permission (entity_id , affected_user_id , permission ) VALUES (:entity_id, :affected_user_id , :permission );');
					$statement->bindParam(':entity_id', $guacUserId, PDO::PARAM_INT);
					$statement->bindParam(':affected_user_id', $guacUserId, PDO::PARAM_INT);
					$permission = 'ADMINISTER';
					$statement->bindParam(':permission', $permission, PDO::PARAM_STR);
					$statement->execute();

					$statement = $html5_db->prepare('INSERT INTO guacamole_system_permission ( entity_id , permission ) VALUES ( :entity_id , :permission );');
					$statement->bindParam(':entity_id', $guacUserId, PDO::PARAM_INT);
					$statement->bindParam(':permission', $permission, PDO::PARAM_STR);
					$statement->execute();
				}
			}

				$rc = updateUserToken($db, $username, $pod, $plainPassword);
		} else {
			$statement = $db->prepare('SELECT id FROM pods WHERE username = :username;');
			$statement->bindParam(':username', $username, PDO::PARAM_STR);
			$statement->execute();
			$result = $statement->fetch();
			$pod = isset($result['id']) ? (int) $result['id'] : null;
			$guacUserId = $pod !== null ? $pod + 1000 : null;

			$statement = $db->prepare('UPDATE users SET html5 = 0 WHERE username = :username ;');
			$statement->bindParam(':username', $username, PDO::PARAM_STR);
			$statement->execute();

			if ($guacUserId !== null) {
				$statement = $html5_db->prepare('DELETE FROM guacamole_user WHERE user_id = :user_id;');
				$statement->bindParam(':user_id', $guacUserId, PDO::PARAM_INT);
				$statement->execute();
			}
		};

		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = $GLOBALS['messages'][90013];
		return $output;
	}

	// User/Password does not match
	$output['code'] = 400;
	$output['status'] = 'fail';
	$output['message'] = $GLOBALS['messages'][90014];
	return $output;
}

/*
 * Function to logout a user.
 *
 * @param	PDO			$db				PDO object for database connection
 * @param	String		$cookie			Session cookie
 * @return	bool						True if valid
 */
function apiLogout($db, $cookie)
{
	$query = 'UPDATE users SET cookie = NULL, session = NULL WHERE cookie = :cookie;';
	$statement = $db->prepare($query);
	$statement->bindParam(':cookie', $cookie, PDO::PARAM_STR);
	$statement->execute();
	//$result = $statement->fetch();

	$output['code'] = 200;
	$output['status'] = 'success';
	$output['message'] = $GLOBALS['messages'][90019];
	return $output;
}

/*
 * Function to check authorization
 *
 * @param	PDO			$db				PDO object for database connection
 * @param	String		$cookie			Session cookie
 * @return	Array						Username, role, tenant if logged in; JSend data if not authorized
 */
function apiAuthorization($db, $cookie)
{
	$output = array();
	$user = getUserByCookie($db, $cookie);	// This will check session/web/pod expiration too

	if (empty($user)) {
		// Used not logged in
		$output['code'] = 412;
		$output['status'] = 'unauthorized';
		$output['message'] = $GLOBALS['messages']['90001'];
		return array(False, $output);
	} else {
		// User logged in
		$rc = updateUserCookie($db, $user['username'], $cookie);
		if ($rc !== 0) {
			// Cannot update user cookie
			$output['code'] = 500;
			$output['status'] = 'error';
			$output['message'] = $GLOBALS['messages'][$rc];
			return array(False, $output);
		}
	}

	return array($user, False);
}
