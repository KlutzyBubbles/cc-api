<?php

require_once 'config.php';

class DBConnection {

	private $link, $set, $pointer;
	private $query = '';
	private $error = false;

	public function DBConnection($server = SERVER, $port = PORT, $database = DATABASE, $username = USERNAME, $password = PASSWORD, $charset = CHARSET) {
		try {
			//$this->link = new PDO('pgsql:host=' . $server . ':' . $port . ';dbname=' . $database, $username, $password);
			$this->link = new PDO('mysql:host=' . $server . ':' . $port . ';dbname=' . $database . ';charset=' . $charset, $username, $password);
			$this->link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch (PDOException $e) {
			$this->link = $e;
			$this->error = true;
		}
		$this->set = null;
		$this->pointer = 0;
	}

	public function hasError($soft = false) {
		if ($this->error && !$soft)
			return true;
		if (!$this->error)
			$this->error = true;
		if ($this->link instanceof PDOException || ($this->query instanceof PDOException && !$soft))
			return true;
		if (!($this->link instanceof PDO) || (!($this->query instanceof PDOStatement) && $this->query !== '' && !$soft))
			return true;
		if ($this->link->errorCode() != '00000' || ($this->query !== '' && $this->query->errorCode() != '00000' && !$soft))
			return true;
		$this->error = false;
		return false;
	}

	public function getError() {
		if (!$this->hasError())
			return null;
		if ($this->link instanceof PDOException)
			return new DBError($this->link->getCode(), $this->link->getMessage(), $this->link->getFile(), $this->link->getLine());
		if ($this->query instanceof PDOException)
			return new DBError($this->query->getCode(), $this->query->getMessage(), $this->query->getFile(), $this->query->getLine());
		if (!($this->link instanceof PDO))
			return new DBError('100', 'The link is not a valid PDO Object', 'connection.php', 5);
		if (!($this->query instanceof PDOStatement) && $this->query !== '')
			return new DBError('101', 'The query is not a valid PDOStatement and has been altered from its original value', 'connection.php', 6);
		if ($this->link->errorCode() != '00000')
			return new DBError($this->link->errorInfo()[1], $this->link->errorInfo()[2], 'connection.php', 'UNKNOWN');
		if ($this->query->errorCode() != '00000')
			return new DBError($this->query->errorInfo()[1], $this->query->errorInfo()[2], 'connection.php', 'UNKNOWN');
		return null;
	}

	public function query($q) {
		if ($this->hasError(true))
			return false;
		try {
			$this->query = $this->link->query($q);
		} catch (PDOException $e) {
			$this->query = $e;
			$this->error = true;
		}
		if ($this->hasError())
			return false;
		$this->set = null;
		$this->pointer = 0;
		if ($this->query->columnCount() > 0)
			$this->set = $this->query->fetchAll(PDO::FETCH_ASSOC);
		return $this->query->rowCount();
	}

	public function insert($table, $data) {
		if (!is_array($data))
			return false;
		if ($data == array())
			return false;
		if (array_keys($data) === range(0, count($data) - 1))
			return false;
		$q = 'INSERT INTO ' . $table . ' (';
		$prefix = '';
		foreach ($data as $key => $val) {
			$q .= $prefix . $key;
			$prefix = ', ';
		}
		$q .= ') VALUES (';
		$prefix = '';
		foreach ($data as $key => $val) {
			$q .= $prefix . $this->quote($val);
			$prefix = ', ';
		}
		$q .= ')';
		return $this->query($q);
	}

	public function update($table, $data, $where) {
		if (!isset($table) || !isset($data) || !isset($where) || !is_array($data) || $data == array() || array_keys($data) === range(0, count($data) - 1))
			return false;
		$q = 'UPDATE ' . $table . ' SET ';
		$prefix = '';
		foreach ($data as $key => $val) {
			$q .= $prefix . $key . '=' . $this->quote($val);
			$prefix = ', ';
		}
		$q .= ' WHERE ' . $where;
		return $this->query($q);
	}
	
	public function search($table, $column, $term) {
		if (!isset($table) || !isset($column) || !isset($term))
			return false;
		$q = 'SELECT * FROM ' . $table . ' WHERE ';
		$q .= $column;
		$q .= ' LIKE ';
		$q .= $this->quote('%' . $term . '%');
		return $this->query($q);
	}
	
	public function searchArr($table, $terms) {
		if (!isset($table) || !isset($terms) || !is_array($terms))
			return false;
		$q = 'SELECT * FROM ' . $table . ' WHERE ';
		$prefix = '';
		foreach ($terms as $key => $val) {
			$q .= $prefix;
			$q .= $key;
			$q .= ' LIKE ';
			$q .= $this->quote('%' . $val . '%');
			$prefix = ' AND ';
		}
		return $this->query($q);
	}

	public function hasRows() {
		return $this->rowCount() > 0;
	}

	public function fetchNext() {
		if ($this->set === null)
			return false;
		if ($this->pointer + 1 < 0 || $this->pointer + 1 >= $this->rowCount())
			return false;
		return $this->set[$this->pointer++];
	}

	public function fetchCurrent() {
		if ($this->set === null)
			return false;
		if ($this->pointer < 0 || $this->pointer >= $this->rowCount())
			return false;
		return $this->set[$this->pointer];
	}

	public function fetchPrevious() {
		if ($this->set === null)
			return false;
		if ($this->pointer - 1 < 0 || $this->pointer - 1 >= $this->rowCount())
			return false;
		return $this->set[$this->pointer--];
	}

	public function resetPointer() {
		$this->pointer = 0;
		if ($this->set === null)
			return false;
		if ($this->rowCount() === 0) {
			$this->set = null;
			return false;
		}
		return true;
	}
	/**
	public function restartPointer() {
		$this->pointer = -1;
		if ($this->set === null)
			return false;
		if ($this->rowCount() === 0) {
			$this->set = null;
			return false;
		}
		return true;
	}
*/
	public function fetchAll() {
		if ($this->set === null)
			return false;
		return $this->set;
	}

	public function rowCount($override = false) {
		if ($override)
			return $this->query->rowCount();
		if ($this->set === null)
			return 0;
		return count($this->set);
	}

	public function columnCount() {
		if ($this->set === null)
			return false;
		if (!is_array($this->set))
			return false;
		if (!is_array($this->set[0]))
			return false;
		return count($this->set[0]);
	}

	public function close() {
		$this->link = null;
		$this->query = null;
		$this->set = null;
		$this->pointer = null;
		$this->error = null;
	}

	public function quote($s) {
		if ($this->hasError(true))
			return null;
		if (!is_string($s) && is_array($s))
			throw new Exception(implode($s->getArray()));
		return $this->link->quote($s);
	}

}

class DBError {

	private $message, $code, $file, $line;

	public function DBError($code, $message, $file, $line) {
		$this->code = $code === null ? '' : $code;
		$this->message = $message === null ? '' : $message;
		$this->file = $file === null ? '' : $file;
		$this->line = $line === null ? '' : $line;
	}

	public function getMessage() {
		return $this->message;
	}

	public function getCode() {
		return $this->code;
	}

	public function getFile() {
		return $this->file;
	}

	public function getLine() {
		return $this->line;
	}

	public function getArray() {
		return ['code' => $this->getCode(), 'message' => $this->getMessage(), 'file' => $this->getFile(), 'line' => $this->getLine()];
	}

}
