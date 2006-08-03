<?php
/* portable Mail Daemon - Mail Transfert Agent
 * $Id$
 * 
 * This script runs as a background task
 */

$iterate=5;
$mta_agents = array();

function main_loop() {
	// Main loop, called every 1 second
	if ($iterate-->0) return; // only works 1 time every 5 seconds :)
	$iterate=5;
	check_queue();
}

function check_queue() {
	global $pmaild_mta_max_processes, $mta_agents, $pmaild_mta_thread_start_threshold;
	// check for dead childs
	foreach($mta_agents as $pid=>&$agent) {
		$sta=pcntl_waitpid($pid,$status,WNOHANG);
		if ($sta!=0) unset($mta_agents[$pid]);
	}
	if (count($mta_agents)>=$pmaild_mta_max_processes) return; // too many agents running
	$req = 'SELECT COUNT(1) ';
	$req.= 'FROM `'.PHPMAILD_DB_NAME.'`.`mailqueue` ';
	$req.= 'WHERE `next_attempt` > NOW() OR `next_attempt` IS NULL';
	$res = @mysql_query($req);
	if ((!$res) && (!$mta_agents)) exit(180); // Database not installed?
	$res = @mysql_fetch_row($res);
	$count = $res[0];
	if ($count==0) return; // nothing to send out, let's go back to sleep
	$to_start = (int)($count/$pmaild_mta_thread_start_threshold);
	if ($to_start==0) $to_start=1; // at least 1 server if nothing's running
	if ($to_start>$pmaild_mta_max_processes) $to_start=$pmaild_mta_max_processes; // do not go outbound
	if (count($mta_agents)>=$to_start) return; // we already have the right amount of agents
	for($i=count($mta_agents);$i<$to_start;$i++) start_mta_agent();
}

function core_mta_agent() {
	$sql = getsql();
	sleep(10);
}

function start_mta_agent() {
	global $mta_agents;
	$pid = cfork(false);
	if ($pid==-1) {
		// ARGH, couldn't start MTAgent - will retry in 5 seconds if still too many mails to send
		return;
	} elseif ($pid) {
		$mta_agents[$pid] = true;
	} else {
		core_mta_agent();
		exit; // if we forked, we don't want to return to main loop
	}
}

