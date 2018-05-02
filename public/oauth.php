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
require_once 'random_compat/lib/random.php';
require_once 'oauth/oauth_functions.php';

$output = [];
$output['valid'] = false;

$app->post('/revoke', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	revoke($output, $body['user'], $body['token']);
	$response->getBody()->write(json_encode($output));
});

$app->post('/request', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	request($output, $body['user'], $body['password']);
	$response->getBody()->write(json_encode($output));
});

$app->post('/validate', function(Request $request, Response $response) {
	global $output;
	$body = $request->getParsedBody();
	validate($output, $body['user'], $body['token']);
	$response->getBody()->write(json_encode($output));
});

// Run app
$app->run();
