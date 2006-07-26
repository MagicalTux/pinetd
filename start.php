#!/bin/php -q
<?php

echo "Loading phpInetd v1.0 ...\n";
if (!defined('HOME_DIR')) define('HOME_DIR', dirname(__FILE__).'/');
define('DAEMON_PARENT_PID', getmypid());

if (!file_exists(HOME_DIR.'config.php')) {
	echo "Error : please configure before running.\n";
	exit;
}

set_time_limit(0);
require(HOME_DIR."config.php");
chdir(HOME_DIR);
if (posix_getuid() != 0) die("This program must be started as root and not ".posix_getlogin().".\n");

$fil = @fopen($pidfile,"r");
if ($fil) {
	$oldpid=fgets($fil,25);
	fclose($fil);
	if (posix_kill($oldpid,0)) die("Found running process at pid $oldpid - aborting loading...\n");
}

$dir=opendir("funcs/base");
while ($fil=readdir($dir)) {
	if (substr($fil,-4)==".php") { require("funcs/base/".$fil); }
}
closedir($dir);

$pid=cfork();
if ($pid == -1) die("Error while trying to fork.\n");
if ($pid) {
	sleep(1);
	if (!posix_kill($pid,0)) die("Could not detach from terminal.\n");
	echo "Started main daemon pid #".$pid." ...\n";
	$fil=@fopen($pidfile,"w");
	if (!$fil) { posix_kill($pid,SIGKILL); die("Couldn't make pidfile....\n"); }
	fputs($fil,$pid);
	fclose($fil);
	exit;
}
sleep(2);


// we're child
logstr("Main child init. Loading server childs...");
chdir(HOME_DIR);
$dir=@opendir("daemon");
if (!$dir) { logstr("Error while reading daemon directory. Aborting..."); exit(); }
$daemons=array();
while ($fil=readdir($dir)) {
	if (substr($fil,-4)==".php") {
		$fil=substr($fil,0,strlen($fil)-4);
		$fil2=(int)$fil;
		if ($fil == $fil2) $daemons[$fil2]=0;
	}
}

// check for subprocesses
$dir=@opendir('subprocess');
while($fil=readdir($dir)) {
	if (substr($fil,-4)==".php") {
		$fil=substr($fil,0,strlen($fil)-4);
		$daemons[$fil]=0;
	}
}

foreach($daemon_noload as $d) if (isset($daemons[$d])) unset($daemons[$d]);

if (!$daemons) {
	logstr("FATAL: no daemon defined !");
}
$res=0;
$server_port=null;
include("funcs/sysv_shared.php"); // method for comm
foreach($daemons as $port=>$pid) {
	$pid=cfork(false);
	if ($pid == -1) {
		logstr("Error forking service $port ...");
		$daemons[$port]=-(time()+10); // retry in 10 secs
	} elseif ($pid) {
		$daemons[$port]=$pid;
		$res++;
	} else {
		$server_port=$port;
		comm_child_channel();
		logstr("Service $port has just forked.");
		break;
	}
}

if (is_null($server_port)) {
	// We're still parent
	logstr("System ready. $res services successfully loaded");
	$shutdown=false;
}

