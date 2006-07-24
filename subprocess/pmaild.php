<?php
/* portable Mail Daemon - Mail Transfert Agent
 * $Id$
 * 
 * This script runs as a background task
 */

$iterate=5;

function main_loop() {
	// Main loop, called every 1 second
	if ($iterate-->0) return; // only works 1 time every 5 seconds :)
	$iterate=5;
	check_queue();
}

function check_queue() {
	return;
	$req = 'SELECT COUNT(1) ';
	$req.= 'FROM `'.PHPMAILD_DB_NAME.'`.`mailqueue` ';
	$req.= 'WHERE `next_attempt` > NOW() OR `next_attempt` IS NULL';
	$res = @mysql_query($req);
	$res = @mysql_fetch_row($res);
	$count = $res[0];
}

