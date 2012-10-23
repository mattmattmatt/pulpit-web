<?php

	define("ROOTLOCAL", "/daten/documents/coding/xampp/htdocs/quotify/pulpit-web/");
	define("DATABASE", ROOTLOCAL . "db/database.db3");
	define("ROOTWEB", "http://localhost/quotify/website/");
	
	require_once(ROOTLOCAL . 'smarty/Smarty.class.php');
	
	$smarty = new Smarty();
	
	$smarty->setTemplateDir(ROOTLOCAL . 'templates/');
	$smarty->setCompileDir(ROOTLOCAL . 'smarty/templates_c/');
	$smarty->setConfigDir(ROOTLOCAL . 'smarty/configs/');
	$smarty->setCacheDir(ROOTLOCAL . 'smarty/cache/');	
?>