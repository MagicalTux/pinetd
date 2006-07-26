<?php

function socket_init($type,$port,$ip="0.0.0.0") {
	global $ssl_settings;
	logstr("Creating '.$type.' socket ip $ip - port $port ...");
	if (isset($ssl_settings[$port])) {
		$c = stream_context_create($ssl_settings[$port]);
		$socket = stream_socket_server($type.'://'.$ip.':'.$port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $c);
	} else {
		$socket = stream_socket_server($type.'://'.$ip.':'.$port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
	}
	if (!$socket) {
		$err='Error #'.$errno.': '.$errstr;
		logstr("Could not set socket to listening state : ".$err);
		exit(30);
	}
//	socket_set_option($socket,SOL_SOCKET,SO_REUSEADDR,1); // <-- no equivalent with streams
	return $socket;
}

function sread(&$socket) {
	if (!$socket["state"]) return false;
	stream_set_blocking($socket['sock'], 1);
	$dat=@fgets($socket["sock"],8192);
	if ( (!$dat) and ($dat!=="\0")) { $socket["state"]=false; return false; }
	if ( ($socket['last_was_cr']) && ($dat=="\n")) {
		$socket['last_was_cr']=false;
		return sread($socket);
	}
	$socket['last_was_cr']=false;
	if (substr($dat,-1)=="\r") $socket['last_was_cr']=true;
	
	$socket["lastread"]=$dat; // raw data
	$dat=str_replace("\r","",str_replace("\n","",$dat));
	if ( (isset($socket["log_fp"])) and ($dat!="") ) {
		fputs($socket["log_fp"],"< ".$dat."\r\n");
	}
	return $dat;
}

function swrite(&$socket,$str,$send=false) {
	stream_set_blocking($socket['sock'], 1);
	$str=str_replace("\r","",$str);
	$str=str_replace("\n","",$str);
	if (isset($socket["log_fp"])) {
		fputs($socket["log_fp"],"> ".$str."\r\n");
	}
	$str.="\r\n";
	if (!$send) {
		$dat=0;
		while ($dat != strlen($str)) {
			$dat=@fwrite($socket["sock"],$str);
			if (($dat === FALSE) || ($dat===0)) {
				$socket["state"]=false; // my socket is broken
				return;
			}
			$str=substr($str,$dat); // remove written bytes
		}
	} else {
		@stream_socket_sendto($socket["sock"],$str,strlen($str),0);
	}
}

function sclose(&$socket) {
//	@socket_shutdown($socket["sock"],2); // signal end of comm
	fflush($socket['sock']);
	@fclose($socket["sock"]);
	$socket["state"]=false;
}

// $socket : array
// ...["sock"] : socket object
// ...["state"] : true : good socket; false : bad socket
// ...["remote_ip"] : remote ip address
// ...["remote_host"] : remote hostname
// ...["remote_port"] : remote port