while (is_null($server_port)) {
	// as long as we don't fork
	sleep(1); // idle time : 1sec
	$info=array();
	foreach($daemons as $port=>$pid) {
		$pid2=$pid;
		if ($pid>0) {
			$res=pcntl_waitpid($pid,$status,WNOHANG);
			if ($res != 0) {
				// le child est mort...vive le child !
				if (pcntl_wifexited($status)) {
					$wait=pcntl_wexitstatus($status);
				} else {
					$wait=2;
				}
				$daemons[$port]=-(time()+$wait);
				if ($wait==0) $daemons[$port]=0;
				logstr("Child port $port [$pid] dead. Sheduling restart to time + $wait");
			}
		}
		$pinfo=array();
		$pinfo["pid"]=$pid2;
		$pinfo["up"]=($pid>0);
		$info[$port]=$pinfo;
		unset($pinfo);
	}
	comm_export(DATA_DAEMONS,$info);
	if (comm_check_shutdown()) {
		comm_clear_shutdown();
		logstr("Shutting down (requested)...");
		$d=$daemons;
		$daemons=array();
		foreach($d as $port=>$pid) {
			if ($pid>0) { comm_set_shutdown($port); posix_kill($pid,0); } // posix kill would force the process to wake up
			if ($pid<1) unset($d[$port]);
		}
		// signal broadcasted...
		$max=time()+4; // maxi 4secs to exit
		while(count($d)>0) {
			reset($d);
			while(list($port,$pid)=each($d)) {
				$sta=pcntl_waitpid($pid,$status,WNOHANG);
				if ($sta==0) {
					unset($d[$port]);
				} elseif ($max<time()) {
					logstr("Killing service $port [$pid] (stop timeout)");
					posix_kill($pid,SIGKILL);
					unset($d[$port]);
				} else {
					unset($d[$port]);
				}
			}
		}
		comm_free();
		unlink(HOME_DIR.$pidfile);
		exit;
	}
	if (comm_check_reload()) {
		logstr("Trying to reload config file...");
		comm_clear_reload();
		chdir(HOME_DIR);
		if (!file_exists("config.php")) {
			logstr("Error : configuration file not found !");
		} else {
			require("config.php");
			$d=array();
			$d2=$daemons;
			$d3=array();
			$dir=@opendir("daemon");
			if (!$dir) { logstr("Error while reading daemon directory. Aborting rehash."); } else {
				while ($fil=readdir($dir)) {
					if (substr($fil,-4)==".php") {
						$fil=substr($fil,0,strlen($fil)-4);
						$fil2=$fil-4;
						$fil2+=4;
						if ($fil == $fil2) {
							if (!isset($d2[$fil2])) $d2[$fil2]=0;
							$d[$fil2]=true;
						}
					}
				}
				closedir($dir);
				$dir=@opendir('subprocess');
				while ($fil=readdir($dir)) {
					if (substr($fil,-4)==".php") {
						$fil=substr($fil,0,strlen($fil)-4);
						$d[$fil]=true;
					}
				}
				closedir($dir);
				foreach($daemon_noload as $da) if (isset($d2[$da])) unset($d2[$da]);
				reset($d2);
				while(list($port,$state)=each($d2)) {
					if ( (!isset($d[$port])) and ($state>0) ) {
						// shutdown daemon 
						comm_set_shutdown($port);
						$d3[$port]=$state;
						unset($daemons[$port]);
					} elseif (!isset($daemons[$port])) {
						$daemons[$port]=0; // démarrage immédiat !
					} elseif ($daemons[$port]>0) {
						comm_set_reload($port); // force rehash...
					}
				}
				// check closing
				$exp=time()+4; // 4 secs for closing
				while (count($d3)>0) {
					reset($d3);
					sleep(1);
					while(list($port,$pid)=each($d3)) {
						if ($exp<time()) {
							posix_kill($pid,SIGKILL);
							unset($d3[$port]);
						} elseif (!posix_kill($pid,0)) {
							unset($d3[$port]);
						}
					}
				}
			}
		}
		unset($d);
		unset($d2);
		unset($d3);
	}
	// final while !
	foreach($daemons as $port=>$pid) {
		if (!is_null($server_port)) break;
		if ($pid == 0) {
			// redémarrage immédiat !
			$pid=cfork(false);
			if ($pid == -1) {
				logstr("Error forking for service $port ...");
				$daemons[$port]=-(time()+10); // retry in 10 secs
			} elseif ($pid) {
				$daemons[$port]=$pid;
			} else {
				$server_port=$port;
				logstr("Server for port $port forked. Status ok");
			}
		} elseif ($pid<0) {
			$pid = abs($pid);
			if ($pid < time()) {
				$pid=cfork(false);
				if ($pid == -1) {
					logstr("Error forking for service $port ...");
					$daemons[$port]=-(time()+10); // retry in 10 secs
				} elseif ($pid) {
					$daemons[$port]=$pid;
				} else {
					$server_port=$port;
					logstr("Server for port $port forked. Status ok");
				}
			}
		}
	}
}


// child process...
$dir=opendir("funcs/serv");
while ($fil=readdir($dir)) {
	if (substr($fil,-4)==".php") require("funcs/serv/".$fil);
}
closedir($dir);

