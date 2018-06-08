<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

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
require_once 'oauth/oauth_functions.php';

/*
 * Error Codes:
 * 0 - Database error
 * 1 - Body variable format error
 * 2 - Token error
 * 3 - Invalid arguments
 * 
 * * = optional argument
 */


/**
 * DATA:
 * 
 * first_name
 * middle_initial*
 * last_name
 * street_no
 * street_name
 * suburb
 * postcode
 * email
 * password
 * phone*
 * 
 * ERROR CODES:
 * 
 * 4 - User already exists
 * 5 - street_no invalid format
 * 6 - postcode invalid format
 * 
 */
$app->post('/user/register', function(Request $request, Response $response) {
	$body = $request->getParsedBody();
	if (isset($body['first_name']) && isset($body['last_name'])
			&& isset($body['street_no']) && isset($body['street_name']) && isset($body['suburb'])
			&& isset($body['postcode']) && isset($body['email']) && isset($body['password'])) {
		$con = new DBConnection();
		if ($con->hasError()) {
			$output['error'] = $con->getError()->getArray();
			$output['code'] = 0;
		} else {
			$con->query("SELECT id FROM users WHERE LOWER(email)=" . $con->quote(strtolower($body['email'])));
			if ($con->hasError()) {
				$output['db_error'] = $con->getError()->getArray();
				$output['code'] = 0;
			} else if ($con->rowCount() >= 1) {
				$output['error'] = 'The user already exist';
				$output['field'] = 'id';
				$output['code'] = 4;
			} else if (!is_numeric($body['street_no'])) {
				$output['error'] = 'The Street Number must be a number';
				$output['field'] = 'street_no';
				$output['code'] = 5;
			} else if ($body['street_no'] < 1 || $body['street_no'] > 999999) {
				$output['error'] = 'The Street Number must be between 1 and 999999';
				$output['field'] = 'street_no';
				$output['code'] = 5;
			} else if (!is_numeric($body['postcode'])) {
				$output['error'] = 'The Postcode must be a number';
				$output['field'] = 'postcode';
				$output['code'] = 6;
			} else if ($body['postcode'] < 1 || $body['postcode'] > 9999) {
				$output['error'] = 'The Postcode must be between 1 and 9999';
				$output['field'] = 'postcode';
				$output['code'] = 7;
			} else {
				if (!isset($body['middle_initial']))
					$body['middle_initial'] = '';
				if (!isset($body['phone']))
					$body['phone'] = '';
				$pass = $body['password'];
				$body['password'] = password_hash($pass, PASSWORD_DEFAULT);
				if (count($body) != 10) {
					$output['error'] = 'Invalid arguments provided, please see documentation';
					$output['code'] = 3;
				} else {
					$con->insert('users', $body);
					if ($con->hasError()) {
						$output['db_error'] = $con->getError()->getArray();
						$output['code'] = 0;
					} else {
						$o = [];
						$o['valid'] = false;
						request($o, $body['email'], $pass);
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
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});

/**
 * DATA:
 * 
 * user
 * token
 * 
 * ERROR CODES:
 * 
 * 4 - User doesn't exists
 * 
 */
$app->post('/user/get', function(Request $request, Response $response) {
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
				$con->query("SELECT first_name, middle_initial, last_name, street_no, street_name, suburb, postcode, phone, state FROM users WHERE LOWER(email)=" . $con->quote(strtolower($body['email'])) . " LIMIT 1");
				$output['sql'] = "SELECT first_name, middle_initial, last_name, street_no, street_name, suburb, postcode, phone, state FROM users WHERE LOWER(email)=" . $con->quote(strtolower($body['email'])) . " LIMIT 1";
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else if ($con->rowCount() >= 1) {
					$output['valid'] = true;
					$output['data'] = $con->fetchAll();
				} else {
					$output['error'] = 'The user doesnt exist';
					$output['code'] = 4;
				}
			}
			$con->close();
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});

/**
 * DATA:
 * 
 * user
 * token
 * first_name*
 * middle_initial*
 * last_name*
 * street_no*
 * street_name*
 * suburb*
 * postcode*
 * phone*
 * 
 * ERROR CODES:
 * 
 * Refer to page comment
 */
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
					if (strtolower($key) == 'user' || strtolower($key) == 'token'
							|| strtolower($key) == 'password' || strtolower($key) == 'id'
							|| strtolower($key) == 'email' || strtolower($key) == 'state')
						continue;
					$data[$key] = $val;
				}
				$where = 'LOWER(email)=' . $con->quote(strtolower($body['user']));
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
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});

/**
 * DATA:
 * 
 * user
 * token
 * password
 * 
 * ERROR CODES:
 * 
 * Refer to page comment
 */
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
				$data['password'] = password_hash($body['password'], PASSWORD_DEFAULT);
				$where = 'LOWER(email)=' . $con->quote(strtolower($body['user']));
				$con->update('users', $data, $where);
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else {
					$o = [];
					$o['valid'] = false;
					request($o, $body['user'], $body['password']);
					if (!$o['valid']) {
						$output['error'] = 'The token could not be retrieved';
						$output['token']['error'] = $o['error'];
						$output['token']['code'] = $o['code'];
						$output['code'] = 2;
					} else {
						$output['valid'] = true;
						$output['user'] = $body['user'];
						$output['token'] = $o['token'];
					}
				}
			}
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});

