<?php
/* portable Mail Daemon - Mail Transfert Agent
 * $Id$
 * 
 * This script runs as a background task
 */

$iterate=1;
$mta_agents = array();

function make_uniq($subpath,$domain=null,$account=null) {
	global $servername;
	// make uniq filename in
	// PHPMAILD_STORAGE/$subpath/
	$path=PHPMAILD_STORAGE.'/'.$subpath;
	if (!is_null($domain)) $path.='/'.substr($domain,-1).'/'.substr($domain,-2).'/'.$domain;
	if (!is_null($account)) {
		$acc=dechex($account);
		while(strlen($acc)<4) $acc='0'.$acc;
		$path.='/'.substr($acc,-1).'/'.substr($acc,-2).'/'.$acc;
	}
	if (!is_dir($path)) mkdir($path, 0755, true);
	$path.='/';
	$tmp=explode(' ',microtime());
	$path.=$tmp[1].'.'.substr($tmp[0],2).'.'.code(5).getmypid().'.'.$servername;
	return $path;
}

function mta_log($str) {
	$fil = fopen(HOME_DIR.'log/pmaild-'.date('Ymd').'.log','a');
	fputs($fil, '['.date(DATE_RFC822).'] '.$str."\r\n");
	fclose($fil);
}

function main_loop() {
	global $iterate;
	// Main loop, called every 1 second
	if ($iterate-->=0) return; // only works 1 time every 5 seconds :)
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
	$sql = getsql();
	if (rand(0,100)>95) {
		// Database check : did a pid failed an attempt to send an email ? if so, cleanup db pid number for a retry
		$req = 'UPDATE `'.PHPMAILD_DB_NAME.'`.`mailqueue` SET `pid` = NULL WHERE `pid` ';
		if ($mta_agents) {
			$req.='NOT IN ('.implode(',',array_keys($mta_agents)).')';
		} else {
			$req.='IS NOT NULL';
		}
		@mysql_query($req);
	}
	$req = 'SELECT COUNT(1) ';
	$req.= 'FROM `'.PHPMAILD_DB_NAME.'`.`mailqueue` ';
	$req.= 'WHERE (`next_attempt` > NOW() OR `next_attempt` IS NULL) AND `pid` IS NULL';
	$res = @mysql_query($req, $sql);
	if ((!$res) && (!$mta_agents)) {
		mta_log('Failed to select from table mailqueue: '.mysql_error());
		@mysql_close($sql);
		exit(180); // Database not installed?
	}
	$res = @mysql_fetch_row($res);
	@mysql_close($sql);
	$count = $res[0];
	if ($count==0) return; // nothing to send out, let's go back to sleep
	$to_start = (int)($count/$pmaild_mta_thread_start_threshold);
	if ($to_start==0) $to_start=1; // at least 1 server if nothing's running
	if ($to_start>$pmaild_mta_max_processes) $to_start=$pmaild_mta_max_processes; // do not go outbound
	if (count($mta_agents)>=$to_start) return; // we already have the right amount of agents
	mta_log('Starting '.($to_start-count($mta_agents)).' daemon(s) for processing of '.$count.' mails');
	for($i=count($mta_agents);$i<$to_start;$i++) start_mta_agent();
}

