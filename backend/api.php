<?php

include_once '../_globals.php';
include_once '../dbwrapper.php';

header("access-control-allow-origin: *");

function noLuck() {
	echo "Go away.";
}

if (!isset($_POST['method'])) {
	noLuck();
	return;
}

switch ($_POST['method']) {
	case 'user.login' :
		sendResult(loginApi());
		break;
	case 'user.register' :
		sendResult(registerApi());
		break;
	case 'quote.create' :
		sendResult(createQuoteApi());
		break;
	default:
		noLuck();
		return;
}

function loginApi() {
	if (!isset($_POST['password']) or !isset($_POST['email'])) {
		return array("stat" => "fail", "message" => "Password and email must be provided");
	}

	$password = $_POST['password'];
	$email = strtolower($_POST['email']);

	if (!preg_match("/^.{8,}$/", $password)) {
		return array("stat" => "fail", "message" => "Password must be at least 8 characters long");
	}

	if (!preg_match("/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/", $email)) {
		return array("stat" => "fail", "message" => "Email invalid");
	}

	$dbresult = dbQueryRow('SELECT passwordhash, username, id FROM users WHERE email = ?', array($email));
	if (!$dbresult) {
		return array("stat" => "fail", "message" => "Login failed, email not found");
	}

	$arr = explode('@', $dbresult['passwordhash']);
	$salt = $arr[0];
	$hash = $arr[1];

	$checkableHash = crypt($password, '$6$rounds=5000$' . $salt . '$');

	if ($checkableHash !== $hash) {
		return array("stat" => "fail", "message" => "Login failed, wrong password");
	}
	
	$token = md5(time() . $checkableHash . $dbresult['id']);
	
	$statement = "
				UPDATE users 
				SET token = ?
				WHERE id = ?
			";
	$arr = array($token, $dbresult['id']);
	try {
		$result = dbExec($statement, $arr);
	} catch (Exception $e) {
		$result = false;
	}
	
	session_id(md5(sha1($dbresult['id'])));
	session_start();
	$_SESSION['sessionhash'] = sha1(md5($dbresult['id']));
	
	return array("stat" => "ok", "message" => "Login successful", "username" => $dbresult['username'], "uid" => $dbresult['id'], "token" => $token);
}

function registerApi() {
	if (!isset($_POST['username']) or !isset($_POST['password']) or !isset($_POST['email'])) {
		return array("stat" => "fail", "message" => "Username, password and email must be provided");
	}

	$username = $_POST['username'];
	$password = $_POST['password'];
	$email = strtolower($_POST['email']);

	if (!preg_match("/^.{8,}$/", $password)) {
		return array("stat" => "fail", "message" => "Password must be at least 8 characters long");
	}

	if (!preg_match("/^[a-z0-9_]{4,}$/i", $username)) {
		return array("stat" => "fail", "message" => "Username invalid");
	}

	if (!preg_match("/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/", $email)) {
		return array("stat" => "fail", "message" => "Email invalid");
	}

	$salt = "";
	for ($i = 0; $i < 22; $i++) {
		$salt .= substr("./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789", mt_rand(0, 63), 1);
	}

	$hash = crypt($password, '$6$rounds=5000$' . $salt . '$');
	$hashToSafe = $salt . '@' . $hash;

	$statement = "
				INSERT INTO users 
				(email, username, passwordhash) VALUES
				(?, ?, ?)
			";
	$arr = array($email, $username, $hashToSafe);
	try {
		$result = dbExec($statement, $arr);
	} catch (Exception $e) {
		$result = false;
	}

	$uid = dbQueryValue('SELECT id FROM users WHERE email = ?', array($email));

	if ($result) {
		return array("stat" => "ok", "message" => "User " . $username . " registered", "username" => $username, "uid" => $uid);
	} else {
		return array("stat" => "fail", "message" => "User could not be registered: " . $e -> getMessage());
	}
}

function createQuoteApi() {
	if (!isset($_POST['image']) or !isset($_POST['uid']) or !isset($_POST['template']) or !isset($_POST['content']) or !isset($_POST['token'])) {
		return array("stat" => "fail", "message" => "Image, uid, token, template and content string must be provided");
	}
	
	if (!checkUserToken($_POST['uid'], $_POST['token']) or !checkUserSession($_POST['uid'])) {
		return array("stat" => "fail", "message" => "Token or session hash invalid");
	}

	$data = $_POST['image'];
	$uri = substr($data, strpos($data, ",") + 1);
	$encodedData = str_replace(' ', '+', $uri);
	$decodedData = base64_decode($encodedData);

	preg_match('/^data:image\/(.*?);/i', $data, $matches);
	
	$path = 'backend/uploads/' . $_POST['uid'] . '/';
	if (!is_dir(ROOTLOCAL . $path)) {
		mkdir(ROOTLOCAL . $path, 0775);
	}
	if (isset($matches[1])) {
		$extension = $matches[1];
	} else {
		$extension = 'png';
	}
	$filename = $_POST['template'] . '-' . time() . '.' . $extension;

	$result = file_put_contents(ROOTLOCAL . $path . $filename, $decodedData);
	if ($result === false) {
		return array("stat" => "fail", "message" => "Quote could not be saved.");
	}

	$statement = "
				INSERT INTO quotes 
				(uid, template, path, content) VALUES
				(?, ?, ?, ?)
			";
	$arr = array($_POST['uid'], $_POST['template'], $path . $filename, $_POST['content']);
	try {
		$result = dbExec($statement, $arr);
	} catch (Exception $e) {
		$result = false;
	}

	if ($result) {
		return array("stat" => "ok", "message" => "Quote successfully saved", "filename" => ROOTWEB . $path . $filename, "url" => ROOTWEB . 'quote.php?q=' . dblastId());
	} else {
		return array("stat" => "fail", "message" => "Quote could not be registered: " . $e -> getMessage());
	}
}

function checkUserToken($id, $token) {
	return dbQueryValue('SELECT id FROM users WHERE id = ? AND token = ?', array($id, $token));
}

function checkUserSession($id) {
	session_id(md5(sha1($id)));
	session_start();
	if (!isset($_SESSION['sessionhash']) or $_SESSION['sessionhash'] !== sha1(md5($id))) {
		return false;
	} else {
		return true;
	}
	
}

function sendResult($object, $callbackname = false) {
	header('content-type: application/json; charset=utf-8');
	if ($callbackname) {
		echo $callbackname . '(' . json_encode($object) . ')';
	} else {
		echo json_encode($object);
	}
}
?>