/**
 * DATA:
 * 
 * user
 * token
 * column
 * term
 * 
 * ERROR CODES:
 * 
 * Refer to page comment
 */
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
				$con->search('tours', $body['column'], $body['term']);
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else if ($con->hasRows()) {
					$output['valid'] = true;
					$output['rows'] = $con->rowCount();
					$output['data'] = $con->fetchAll();
				} else {
					$output['valid'] = true;
					$output['rows'] = 0;
					$output['data'] = [];
				}
			}
		}
	} else if (isset($body['user']) && isset($body['token'])) {
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
				$con->query('SELECT * FROM tours');
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else if ($con->hasRows()) {
					$output['valid'] = true;
					$output['rows'] = $con->rowCount();
					$output['data'] = $con->fetchAll();
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
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});


/**
 * DATA:
 * 
 * user
 * token
 * trip_id
 * 
 * Error Codes:
 * 
 * 4 - Trip doesn't exist
 * 5 - No space left on the trip
 * 6 - Trip already booked
 */
$app->post('/trips/book', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['token']) && isset($body['trip_id'])) {
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
				$output['db_error'] = $con->getError()->getArray();
				$output['code'] = 0;
			} else {
				$con->search('trips', 'id', $body['trip_id']);
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else if ($con->hasRows()) {
					require_once 'bookings/functions.php';
					$c = $con->fetchCurrent();
					if (getLeft($body['trip_id']) > 0) {
						$con->searchArr('bookings', ['trip_id'=>$body['trip_id'],
													'primary_customer'=>getID($body['user'], $body['token'])]);
						if ($con->hasError()) {
							$output['db_error'] = $con->getError()->getArray();
							$output['code'] = 0;
						} else if ($con->hasRows()) {
							$output['error'] = 'User already booked trip';
							$output['code'] = 6;
						} else {
							$con->insert('bookings', ['trip_id'=>$body['trip_id'],
														'primary_customer'=>getID($body['user'], $body['token']),
														'booking_date'=>$c['departure'],
														'deposit'=>$c['standard_cost'] / 10]);
							if ($con->hasError()) {
								$output['db_error'] = $con->getError()->getArray();
								$output['code'] = 0;
							} else {
								$output['valid'] = true;
							}
						}
					} else {
						$output['error'] = 'The trip doesnt have any more space';
						$output['code'] = 5;
					}
				} else {
					$output['error'] = 'The trip doesnt exist';
					$output['code'] = 4;
				}
			}
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});

$app->post('/trips/me', function(Request $request, Response $response) {
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
				$cont = true;
				$result = array();
				$con->search('bookings', 'primary_customer', getID($body['user'], $body['token']));
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else {
					if ($con->hasRows()) {
						$r = $con->fetchAll();
						foreach ($r as $row) {
							$result[] = $row;
						}
					}
					$con->search('customer_bookings', 'customer_id', getID($body['user'], $body['token']));
					if ($con->hasError()) {
						$output['db_error'] = $con->getError()->getArray();
						$output['code'] = 0;
					} else {
						if ($con->hasRows()) {
							$rows = $con->fetchAll();
							foreach($rows as $row) {
								$con->search('bookings', 'id', $row['booking_id']);
								if ($con->hasError()) {
									$output['db_error'] = $con->getError()->getArray();
									$output['code'] = 0;
									$cont = false;
									break;
								} else if ($con->hasRows()) {
									$result[] = $con->fetchCurrent();
								}
							}
						}
						if ($cont) {
							$trips = array();
							foreach ($result as $row) {
								$con->search('trips', 'id', $row['trip_id']);
								if ($con->hasError()) {
									$output['db_error'] = $con->getError()->getArray();
									$output['code'] = 0;
									$cont = false;
									break;
								} else if ($con->hasRows()) {
									$trips[] = $con->fetchCurrent();
								}
							}
							if ($cont) {
								$output['valid'] = true;
								$output['rows'] = count($trips);
								$output['data'] = $trips;
							}
						}
					}
				}
			}
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});

/**
 * DATA:
 * 
 * user
 * token
 * trip_id
 * 
 * Error Codes:
 * 
 * 4 - Trip doesn't exist
 * 5 - Trip doesn't have an itinerary
 */
