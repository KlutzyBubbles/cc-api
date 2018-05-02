<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require_once 'oauth/user.php';

function revoke(&$output, $user, $token) {
	if (isset($user) && isset($token)) {
		$con = new DBConnection();
		if ($con->hasError()) {
			$output['error'] = $con->getError()->getArray();
			$output['code'] = 0;
		} else {
			$con->query("SELECT id, state FROM users WHERE email=" . $con->quote(strtolower($user)));
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
					$con->query("SELECT requested, cstate, expires FROM tokens WHERE token=" . $con->quote($token) . " AND user=" . $con->quote($u['id']));
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
									$con->query("DELETE FROM tokens WHERE token='" . $con->quote($token) . "'");
									if ($con->hasError()) {
										$output['db_error'] = $con->getError()->getArray();
										$output['error'] = 'There was an issue executing the DELETE query';
										$output['code'] = 0;
									}
								} else {
									if ($u['state'] >= 3) {
										$con->query('SELECT cstate FROM tokens WHERE cstate=2 AND token=' . $con->quote($token));
										if ($con->hasError()) {
											$output['db_error'] = $con->getError()->getArray();
											$output['error'] = 'There was an issue executing the SELECT query';
											$output['code'] = 0;
										} else {
											if ($con->rowCount() >= 1) {
												$output['valid'] = true;
												$output['changed'] = 0;
											} else {
												$con->query('UPDATE tokens SET cstate=2 WHERE token=' . $con->quote($token));
												if ($con->hasError()) {
													$output['db_error'] = $con->getError()->getArray();
													$output['error'] = 'There was an issue executing the UPDATE query';
													$output['code'] = 0;
												} else {
													$con->query('SELECT cstate FROM tokens WHERE cstate=2 AND token=' . $con->quote($token));
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
}

function request(&$output, $user, $password) {
	if (isset($user) && isset($password)) {
		$con = new DBConnection();
		if ($con->hasError()) {
			$output['error'] = $con->getError()->getArray();
			$output['code'] = 0;
		} else {
			$con->query("SELECT id, password FROM users WHERE email=" . $con->quote(strtolower($user)));
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
					if (password_verify($password, $u['password'])) {
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
}

function validate(&$output, $user, $token) {
	if (isset($user) && isset($token)) {
		$con = new DBConnection();
		if ($con->hasError()) {
			$output['error'] = $con->getError()->getArray();
			$output['code'] = 0;
		} else {
			$u = new User($user);
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
					if ($u->tokenMatch($token)) {
						$output['valid'] = true;
					} else if ($u->hasExpired()) {
						$output['error'] = 'The token has expired';
						$output['code'] = 6;
						$output['cstate'] = 0;
					} else {
						$output['error'] = 'The request could not be validated';
						$output['code'] = 5;
						$output['cstate'] = $u->getCState($token);
					}
				}
			}
		}
		$con->close();
	} else {
		$output['error'] = 'Invalid arguments provided, please see documentation';
		$output['code'] = 3;
	}
}