function core_mta_send_attempt(&$info) {
	global $servername;
	// We are allowed to change $info['last_error'] to put an human-readable error message
	// We can also alter is_fatal to true if we encounter a fatal error. is_fatal shouldn't be set to false
	// if it's not a fatal error, as it may become one because of too many attempts
	
	// first, we take the info from $info['to']. We need to select MX servers for the domain name
	$to = $info['to'];
	$pos = strrpos($to, '@');
	if ($pos===false) {
		$info['is_fatal'] = true;
		$info['last_error'] = 'Invalid email in TO field. No @ symbol found.';
		return false;
	}
	$domain = substr($to, $pos+1); // only extract domain
	if (!getmxrr($domain, $mx_list)) $mx_list = array($domain); // Get MX records, or put domain itself as MX if failed
	// Ok, now we have X mx records we can try, so let's work !
	foreach($mx_list as $mx) {
		// connect to MX...
		$sock = fsockopen($mx, 25, $errno, $errstr, 25); // 25 seconds timeout for connect should be OK
		if (!$sock) { // connection failed - non fatal error
			$info['last_error'] = 'Connect to '.$mx.' failed: '.$errstr;
			continue;
		}
		// let's read SMTP welcome...
		$msg = fgets($sock, 4096);
		if ( ($msg{0}=='5') || ($msg{0}=='4')) {
			// An error hapenned
			fclose($sock);
			$info['last_error'] = 'Error while connecting to SMTP: '.$msg;
			if ($msg{0}=='5') {
				$info['is_fatal'] = true;
				return false;
			}
			continue;
		}
		fputs($sock, 'HELO '.$servername."\r\n"); // TODO: implement EHLO and read capabilities, then try to use them
		// read answer to HELO
		$msg = fgets($sock, 4096);
		if ( ($msg{0}=='5') || ($msg{0}=='4')) {
			// An error hapenned
			fclose($sock);
			$info['last_error'] = 'Got error while sending EHLO/HELO: '.$msg;
			if ($msg{0}=='5') {
				$info['is_fatal'] = true;
				return false;
			}
			continue;
		}
		fputs($sock, 'MAIL FROM: <'.$info['from'].">\r\n");
		// read answer to MAIL FROM
		$msg = fgets($sock, 4096);
		if ( ($msg{0}=='5') || ($msg{0}=='4')) {
			// An error hapenned
			fclose($sock);
			$info['last_error'] = 'Got error while sending MAIL FROM: '.$msg;
			if ($msg{0}=='5') {
				$info['is_fatal'] = true;
				return false;
			}
			continue;
		}
		fputs($sock, 'RCPT TO: <'.$info['to'].">\r\n");
		// read answer to RCPT TO
		$msg = fgets($sock, 4096);
		if ( ($msg{0}=='5') || ($msg{0}=='4')) {
			// An error hapenned
			fclose($sock);
			$info['last_error'] = 'Got error while sending RCPT TO: '.$msg;
			if ($msg{0}=='5') {
				$info['is_fatal'] = true;
				return false;
			}
			continue;
		}
		fputs($sock, 'DATA'."\r\n");
		// read answer to DATA
		$msg = fgets($sock, 4096);
		if ( ($msg{0}=='5') || ($msg{0}=='4')) {
			// An error hapenned
			fclose($sock);
			$info['last_error'] = 'Got error while sending DATA: '.$msg;
			if ($msg{0}=='5') {
				$info['is_fatal'] = true;
				return false;
			}
			continue;
		}
		// Now, send the mail itself
		$file = PHPMAILD_STORAGE.'/mailqueue/'.$info['mlid'];
		$fil = fopen($file, 'r');
		if (!$fil) {
			$info['last_error'] = 'Couldn\'t open mail data for MLID='.$info['mlid'];
			$info['is_fatal'] = true;
			return false;
		}
		while(!feof($fil)) {
			$lin = fgets($fil, 4096);
			if ($lin{0}=='.') $lin = '.'.$lin;
			fputs($sock, $lin); // should already include the linebreak
		}
		fputs($sock, ".\r\n"); // END OF MAIL
		// Read answer to END OF DATA
		$msg = fgets($sock, 4096);
		if ( ($msg{0}=='5') || ($msg{0}=='4')) {
			// An error hapenned
			fclose($sock);
			$info['last_error'] = 'Got error after mail was sent: '.$msg;
			if ($msg{0}=='5') {
				$info['is_fatal'] = true;
				return false;
			}
			continue;
		}
		// OK, mail was delivered !!! YAY!
		$info['data_reply'] = $msg;
		fputs($sock, "QUIT\r\n");
		fgets($sock, 4096); // answer to quit (we don't care about it)
		fclose($sock);
		return true;
	}
	return false;
}

