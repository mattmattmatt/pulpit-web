<?php

include_once '../_globals.php';
include_once '../dbwrapper.php';
include_once './imagestuff.php';

header("access-control-allow-origin: *");
header('content-type: application/json; charset=utf-8');

function noLuck() {
	echo "Go away.";
}

if (!isset($_POST['method'])) {
	noLuck();
	return;
}

switch ($_POST['method']) {
	case 'user.edit.delete' :
		sendResult(deleteUser());
		break;
	case 'user.edit.update' :
		sendResult(updateUser());
		break;
	case 'user.facebook.register' :
		sendResult(fbUserApi(FALSE));
		break;
	case 'user.facebook.login' :
		sendResult(fbUserApi(TRUE));
		break;
	case 'quote.create' :
		sendResult(createQuoteApi());
		break;
	case 'quote.shared.fb' :
		sendResult(saveSharedFb());
		break;
	case 'quotes.get.user' :
		sendResult(getQuotesFromUser());
		break;
	default:
		noLuck();
		return;
}


function fbUserApi($dontRegister) {
	if (!isset($_POST['fbid']) or !isset($_POST['fbtoken'])) {
		return array("stat" => "fail", "message" => "FB id, FB token and email must be provided");
	}

	$dbresult = dbQueryRow('SELECT username, id, fbid FROM users WHERE fbid = ?', array($_POST['fbid']));
	if (!$dbresult && $dontRegister) {
		return array("stat" => "fail", "message" => "Facebook user not registered for Pulpit.");
	}
	
	$fbtoken = verifyFbToken($_POST['fbtoken']);
	if (!$fbtoken) {
		return array("stat" => "fail", "message" => "Could not verify Facebook user with Facebook.");
	}
	
	if (!is_array($dbresult)) {
		return registerFbUser($fbtoken);
	} else {
		return loginFbUser($dbresult['id'], $dbresult['username'], $fbtoken);
	}
}

function registerFbUser($fbtoken) {
	$fbUserResult = json_decode(file_get_contents("https://graph.facebook.com/me?access_token=" . $fbtoken));
	$username = $fbUserResult->name;
	$statement = "
				INSERT INTO users 
				(email, username, fbtoken, fbid) VALUES
				(?, ?, ?, ?)
			";
	$arr = array($fbUserResult->email, $username, $fbtoken, $_POST['fbid']);
	try {
		$result = dbExec($statement, $arr);
	} catch (Exception $e) {
		$result = FALSE;
	}
	$uid = dblastId();

	if ($result !== FALSE) {
		$logInResult = loginFbUser($uid, $username, $fbtoken);
		if ($logInResult->stat === "ok") {
			return array("stat" => "ok", "message" => "User " . $username . " registered", "username" => $username, "fbtoken" => $fbtoken, "uid" => $uid, "token" => $pulpittoken);
		} else {
			return $logInResult;
		}
	} else {
		return array("stat" => "fail", "message" => "User could not be registered: " . $e -> getMessage());
	}
}

function loginFbUser($uid, $username, $fbtoken) {
	$pulpittoken = md5(time() . $fbtoken . $_POST['fbid']);
	$statement = "
			UPDATE users 
			SET fbtoken = ?, token = ?, date_lastlogin = datetime('now'), date_tokenexpire = datetime('now', '+7 days')
			WHERE id = ?
		";
	$arr = array($fbtoken, $pulpittoken, $uid);
	try {
		$result = dbExec($statement, $arr);
	} catch (Exception $e) {
		$result = false;
		return array("stat" => "fail", "message" => "Facebook login failed: " . $e->getMessage());
	}
	
	session_id(md5(sha1($uid)));
	session_start();
	$_SESSION['sessionhash'] = sha1(md5($uid));
	
	return array("stat" => "ok", "message" => "Facebook login successful", "username" => $username, "uid" => $uid, "fbtoken" => $fbtoken, "fbid" => $_POST['fbid'], "token" => $pulpittoken);
}

function verifyFbToken($token) {
	$token_url = "https://graph.facebook.com/oauth/access_token?grant_type=fb_exchange_token&"
       . "client_id=514501168566195&client_secret=c89f7623a498ce1a7e76087759011fad&fb_exchange_token=" . $token;
    $response = file_get_contents($token_url);
	$params = null;
    parse_str($response, $params);
	if ($params['access_token'] AND $params['expires'] > 0) {
		return $params['access_token'];
	} else {
		return false;
	}
}


