<?php
	
/**

File: oauth.php
Date: 26/04/2017
Author: Lee Tzilantonis
Page Format: JSON

All Errors supply atleast an 'error' and 'code' variable within the JSON Object

Error Codes:
 - 0: Database Connection or Query Issue
 	- 'db_error' variable supplied refer to DBError (db_connection.php)
 	- supplies 'table' and 'query_type' variables for debug
 - 1: Reserved for alternate builds
 - 2: Reserved for alternate builds
 - 3: Invalid POST arguments for the requested command supplied
 - 4: The request cannot define an output (no token assigned to the user)
 - 5: Token request could not be validated
 	- 'cstate' variable supplied
 - 6: Token requested has expired
 	- 'cstate' variable supplied
 - 7: The user supplied doesnt exist
 - 8: The user doesnt have permission to use the function
 - 9: The password supplied is incorrect
 
 Sucessful Requests return atleast a 'valid' variable
 
 Functions:
 
 - revoke:
 	SUCCESS:
		- 'valid': Boolean value notifying whether or not the revoke request was valid (or succeeded)
		- 'changed': Integer value indicating how many rows were affected (should only ever be 1 or 0)
	FAIL:
		- Refer to Error section above
 - request:
 	SUCCESS:
		- 'valid': Boolean value notifying whether or not the request was valid (or succeeded)
		- 'token': 10 character token to be used with all future requests, valid for 10 days from requested
		- 'requested': The server date to indicate when the token was requested
	FAIL:
		- Refer to Error section above
- validate:
	SUCCESS:
		- 'valid': Boolean value notifying whether or not the validate request was validated
	FAIL:
		- Refer to Error section above

CSTATE (Current State):

0 - Expired
1 - Valid
2 - Revoked

4 - Overridden

*/
	
