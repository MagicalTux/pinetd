<?php
/* SysV Sharedmemory-based inter-process communication module
 * $Id$
 * 
 * This module manages the communication between forked processes using shared segments
 * of memory. When a segment is changed, a signal is sent to the target process to let
 * it know something happened.
 */

define("DATA_PID",1);
define("DATA_REHASH",2);
define("DATA_SHUTDOWN",3);
define("DATA_DAEMONS",4);

$main_shared_mem=false;
$shared_mem=false;
$fil=@fopen(HOME_DIR.$pidfile,"r");
if ($fil) {
	$pid=fgets($fil,100);
	fclose($fil);
	if (!posix_kill($pid,0)) {
		unlink(HOME_DIR.$pidfile);
		unset($pid);
	} else {
		$shared_mem = shm_attach($pid,10000,0666);
		if (!@shm_get_var($shared_mem,DATA_PID)) {
			if ($pid != posix_getpid()) {
				shm_remove($shared_mem);
				$shared_mem=false;
			}
		}
		if ($pid == posix_getpid()) {
			$main_shared_mem = $shared_mem;
			$shared_mem = shm_attach($pid,10000,0666); // secondary connexion to the shared space
			shm_put_var($main_shared_mem,DATA_PID,posix_getpid());
			shm_put_var($main_shared_mem,DATA_REHASH,false); // rehash
			shm_put_var($main_shared_mem,DATA_SHUTDOWN,false); // shutdown
			shm_put_var($main_shared_mem,DATA_DAEMONS,array());
		}
	}
}

function comm_child_channel() {
	// makes a child channel
	global $shared_mem,$pidfile;
	$fil=@fopen(HOME_DIR.$pidfile,"r");
	if ($fil) {
		$pid=fgets($fil,100);
		fclose($fil);
	} else {
		return;
	}
	$shared_mem = shm_attach($pid,10000,0666);
}

function comm_free() {
	// free the communication channel
	global $shared_mem;
	if (shm_get_var($shared_mem,DATA_PID)==posix_getpid()) {
		global $main_shared_mem;
		shm_detach($shared_mem);
		shm_remove($main_shared_mem);
	} else {
		shm_detach($shared_mem);
	}
}

function comm_check_reload() {
	global $shared_mem,$server_port;
	$sd=shm_get_var($shared_mem,DATA_REHASH);
	if (!is_array($sd)) return false;
	if (isset($sd[is_null($server_port)?0:$server_port])) return true;
	return false;
}

function comm_check_shutdown() {
	global $shared_mem,$server_port;
	$sd=shm_get_var($shared_mem,DATA_SHUTDOWN);
	if (!is_array($sd)) return false;
	if (isset($sd[is_null($server_port)?0:$server_port])) return true;
	return false;
}

function comm_clear_reload($dest=0) {
	global $shared_mem;
	$sd=shm_get_var($shared_mem,DATA_REHASH);
	if (!is_array($sd)) return;
	unset($sd[$dest]);
	if (count($sd)==0) $sd=false;
	shm_put_var($shared_mem,DATA_REHASH,$sd);
}

function comm_clear_shutdown($dest=0) {
	global $shared_mem;
	$sd=shm_get_var($shared_mem,DATA_SHUTDOWN);
	if (!is_array($sd)) return;
	unset($sd[$dest]);
	if (count($sd)==0) $sd=false;
	shm_put_var($shared_mem,DATA_SHUTDOWN,$sd);
}

function comm_set_reload($dest=0) {
	global $shared_mem;
	$sd = shm_get_var($shared_mem,DATA_REHASH);
	if (!is_array($sd)) $sd = array();
	$sd[$dest] = true;
	shm_put_var($shared_mem,DATA_REHASH,$sd);
}

function comm_set_shutdown($dest=0) {
	global $shared_mem;
	$sd = shm_get_var($shared_mem,DATA_SHUTDOWN);
	if (!is_array($sd)) $sd = array();
	$sd[$dest] = true;
	shm_put_var($shared_mem,DATA_SHUTDOWN,$sd);
}

function comm_export($idnum,$what) {
	global $shared_mem;
	@shm_remove_var($shared_mem,$idnum);
	shm_put_var($shared_mem,$idnum,$what);
}

function comm_import($idnum) {
	global $shared_mem;
	return shm_get_var($shared_mem,$idnum);
}

