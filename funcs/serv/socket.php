<?php

  function socket_init($type,$proto,$port,$ip="0.0.0.0") {
    logstr("Creating socket ip $ip - port $port ...");
    $socket=@socket_create(AF_INET,$type,$proto); // socket
    if (!$socket) {
      $err=socket_last_error();
      $err="Error #".$err." : ".socket_strerror($err);
      logstr("Could not create socket : ".$err);
      exit(40);
    }
    socket_set_option($socket,SOL_SOCKET,SO_REUSEADDR,1);
    if (!@socket_bind($socket,$ip,$port)) {
      $err=socket_last_error();
      $err="Error #".$err." : ".socket_strerror($err);
      logstr("Could not bind socket : ".$err);
      exit(10);
    }
    if (!@socket_listen($socket,15)) {  // 15 backlog requests, this should be suffisent
      $err=socket_last_error();
      $err="Error #".$err." : ".socket_strerror($err);
      logstr("Could not set socket to listening state : ".$err);
      exit(30);
    }
    return $socket;
  }
  
  function sread(&$socket) {
    if (!$socket["state"]) return false;
	socket_set_block($socket["sock"]);
    $dat=@socket_read($socket["sock"],8192,PHP_NORMAL_READ);
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
    $str=str_replace("\r","",$str);
    $str=str_replace("\n","",$str);
    if (isset($socket["log_fp"])) {
      fputs($socket["log_fp"],"> ".$str."\r\n");
    }
    $str.="\r\n";
    if (!$send) {
      $dat=0;
      while ($dat != strlen($str)) {
        $dat=@socket_write($socket["sock"],$str,strlen($str));
        if ($dat === FALSE) {
          $socket["state"]=false; // my socket is broken
          return;
        }
        $str=substr($str,$dat); // remove written bytes
      }
    } else {
      @socket_send($socket["sock"],$str,strlen($str),0);
    }
  }
  function sclose(&$socket) {
    @socket_shutdown($socket["sock"],2); // signal end of comm
    @socket_close($socket["sock"]);
    $socket["state"]=false;
  }
  
  // $socket : array
  // ...["sock"] : socket object
  // ...["state"] : true : good socket; false : bad socket
  // ...["remote_ip"] : remote ip address
  // ...["remote_host"] : remote hostname
  // ...["remote_port"] : remote port
  