// step 1 : include the port file for informations
if (is_numeric($server_port)) {
	if (!include("daemon/".$server_port.".php")) {
		logstr("FATAL : could not load daemon for port $server_port ...");
		exit(60); // 60 secs before retry
	}
} else {
	if (!include("subprocess/".$server_port.".php")) {
		logstr("FATAL : could not load subprocess $server_port ...");
		exit(60);
	}
	$mysql_cnx=getsql();
	while(1) {
		sleep(1);
		if (comm_check_shutdown()) {
			comm_clear_shutdown($server_port);
			if (function_exists('func_shutdown')) func_shutdown();
			logstr("Closing subprocess $server_port [".posix_getpid()."] : requested.");
			comm_free();
			exit;
		}
		if (comm_check_reload()) {
			comm_clear_reload($server_port);
			chdir(HOME_DIR);
			if (file_exists("config.php")) require("config.php");
			if (function_exists('func_reload')) func_reload();
		}
		main_loop();
	}
}
$master_socket=socket_init(PINETD_SOCKET_TYPE, $server_port,$bind_ip);
// notre socket est prêt ! :p
$client=false;
$socket=false;
$clients=array();
while(!$client) { // mit sur true si le thread est forké et deviens un thread client
	$socket=false;
	$r = array($master_socket);
	$e = $r;
	$w = NULL;
	if ( @stream_select($r, $w, $e, 2) > 0) {
		$socket=stream_socket_accept($master_socket, 0, $addr);
	}
	if (comm_check_shutdown()) {
		comm_clear_shutdown($server_port);
		logstr("Closing server $server_port [".posix_getpid()."] : requested.");
		fclose($master_socket);
		comm_free();
		exit;
	}
	if (comm_check_reload()) {
		comm_clear_reload($server_port);
		logstr("Reloading server config...");
		chdir(HOME_DIR);
		if (file_exists("config.php")) require("config.php");
	}
	foreach($clients as $pid=>&$data) {
		$res=pcntl_waitpid($pid,$status,WNOHANG);
		if ($res>0) {
			// le client est déconnecté
			unset($clients[$pid]);
		}
		if ($data['start']<(time()-7200)) {
			if ($data['start']<(time()-7320)) {
				// We already sent the TERM signal 120 seconds ago (2 minutes)
				posix_kill($pid, SIGKILL);
			} else {
				if (!$data['termsignal']) {
					// child has been running for two hours, that's too much
					posix_kill($pid, SIGTERM);
					$data['termsignal']=true;
				}
			}
		}
	}
	if ($socket) {
		$new=$socket;
		$socket=array();
		$socket["sock"]=$new;
		unset($new);
		// $addr = 127.0.0.1:39161
		list($addr, $port) = explode(':', $addr);
		$socket["remote_ip"]=$addr;
		$socket["remote_port"]=$port;
		$socket["state"]=true;
		$accept=true;
		if (function_exists("proto_check")) $accept=proto_check($socket,$clients);
		if ($accept) {
			logstr("Service $server_port - connexion from ".$socket["remote_ip"]);
			$pid=cfork(false);
			if ($pid == -1) {
				logstr("Service $server_port - could not fork for client ".$socket["remote_ip"]);
				swrite($socket,$connect_error);
				sclose($socket);
				$socket=false;
				unset($addr);
				unset($port);
			} elseif ($pid) {
				$socket=false;
				$clients[$pid]=array("ip" => $addr, 'start'=>time());
				unset($addr);
				unset($port);
			} else {
				$client=true;
			}
		}
	}
	comm_update_info($server_port, PINETD_SOCKET_TYPE, count($clients));
}
fclose($master_socket);
$mysql_cnx=getsql();
$socket['mysql'] = $mysql_cnx;
proto_welcome($socket);
$socket["remote_host"] = gethostbyaddr($socket["remote_ip"]);
if (isset($socket["log_fp"])) {
	fputs($socket["log_fp"],"DNS answer : ".$socket["remote_ip"]." --> ".$socket["remote_host"]."\r\n");
}
if (function_exists("proto_welcome2")) proto_welcome2($socket);
while(1) {
	if (isset($socket_timeout)) {
		$num=@stream_select($r=array($socket["sock"]),$w=NULL,$e=NULL,$socket_timeout);
		if (!$num) {
			if (function_exists("proto_timeout")) proto_timeout($socket);
			sclose($socket);
			exit;
		}
	}
	$dat=sread($socket);
	if (trim($dat)!="") {
		$pos=strpos($dat," ");
		if (!$pos) $pos=strlen($dat);
		$cmd=substr($dat,0,$pos);
		if (function_exists("proto_handler")) {
			proto_handler($socket,$cmd,$dat);
		} else {
			$cmd=strtolower($cmd);
			if (function_exists("pcmd_".$cmd)) {
				$cmd="pcmd_".$cmd;
				$cmd($socket,$dat);
			} else {
				swrite($socket,$unknown_command);
			}
		}
	}
	if ($socket["state"] == false) {
		sclose($socket);
		exit;
	}
}