function saveSharedFb() {
	if (!isset($_POST['uid']) or !isset($_POST['postid']) or !isset($_POST['photoid']) or !isset($_POST['qid'])) {
		return array("stat" => "fail", "message" => "Uid, postid, photoid and qid must be provided");
	}
	
	if (!checkUserToken($_POST['uid'], $_POST['token']) or !checkUserSession($_POST['uid'])) {
		return array("stat" => "fail", "message" => "Token or session hash invalid");
	}
	
	$statement = "
				INSERT INTO fbposts
				(qid, photoid, postid) VALUES
				(?, ?, ?)
			";
	$arr = array($_POST['qid'], $_POST['photoid'], $_POST['postid']);
	try {
		$result = dbExec($statement, $arr);
	} catch (Exception $e) {
		$result = false;
	}


	if ($result) {
		return array("stat" => "ok", "message" => "Quote share on FB saved in db.");
	} else {
		return array("stat" => "fail", "message" => "Quote share on FB could not be saved: " . $e -> getMessage());
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
	
	$image = new SimpleImage();
	$image->load(ROOTLOCAL . $path . $filename);
	$originalWidth = $image->getWidth();
	$originalHeight = $image->getHeight();
	
	// maximum Facebook wall post width
	$image->resizeToWidth(403);
	
	$fbfilename = $_POST['template'] . '-' . time() . '-fb' . '.' . $extension;
	$image->save(ROOTLOCAL . $path . $fbfilename, IMAGETYPE_PNG);

	$statement = "
				INSERT INTO quotes 
				(uid, template, path, content, width, height, fbpath, fbwidth, fbheight) VALUES
				(?, ?, ?, ?, ?, ?, ?, ?, ?)
			";
	$arr = array($_POST['uid'], $_POST['template'], $path . $filename, $_POST['content'], $originalWidth, $originalHeight, $path . $fbfilename, $image->getWidth(), $image->getHeight());
	try {
		$result = dbExec($statement, $arr);
	} catch (Exception $e) {
		$result = false;
	}

	if ($result) {
		return array("stat" => "ok", "message" => "Quote successfully saved", "filename" => ROOTWEB . $path . $filename, "url" => ROOTWEB . 'quote.php?q=' . dblastId(), "fbfilename" => ROOTWEB . $path . $fbfilename, "qid" => dblastId());
	} else {
		return array("stat" => "fail", "message" => "Quote could not be registered: " . $e -> getMessage());
	}
}

function getQuotesFromUser() {
	if (!isset($_POST['ownerid']) or $_POST['ownerid'] !== $_POST['uid']) {
		return array("stat" => "fail", "message" => "Ownerid must be provided and identical with logged in user.");
	}
	
	if (!checkUserToken($_POST['uid'], $_POST['token']) or !checkUserSession($_POST['uid'])) {
		return array("stat" => "fail", "message" => "Token or session hash invalid");
	}
	
	$result = dbQueryAll('SELECT * FROM quotes WHERE uid = ?', array($_POST['ownerid']));

	if ($result) {
		return array("stat" => "ok", "result" => $result);
	} else {
		return array("stat" => "fail", "message" => "No quotes could be loaded.");
	}
}

function updateUser() {
	if (!isset($_POST['username'])) {
		return array("stat" => "fail", "message" => "Username must be provided.");
	}
	
	if (!checkUserToken($_POST['uid'], $_POST['token']) or !checkUserSession($_POST['uid'])) {
		return array("stat" => "fail", "message" => "Token or session hash invalid");
	}
	
	try {
		$result = dbExec("UPDATE users SET username = ? WHERE id = ?", array(strip_tags($_POST['username']), $_POST['uid']));
	} catch (Exception $e) {
		return array("stat" => "fail", "message" => "Could not update user: " . $e->getMessage());
	}
	
	return array("stat" => "ok", "message" => "User update successful", "username" => strip_tags($_POST['username']));
}

function deleteUser() {
	if (!isset($_POST['uid'])) {
		return array("stat" => "fail", "message" => "Ownerid must be provided and identical with logged in user.");
	}
	
	if (!checkUserToken($_POST['uid'], $_POST['token']) or !checkUserSession($_POST['uid'])) {
		return array("stat" => "fail", "message" => "Token or session hash invalid");
	}
	
	try {
		$result = dbExec("DELETE FROM users WHERE id = ?", array($_POST['uid']));
	} catch (Exception $e) {
		$result = false;
	}
	if ($result) {
		try {
			$result = dbExec("DELETE FROM quotes WHERE uid = ?", array($_POST['uid']));
		} catch (Exception $e) {
			$result = false;
		}
	}

	if ($result) {
		removedir('./uploads/' . $_POST['uid']);
		return array("stat" => "ok", "message" => "User " . $_POST['uid'] . " deleted.");
	}
	
	return array("stat" => "fail", "message" => "User could not be deleted: " . $e->getMessage());
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
	if ($callbackname) {
		echo $callbackname . '(' . json_encode($object) . ')';
	} else {
		echo json_encode($object);
	}
}



