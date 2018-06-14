<?php

require_once 'database/db_connection.php';

class User {
	
	private $token, $requested, $expires, $expired, $cstate, $password, $state, $id, $username, $name, $refreshed;
	
	public $data, $valid;
	
	public function User($username) {
		$this->username = strtolower($username);
		$this->data = $this->refreshData(true);
		$this->valid = true;
		if ($this->data === false || $this->data instanceof DBError)
			$this->valid = false;
	}
	
	public function tokenMatch($token) {
		return $token == $this->token;
	}
	
	public function passwordMatch($password) {
		return password_verify($password, $this->password);
	}
	
	public function getCState($token = null) {
		if ($token != null) {
			$con = new DBConnection();
			$con->query('SELECT cstate FROM tokens WHERE token=' . $con->quote($token) . ' AND user=' . $con->quote($this->id));
			if ($con->hasError())
				return 5;
			if ($con->rowCount() != 1)
				return 5;
			return $con->fetchCurrent()['cstate'];
		}
		$this->updateExpiry();
		return $this->cstate;
	}
	
	public function updateExpiry() {
		if ($this->cstate != '1') {
			$this->cstate = '0';
			$this->expired = true;
		}
		if (!$this->expired) {
			$requested = new DateTime(date("Y-m-d H:i:s", strtotime($this->requested)));
			$now = new DateTime();
			$dif = $requested->diff($now);
			//$mil = ($dif->d * 86400 + $dif->h * 3600 + $dif->i * 60 + $dif->s) * 1000;
			$mil = ($now->getTimestamp() - $requested->getTimestamp()) * 1000;
			if ($mil > intval($this->expires)) {
				$this->cstate = 0;
				$this->expired = true;
			}
		}
		$con = new DBConnection();
		if ($this->expired && $this->valid)
			$con->query('UPDATE tokens SET cstate=0 WHERE cstate=1 AND token=' . $con->quote($this->token));
		$con->close();
	}
	
	public function hasError() {
		$this->refreshData();
		if ($this->token instanceof DBError) {
			$this->valid = false;
			return true;
		}
		return false;
	}
	
	public function hasExpired() {
		$this->updateExpiry();
		return $this->expired;
	}
	
	public function getLoginDate() {
		return $this->requested;
	}
	
	public function getLifeSpan() {
		return $this->expires;
	}
	
	public function getState() {
		$this->refreshData();
		return $this->state;
	}
	
	public function getId() {
		$this->refreshData();
		return $this->id;
	}
	
	public function getName() {
		$this->refreshData();
		return $this->name;
	}
	
	public function refreshData($override = false) {
		if ($this->refreshed && !$override)
			return false;
		if ($this->username === null)
			return false;
		$con = new DBConnection();
		if ($con->hasError())
			return $con->getError();
		$this->data = $con->query('SELECT password, id, state, first_name, last_name FROM users WHERE LOWER(email)=' . $con->quote($this->username));
		if ($con->hasError())
			return $con->getError();
		if ($con->rowCount() != 1)
			return false;
		$r = $con->fetchCurrent();
		$this->password = $r['password'];
		$this->id = $r['id'];
		$this->state = $r['state'];
		$this->name = $r['first_name'] . ' ' . $r['last_name'];
		$this->refreshed = true;
		$this->token = $this->getToken(true);
		return true;
	}
	
	public function getToken($override = false) {
		if ($this->token instanceof DBError)
			if (!$override)
				return $this->token;
		if ($this->token !== null && $this->token != '')
			if (!$override)
				return $this->token;
		if (!$this->refreshed)
			$this->refreshData();
		$con = new DBConnection();
		if ($con->hasError())
			return $con->getError();
		$con->query('SELECT token, expires, requested, cstate FROM tokens WHERE cstate=1 AND user=' . $con->quote($this->id));
		if ($con->hasError())
			return $con->getError();
		if ($con->rowCount() != 1) {
			$con->query('SELECT token, expires, requested, cstate FROM tokens WHERE user=' . $con->quote($this->id) . ' ORDER BY requested DESC');
			if ($con->hasError())
				return $con->getError();
			if ($con->rowCount() == 0) {
				return null;
			}
		}
		$r = $con->fetchCurrent();
		$con->close();
		$this->token = $r['token'];
		$this->expires = $r['expires'];
		$this->cstate = $r['cstate'];
		$this->requested = $r['requested'];
		$this->expired = false;
		$this->updateExpiry();
		return $this->token;
	}
	
}