if (PHP_SAPI == 'cli-server') {
    // To help the built-in PHP dev server, check if the request was actually for
    // something which should probably be served as a static file
    $url  = parse_url($_SERVER['REQUEST_URI']);
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
require_once 'random_compat/lib/random.php';
require_once 'user.php';

$output = [];
$output['valid'] = false;

$app->post('/revoke', function(Request $request, Response $response) {
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['token']) && isset($body['revoke'])) {
		$con = new DBConnection();
		if ($con->hasError()) {
			$output['error'] = $con->getError()->getArray();
			$output['code'] = 0;
		} else {
			$con->query("SELECT id, state FROM users WHERE email=" . $con->quote(strtolower($body['user'])));
			if ($con->hasError()) {
				$output['db_error'] = $con->getError()->getArray();
				$output['error'] = 'The user doesnt exist';
				$output['code'] = 0;
			} else {
				if ($con->rowCount() !== 1) {
					$output['error'] = 'The user doesnt exist';
					$output['code'] = 7;
				} else {
					$u = $con->fetchCurrent();
					$con->query("SELECT requested, cstate, expires FROM tokens WHERE token=" . $con->quote($body['token']) . " AND user=" . $con->quote($u['id']));
					if ($con->hasError()) {
						$output['db_error'] = $con->getError()->getArray();
						$output['error'] = 'There was an issue executing the SELECT query';
						$output['code'] = 0;
					} else {
						if ($con->rowCount() != 1) {
							$output['error'] = 'The request returned no values';
							$output['code'] = 4;
						} else {
							$r = $con->fetchCurrent();
							if ($r['cstate'] == '1') {
								$requested = new DateTime(date("Y-m-d H:i:s", strtotime($r['requested'])));
								$now = new DateTime();
								$dif = $requested->diff($now);
								$mil = ($dif->d * 86400 + $dif->h * 3600 + $dif->i * 60 + $dif->s) * 1000 + $dif->f;
								if ($mil > $r['expires']) {
									$output['error'] = 'The token has expired';
									$output['code'] = 6;
									$output['cstate'] = 0;
									$con->query("DELETE FROM tokens WHERE token='" . $con->quote($body['token']) . "'");
									if ($con->hasError()) {
										$output['db_error'] = $con->getError()->getArray();
										$output['error'] = 'There was an issue executing the DELETE query';
										$output['code'] = 0;
									}
								} else {
									if ($u['state'] >= 3) {
										$con->query('SELECT cstate FROM tokens WHERE cstate=2 AND token=' . $con->quote($body['revoke']));
										if ($con->hasError()) {
											$output['db_error'] = $con->getError()->getArray();
											$output['error'] = 'There was an issue executing the SELECT query';
											$output['code'] = 0;
										} else {
											if ($con->rowCount() >= 1) {
												$output['valid'] = true;
												$output['changed'] = 0;
											} else {
												$con->query('UPDATE tokens SET cstate=2 WHERE token=' . $con->quote($body['revoke']));
												if ($con->hasError()) {
													$output['db_error'] = $con->getError()->getArray();
													$output['error'] = 'There was an issue executing the UPDATE query';
													$output['code'] = 0;
												} else {
													$con->query('SELECT cstate FROM tokens WHERE cstate=2 AND token=' . $con->quote($body['revoke']));
													if ($con->hasError()) {
														$output['db_error'] = $con->getError()->getArray();
														$output['error'] = 'There was an issue executing the SELECT query';
														$output['code'] = 0;
													} else {
														$output['valid'] = true;
														$output['changed'] = $con->rowCount();
													}
												}
											}
										}
									} else {
										$output['error'] = 'The user doesnt have permission to revoke access';
										$output['code'] = 8;
									}
								}
							} else {
								$output['error'] = 'The request could not be validated';
								$output['code'] = 5;
								$output['cstate'] = $r['cstate'];
							}
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

$app->post('/request', function(Request $request, Response $response) {
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['password'])) {
		$con = new DBConnection();
		if ($con->hasError()) {
			$output['error'] = $con->getError()->getArray();
			$output['code'] = 0;
		} else {
			$con->query("SELECT id, password FROM users WHERE email=" . $con->quote(strtolower($body['user'])));
			if ($con->hasError()) {
				$output['db_error'] = $con->getError()->getArray();
				$output['error'] = 'The user doesnt exist';
				$output['code'] = 0;
			} else {
				if ($con->rowCount() !== 1) {
					$output['error'] = 'The user doesnt exist';
					$output['code'] = 7;
				} else {
					$u = $con->fetchCurrent();
					if (password_verify($body['password'], $u['password'])) {
						$con->query("SELECT token, requested, cstate, expires FROM tokens WHERE cstate=1 AND user=" . $con->quote($u['id']));
						if ($con->hasError()) {
							$output['db_error'] = $con->getError()->getArray();
							$output['error'] = 'There was an issue executing the SELECT query';
							$output['code'] = 0;
						} else {
							$r = $con->fetchCurrent();
							if ($con->rowCount() == 1) {
								$con->query('UPDATE tokens SET cstate=4 WHERE cstate=1 AND token=' . $con->quote($r['token']));
								if ($con->hasError()) {
									$output['db_error'] = $con->getError()->getArray();
									$output['error'] = 'There was an issue executing the UPDATE query';
									$output['code'] = 0;
								}
							}
							$con->query('INSERT INTO tokens VALUES (' . $con->quote($u['id']) . ', NOW(3), 1, ' . $con->quote($_SERVER['REMOTE_ADDR']) . ', ' . $con->quote(bin2hex(random_bytes(32))) . ', 864000000)');
							if ($con->hasError()) {
								$output['db_error'] = $con->getError()->getArray();
								$output['error'] = 'There was an issue executing the INSERT query';
								$output['code'] = 0;
							} else {
								$con->query('SELECT token, requested FROM tokens WHERE cstate=1 AND user=' . $con->quote($u['id']));
								if ($con->hasError()) {
									$output['db_error'] = $con->getError()->getArray();
									$output['error'] = 'There was an issue executing the SELECT query';
									$output['code'] = 0;
								} else {
									if ($con->rowCount() != 1) {
										$output['error'] = 'The request returned no values';
										$output['code'] = 4;
									} else {
										$output['valid'] = true;
										$output['token'] = $con->fetchCurrent()['token'];
										$output['requested'] = $con->fetchCurrent()['requested'];
									}
								}
							}
						}
					} else {
						$output['error'] = 'The password is incorrect';
						$output['code'] = 9;
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

$app->post('/validate', function(Request $request, Response $response) {
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['token'])) {
		$con = new DBConnection();
		if ($con->hasError()) {
			$output['error'] = $con->getError()->getArray();
			$output['code'] = 0;
		} else {
			$u = new User($body['user']);
			if (!$u->valid) {
				if ($u->hasError())
					$output['db_error'] = $u->getToken()->getArray();
				$output['error'] = 'The user doesnt exist';
				$output['code'] = 0;
			} else {
				if ($u->getToken() == null) {
					$output['error'] = 'The request returned no values';
					$output['code'] = 4;
				} else {
					if ($u->tokenMatch($_POST['token'])) {
						$output['valid'] = true;
					} else if ($u->hasExpired()) {
						$output['error'] = 'The token has expired';
						$output['code'] = 6;
						$output['cstate'] = 0;
					} else {
						$output['error'] = 'The request could not be validated';
						$output['code'] = 5;
						$output['cstate'] = $u->getCState($body['token']);
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

// Run app
$app->run();