$app->post('/trips/itinerary', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['token']) && isset($body['trip_id'])) {
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
				$con->search('trips', 'id', $body['trip_id']);
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else if ($con->hasRows()) {
					require_once 'bookings/functions.php';
					$c = $con->fetchCurrent();
					$con->search('itinerary', 'trip_id', $c['id']);
					if ($con->hasError()) {
						$output['db_error'] = $con->getError()->getArray();
						$output['code'] = 0;
					} else if ($con->hasRows()) {
						$output['valid'] = true;
						$output['rows'] = $con->rowCount();
						$output['data'] = $con->fetchAll();
					} else {
						$output['error'] = 'There is no itinerary attached to the trip';
						$output['code'] = 4;
					}
				} else {
					$output['error'] = 'The trip doesnt exist';
					$output['code'] = 4;
				}
			}
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});

/**
 * DATA:
 * 
 * user
 * token
 * trip_id
 * rating - Between 1-5
 * feedback - Not empty
 * likes - Not Empty
 * dislikes - Not Empty
 * 
 * Error Codes:
 * 
 * 4 - Trip doesn't exist
 * 5 - Trip already reviewed
 * 6 - Haven't booked
 * 7 - Haven't completed trip
 * 8 - Invalid rating
 */
$app->post('/trips/review', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	if (isset($body['user']) && isset($body['token']) && isset($body['trip_id'])
			&& isset($body['rating']) && isset($body['feedback']) && isset($body['likes']) && isset($body['dislikes'])
			&& $body['feedback'] != null && $body['feedback'] != ''
			&& $body['likes'] != null && $body['likes'] != ''
			&& $body['dislikes'] != null && $body['dislikes'] != '') {
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
				$con->search('trips', 'id', $body['trip_id']);
				if ($con->hasError()) {
					$output['db_error'] = $con->getError()->getArray();
					$output['code'] = 0;
				} else if ($con->hasRows()) {
					$con->searchArr('customer_reviews', ['trip_id'=>$body['trip_id'], 'customer_id'=> getID($body['user'], $body['token'])]);
					if ($con->hasError()) {
						$output['db_error'] = $con->getError()->getArray();
						$output['code'] = 0;
					} else if ($con->hasRows()) {
						$output['error'] = 'User has already reviewed that trip';
						$output['code'] = 5;
					} else {
						$review = false;
						$con->searchArr('bookings', ['trip_id'=>$body['trip_id'], 'primary_customer'=> getID($body['user'], $body['token'])]);
						if ($con->hasError()) {
							$output['db_error'] = $con->getError()->getArray();
							$output['code'] = 0;
						} else if ($con->hasRows()) {
							if (strtotime($con->fetchCurrent()['booking_date']) >= time()) {
								$output['error'] = 'User hasnt completed the trip yet';
								$output['code'] = 7;
							} else {
								$review = true;
							}
						} else {
							$con->search('customer_bookings', 'customer_id', getID($body['user'], $body['token']));
							if ($con->hasError()) {
								$output['db_error'] = $con->getError()->getArray();
								$output['code'] = 0;
							} else if ($con->hasRows()) {
								$o = $con->fetchAll();
								foreach ($o as $val) {
									$con->searchArr('bookings', ['trip_id'=>$body['trip_id'], 'booking_id'=>$val['booking_id']]);
									if ($con->hasError()) {
										$output['db_error'] = $con->getError()->getArray();
										$output['code'] = 0;
									} else if ($con->hasRows()) {
										if (strtotime($con->fetchCurrent()['booking_date']) >= time()) {
											$output['error'] = 'User hasnt completed the trip yet';
											$output['code'] = 7;
										} else {
											$review = true;
										}
										break;
									}
								}
							} else {
								$output['error'] = 'User hasnt got the trip booked';
								$output['code'] = 6;
							}
						}
						if ($review) {
							if (is_numeric($body['rating']) && $body['rating'] > 0 && $body['rating'] <= 5) {
								$data = ['trip_id'=>$body['trip_id'], 'customer_id'=> getID($body['user'],$body['token']),
											'rating'=>$body['rating'], 'feedback'=>$body['feedback'],
											'likes'=>$body['likes'], 'dislikes'=>$body['dislikes']];
								$con->insert('customer_reviews', $data);
								if ($con->hasError()) {
									$output['db_error'] = $con->getError()->getArray();
									$output['code'] = 0;
								} else {
									$output['valid'] = true;
									$output['data'] = $data;
									$output['rows'] = 1;
								}
							} else {
								$output['error'] = 'Invalid rating (only from 1-5)';
								$output['code'] = 8;
							}
						} else {
							if ((!isset($output['error']) && !isset($output['db_error'])) || !isset($output['code'])) {
								$output['error'] = 'Unknown error occurred';
								$output['code'] = -1;
							}
						}
					}
				} else {
					$output['error'] = 'The trip doesnt exist';
					$output['code'] = 4;
				}
			}
		}
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
	$response->getBody()->write(json_encode($output));
	return $response->withAddedHeader('Content-type', 'application/json')->withAddedHeader('Access-Control-Allow-Origin', '*');
});

// Run app
$app->run();
