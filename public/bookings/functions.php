<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
require_once 'database/db_connection.php';

function getMax($tripID, $con = null) {
	if (is_null($con))
		$con = new DBConnection();
	if (!($con instanceof DBConnection))
		return 0;
	$con->search('trips', 'id', $tripID);
	if ($con->hasError())
		return 0;
	if (!$con->hasRows())
		return 0;
	if ($con->rowCount() > 1)
		return 0;
	$con->resetPointer();
	return $con->fetchCurrent()['max_passengers'];
}

function getCount($tripID, $con = null) {
	if (is_null($con))
		$con = new DBConnection();
	if (!($con instanceof DBConnection))
		return 0;
	$con->search('bookings', 'trip_id', $tripID);
	if ($con->hasError())
		return 0;
	if (!$con->hasRows())
		return 0;
	$con->resetPointer();
	$t = [];
	while ($row = $con->fetchNext() != false) {
		$t[] = $row['id'];
	}
	$count = 0;
	foreach ($t as $id) {
		$con->search('customer_bookings', 'booking_id', $id);
		if ($con->hasError())
			continue;
		if (!$con->hasRows())
			continue;
		$count += $con->rowCount();
	}
}

function getLeft($tripID, $con = null) {
	return getMax($tripID, $con) - getCount($tripID, $con);
}


