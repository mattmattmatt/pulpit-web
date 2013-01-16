<?php

include_once '_globals.php';
include_once 'dbwrapper.php';

function noLuck($reason) {
	global $smarty;
	$smarty -> assign('message', $reason);
	$smarty -> display('404.tpl.html');
}

if (!isset($_GET['q']) or !preg_match("/^\d+$/", $_GET['q'])) {
	noLuck('Invalid quote id.');
	return;
}

$quoteid = $_GET['q'];

$dbresult = dbQueryRow('SELECT quotes.uid, quotes.path, quotes.fbpath, quotes.content, users.username, quotes.date FROM quotes INNER JOIN users ON quotes.uid=users.id WHERE quotes.id = ?', array($quoteid));

if (!$dbresult) {
	noLuck('Quote not found.');
}

$shortcontent = $dbresult['content'];
if (strlen($shortcontent) > 18) {
	$shortcontent = substr($shortcontent, 0, 15) . '...';
}

$smarty -> assign('pathtoimg', ROOTWEB . $dbresult['path']);
$smarty -> assign('pathtofbimg', ROOTWEB . $dbresult['fbpath']);
$smarty -> assign('dbresult', $dbresult);
$smarty -> assign('shortcontent', $shortcontent);
$smarty -> assign('currenturl', ROOTWEB . 'quote.php?q=' . $quoteid);
$smarty -> display('quote.tpl.html');

