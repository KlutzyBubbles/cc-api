<?php

if (PHP_SAPI == 'cli-server') {
	// To help the built-in PHP dev server, check if the request was actually for
	// something which should probably be served as a static file
	$url = parse_url($_SERVER['REQUEST_URI']);
	$file = __DIR__ . $url['path'];
	if (is_file($file)) {
		return false;
	}
}

require __DIR__ . '/../vendor/autoload.php';

session_start();

header("Content-Type: application/json");

// Instantiate the app
$settings = require __DIR__ . '/../src/settings.php';
$app = new \Slim\App($settings);

// Set up dependencies
require __DIR__ . '/../src/dependencies.php';

// Register middleware
require __DIR__ . '/../src/middleware.php';

// Register routes
require __DIR__ . '/../src/routes.php';

require_once 'database/db_connection.php';
require_once 'global_config.php';
require_once 'oauth/oauth_functions.php';

/*
 * Error Codes:
 * 0 - Database error
 * 1 - Body variable format error
 * 2 - Token error
 * 3 - Invalid arguments
 */

$app->post('/register', function(Request $request, Response $response) {
	$body = $request->getParsedBody();
	if (isset($body['id']) && isset($body['first_name']) && isset($body['last_name']) && isset($body['street_no']) && isset($body['street_name']) && isset($body['suburb']) && isset($body['postcode']) && isset($body['email']) && isset($body['password'])) {
		$con = new DBConnection();
		if ($con->hasError()) {
			$output['error'] = $con->getError()->getArray();
			$output['code'] = 0;
		} else {
			if (!is_int($body['id'])) {
				$output['error'] = 'The ID must be a number';
				$output['field'] = 'id';
				$output['code'] = 1;
			} else if ($body['id'] < 1 || $body['id'] > 999999) {
				$output['error'] = 'The ID must be between 1 and 999999';
				$output['field'] = 'id';
				$output['code'] = 1;
			} else {
				$con->query("SELECT id FROM users WHERE id=" . $con->quote($body['id']));
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else if ($con->rowCount() >= 1) {
					$output['error'] = 'The user already exist';
					$output['field'] = 'id';
					$output['code'] = 1;
				} else if (!is_int($body['street_no'])) {
					$output['error'] = 'The Street Number must be a number';
					$output['field'] = 'street_no';
					$output['code'] = 1;
				} else if ($body['street_no'] < 1 || $body['street_no'] > 999999) {
					$output['error'] = 'The Street Number must be between 1 and 999999';
					$output['field'] = 'street_no';
					$output['code'] = 1;
				} else if (!is_int($body['postcode'])) {
					$output['error'] = 'The Postcode must be a number';
					$output['field'] = 'postcode';
					$output['code'] = 1;
				} else if ($body['postcode'] < 1 || $body['postcode'] > 9999) {
					$output['error'] = 'The Postcode must be between 1 and 9999';
					$output['field'] = 'postcode';
					$output['code'] = 1;
				} else {
					if (!isset($body['middle_initial']))
						$body['middle_initial'] = '';
					if (!isset($body['phone']))
						$body['phone'] = '';
					$body['password'] = password_hash($body['password']);
					$con->insert('users', $body);
					if ($con->hasError()) {
						$output['db_error'] = $con->getError()->getArray();
						$output['code'] = 0;
					} else {
						/*
						  $c = curl_init();
						  curl_setopt_array($c, array(
						  CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
						  CURLOPT_URL => CLOUD_URL . 'oauth.php/request',
						  CURLOPT_POST => true,
						  CURLOPT_POSTFIELDS => json_encode(array(
						  "user" => $body['email'],
						  "password" => $body['password']
						  ))
						  ));
						  $o = curl_exec($c);
						  curl_close($c);

						 */
						$o = [];
						$o['valid'] = false;
						request($o, $body['email'], $body['password']);
						if (!$o['valid']) {
							$output['error'] = 'The token could not be retrieved';
							$output['token']['error'] = $o['error'];
							$output['token']['code'] = $o['code'];
							$output['code'] = 2;
						} else {
							$output['valid'] = true;
							$output['user'] = $body['email'];
							$output['token'] = $o['token'];
						}
					}
				}
			}
		}
		$con->close();
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
});


$app->post('/user/update', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['token'])) {
		$o = [];
		$o['valid'] = false;
		validate($o, $body['user'], $body['token']);
		if (!$o['valid']) {
			$output['error'] = 'The token could not be validated';
			$output['token']['error'] = $o['error'];
			$output['token']['code'] = $o['code'];
			$output['code'] = 2;
		} else {
			$con = new DBConnection();
			if ($con->hasError()) {
				$output['error'] = $con->getError()->getArray();
				$output['code'] = 0;
			} else {
				$data = [];
				foreach ($body as $key => $val) {
					if (strtolower($key) == 'user' || strtolower($key) == 'token' || strtolower($key) == 'password')
						continue;
					$data[$key] = $val;
				}
				$where = 'email=' . $con->quote($body['user']);
				$con->update('users', $data, $where);
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else {
					$output['valid'] = true;
				}
			}
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
});

$app->post('/user/password_reset', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['token']) && isset($body['password'])) {
		$o = [];
		$o['valid'] = false;
		validate($o, $body['user'], $body['token']);
		if (!$o['valid']) {
			$output['error'] = 'The token could not be validated';
			$output['token']['error'] = $o['error'];
			$output['token']['code'] = $o['code'];
			$output['code'] = 2;
		} else {
			$con = new DBConnection();
			if ($con->hasError()) {
				$output['error'] = $con->getError()->getArray();
				$output['code'] = 0;
			} else {
				$data = [];
				$data['password'] = password_hash($body['password']);
				$where = 'email=' . $con->quote($body['user']);
				$con->update('users', $data, $where);
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else {
					$output['valid'] = true;
				}
			}
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
});

$app->post('/tours/search', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['token']) && isset($body['column']) && isset($body['term'])) {
		$o = [];
		$o['valid'] = false;
		validate($o, $body['user'], $body['token']);
		if (!$o['valid']) {
			$output['error'] = 'The token could not be validated';
			$output['token']['error'] = $o['error'];
			$output['token']['code'] = $o['code'];
			$output['code'] = 2;
		} else {
			$con = new DBConnection();
			if ($con->hasError()) {
				$output['error'] = $con->getError()->getArray();
				$output['code'] = 0;
			} else {
				$data = [];
				$o = $con->search('tours', $body['column'], $body['term']);
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else if ($con->hasRows($o)) {
					$output['valid'] = true;
					$output['rows'] = $con->rowCount($o);
					$output['data'] = $con->fetchAll($o);
				} else {
					$output['valid'] = true;
					$output['rows'] = 0;
					$output['data'] = [];
				}
			}
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
});

/**


  $body = $request->getParsedBody();
  if (isset($body['']) && isset($body[''])) {
  $con = new DBConnection();
  if ($con->hasError()) {
  $output['error'] = $con->getError()->getArray();
  $output['code'] = 0;
  } else {

  }
  $con->close();
  } else {
  $output['error'] = 'Invalid arguments provided, please see documentation';
  $output['code'] = 3;
  }
  $response->getBody()->write(json_encode($output));


 */
// Run app
$app->run();
