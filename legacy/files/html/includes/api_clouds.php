<?php
# vim: syntax=php tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/includes/api_uusers.php
 *
 * UNetLab Users related functions for REST APIs.
 *
 * @author Andrea Dainese <andrea.dainese@gmail.com>
 * @copyright 2014-2016 Andrea Dainese
 * @copyright fork 2025 Nikita Hochckov https://github.com/laaaaiiit
 * @license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE
 * @link http://www.unetlab.com/
 * @version 20160719
 */

/**
 * Function to get a UNetLab clouds.
 *
 * @param	PDO		$db					PDO object for database connection
 * @return  Array						Clouds UNetLab
 */
function listCloudsNew(PDO $db) {
	$query = 'SELECT id, cloudname, username, pnet FROM user_clouds';
	try {
		$statement = $db->prepare($query);
		$statement->execute();
		$results = $statement->fetchAll(PDO::FETCH_ASSOC);
		return $results;
	} catch (Exception $e) {
		return [];
	}
}

// Функция для получения облака по ID
function apiGetCloudById(PDO $db, $id) {
    try {
        $query = 'SELECT id, cloudname, username, pnet FROM user_clouds WHERE id = :id';
        $statement = $db->prepare($query);
        $statement->bindParam(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            return [
                'code' => 200,
                'status' => 'success',
                'data' => $result
            ];
        } else {
            return [
                'code' => 404,
                'status' => 'fail',
                'message' => 'Cloud not found'
            ];
        }
    } catch (Exception $e) {
        return [
            'code' => 500,
            'status' => 'fail',
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Function to add a UNetLab user.
 *
 * @param	PDO		$db					PDO object for database connection
 * @param	Array	$p					Parameters
 * @return  Array                       Return code (JSend data)
 */
function apiAddCloud($db, $p) {
	if (!isset($p['cloudname']) || !isset($p['username']) || !isset($p['pnet'])) {
		$output['code'] = 400;
		$output['status'] = 'fail';
		$output['message'] = "Missing required parameters (cloudname, username, pnet)";
		return $output;
	}

	try {
		$query = 'INSERT INTO user_clouds (cloudname, username, pnet) VALUES (:cloudname, :username, :pnet);';
		$statement = $db->prepare($query);
		$statement->bindParam(':cloudname', $p['cloudname'], PDO::PARAM_STR);
		$statement->bindParam(':username', $p['username'], PDO::PARAM_STR);
		$statement->bindParam(':pnet', $p['pnet'], PDO::PARAM_STR);
		$statement->execute();

		$output['code'] = 201;
		$output['status'] = 'success';
		$output['message'] = 'Cloud successfully added';
	} catch (Exception $e) {
		$output['code'] = 500;
		$output['status'] = 'fail';
		$output['message'] = 'Database error: ' . $e->getMessage();
	}
	return $output;
}

/**
 * Function to delete a UNetLab cloud.
 *
 * @param PDO 		$db 				The PDO object for database connection.
 * @param int 		$id 				The ID of the cloud to be deleted.
 * @return Array 						Returns an associative array containing the status of the operation:
 *
 */
function apiDeleteCloud($db, $id) {
	try {
		$query = 'DELETE FROM user_clouds WHERE id = :id;';
		$statement = $db->prepare($query);
		$statement->bindParam(':id', $id, PDO::PARAM_INT);
		$statement->execute();

		$output['code'] = 200;
		$output['status'] = 'success';
		$output['message'] = 'Cloud successfully deleted';
	} catch (Exception $e) {
		$output['code'] = 500;
		$output['status'] = 'fail';
		$output['message'] = 'Database error: ' . $e->getMessage();
	}
	return $output;
}

/**
 * Function to update a UNetLab cloud.
 *
 * @param	PDO		$db					PDO object for database connection
 * @param	int		$id					Cloud ID
 * @param	Array	$p					Parameters
 * @return  Array                       Return code (JSend data)
 */
function apiUpdateCloud($db, $id, $p) {
    if (!isset($p['cloudname']) || !isset($p['username']) || !isset($p['pnet'])) {
        $output['code'] = 400;
        $output['status'] = 'fail';
        $output['message'] = "Missing required parameters (cloudname, username, pnet)";
        return $output;
    }

    try {
        $query = 'UPDATE user_clouds SET cloudname = :cloudname, username = :username, pnet = :pnet WHERE id = :id';
        $statement = $db->prepare($query);
        $statement->bindParam(':cloudname', $p['cloudname'], PDO::PARAM_STR);
        $statement->bindParam(':username', $p['username'], PDO::PARAM_STR);
        $statement->bindParam(':pnet', $p['pnet'], PDO::PARAM_STR);
        $statement->bindParam(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        $output['code'] = 200;
        $output['status'] = 'success';
        $output['message'] = 'Cloud successfully updated';
    } catch (Exception $e) {
        $output['code'] = 500;
        $output['status'] = 'fail';
        $output['message'] = 'Database error: ' . $e->getMessage();
    }

    return $output;
}
?>