function core_mta_agent() {
	global $pmaild_mta_max_attempt, $pmaild_mta_mail_max_lifetime, $servername;
	$sql = getsql();
	// Select a mail that needs to be sent
	$req = 'SELECT `mlid`, `from`, `to`, UNIX_TIMESTAMP(`queued`) AS `queued`, `attempt_count`, UNIX_TIMESTAMP(`last_attempt`) AS `last_attempt`, `last_error` ';
	$req.='FROM `'.PHPMAILD_DB_NAME.'`.`mailqueue` ';
	$req.= 'WHERE (`next_attempt` > NOW() OR `next_attempt` IS NULL) AND `pid` IS NULL';
	$res = @mysql_query($req);
	$res = @mysql_fetch_assoc($res);
	if (!$res) {
		// no record found to be sent, so we stop here!
		exit;
	}
	$info = $res;
	// update record
	$req = 'UPDATE `'.PHPMAILD_DB_NAME.'`.`mailqueue` SET `pid` = '.getmypid().' WHERE `mlid` = \''.mysql_escape_string($info['mlid']).'\' AND `pid` IS NULL';
	// TODO: Add ORDER BY RAND() ?
	$res = @mysql_query($req);
	if (mysql_affected_rows($sql)==0) return; // another process got this mail before us
	// ok, now we know that we're alone on this mail... we have to send it, or restore pid back to null...
	$info['is_fatal'] = ($pmaild_mta_max_attempt <= $info['attempt_count']); // current "error fatality" state
	mta_log('Trying to send mail '.$info['mlid'].' to '.$info['to']);
	if (!core_mta_send_attempt($info)) {
		// ARGH! Failed!
		$info['last_error'] = rtrim($info['last_error']);
		mta_log('Sending mail '.$info['mlid'].' to '.$info['to'].' failed: '.$info['last_error']);
		if ($info['is_fatal']) {
			// We'll have to give up on this...
			if (!is_null($info['from'])) {
				// Reverse the mail, and send it back to the sender
				$out = make_uniq('mailqueue');
				$fil = fopen($out, 'w');
				$in = fopen(PHPMAILD_STORAGE.'/mailqueue/'.$info['mlid'], 'r');
				fputs($fil, 'Message-ID: <'.basename($out).'@'.$servername.">\r\n");
				fputs($fil, 'Date: '.date(DATE_RFC822)."\r\n");
				fputs($fil, 'From: MAILER-DAEMON <mailer-daemon@'.$servername.">\r\n");
				fputs($fil, 'User-Agent: pMaild 1.0'."\r\n");
				fputs($fil, 'To: '.$info['from']."\r\n");
				fputs($fil, 'Subject: Failed attempt to send email'."\r\n");
				fputs($fil, "\r\n");
				fputs($fil, "Hello,\r\n\r\nHere is the mailer daemon at $servername. I'm sorry\r\n");
				fputs($fil, "I couldn't transmit your mail. This is a fatal error, so I gave up\r\n\r\n");
				fputs($fil, "Here are some details about the error:\r\n");
				fputs($fil, '  '.$info['last_error']."\r\n\r\n");
				fputs($fil, "And here are the headers of your mail, for reference:\r\n\r\n");
				if ($in) {
					while(!feof($in)) {
						$lin = fgets($in, 4096);
						$lin = rtrim($lin);
						if ($lin=='') break; // end of headers
						fputs($fil, $lin."\r\n");
					}
				}
				fclose($in);
				fclose($fil);
				$req = 'INSERT INTO `'.PHPMAILD_DB_NAME.'`.`mailqueue` SET ';
				$req.= '`mlid` = \''.mysql_escape_string(basename($out)).'\', `to` = \''.mysql_escape_string($info['from']).'\', ';
				$req.= '`queued` = NOW()';
				@mysql_query($req);
			}
			// delete the mail we tried to send
			@unlink(PHPMAILD_STORAGE.'/mailqueue/'.$info['mlid']);
			@mysql_query('DELETE FROM `'.PHPMAILD_DB_NAME.'`.`mailqueue` WHERE `mlid` = \''.mysql_escape_string($info['mlid']).'\'');
			return;
		}
		if (is_null($info['last_attempt'])) {
			// special case, we'll retry in 2 minutes~
			$req = 'UPDATE `'.PHPMAILD_DB_NAME.'`.`mailqueue` SET ';
			$req.= '`next_attempt` = DATE_ADD(NOW(), INTERVAL 2 MINUTE), `pid` = NULL, ';
			$req.= '`last_attempt` = NOW(), ';
			$req.= '`last_error` = \''.mysql_escape_string($info['last_error']).'\' ';
			$req.= 'WHERE `mlid` = \''.mysql_escape_string($info['mlid']).'\'';
		} else {
			// compute next attempt time...
			$next_attempt = time() + (($pmaild_mta_mail_max_lifetime/$pmaild_mta_max_attempt) * 3600);
			$req = 'UPDATE `'.PHPMAILD_DB_NAME.'`.`mailqueue` SET ';
			$req.= '`next_attempt` = FROM_UNIXTIME('.$next_attempt.'), `pid` = NULL, ';
			$req.= '`last_attempt` = NOW(), ';
			$req.= '`attempt_count` = `attempt_count` + 1, ';
			$req.= '`last_error` = \''.mysql_escape_string($info['last_error']).'\' ';
			$req.= 'WHERE `mlid` = \''.mysql_escape_string($info['mlid']).'\'';
		}
		@mysql_query($req);
	} else {
		// the mail was sent, we should delete its record & file
		mta_log('Sending mail '.$info['mlid'].' to '.$info['to'].' SUCCESS: '.rtrim($info['data_reply']));
		@unlink(PHPMAILD_STORAGE.'/mailqueue/'.$info['mlid']);
		@mysql_query('DELETE FROM `'.PHPMAILD_DB_NAME.'`.`mailqueue` WHERE `mlid` = \''.mysql_escape_string($info['mlid']).'\'');
	}
}

function start_mta_agent() {
	global $mta_agents;
	$pid = cfork(false);
	if ($pid==-1) {
		// ARGH, couldn't start MTAgent - will retry in a few seconds if still too many mails to send
		return;
	} elseif ($pid) {
		$mta_agents[$pid] = true;
	} else {
		while(1) {
			core_mta_agent(); // if we forked, we don't want to return to main loop (so we have a tiny infinite loop)
		}
	}
}

