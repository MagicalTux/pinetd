<?php
/* logsend.php
 * $Id$
 *
 * Log a string to the main logfile (using UNIX APPEND prop)
 */

function logstr($str) {
	$fil=@fopen(HOME_DIR."log/main-".date("Y-m-d").".log","a");
	if (!$fil) return;
	$str="[".date("H:i:s")."] ".$str;
	fputs($fil,$str."\r\n");
	fclose($fil);
}

