<?php

$db = new PDO('sqlite:'.DATABASE);
$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

function dbQueryValue($statement, $values, $database = null) {
	global $db;
	if ($database == null) {
		$database = $db;
	}
	$stm = $database->prepare($statement);
	$stm->execute($values);
	$result = $stm->fetch(PDO::FETCH_BOTH);
	return $result[0];
}

function dbQueryValues($statement, $values, $database = null) {
	global $db;
	if ($database == null) {
		$database = $db;
	}
	$stm = $database->prepare($statement);
	$stm->execute($values);
	$result = $stm->fetchAll(PDO::FETCH_BOTH);
	$arr = array();
	foreach ($result as $set) {
		$arr[] = $set[0];
	}
	return $arr;
}

function dbQueryRow($statement, $values, $database = null) {
	global $db;
	if ($database == null) {
		$database = $db;
	}
	$stm = $database->prepare($statement);
	$stm->execute($values);
	$result = $stm->fetch(PDO::FETCH_ASSOC);
	return $result;
}

function dbQueryAll($statement, $values, $database = null) {
	global $db;
	if ($database == null) {
		$database = $db;
	}
	$stm = $database->prepare($statement);
	$stm->execute($values);
	$result = $stm->fetchAll(PDO::FETCH_ASSOC);
	return $result;
}


function dbExec($statement, $values, $database = null) {
	global $db;
	if ($database == null) {
		$database = $db;
	}
	$stm = $database->prepare($statement);
	return $stm->execute($values);
}

function dblastId() {
	global $db;
	return $db->lastInsertId();
}

