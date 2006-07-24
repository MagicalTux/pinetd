<?
// SimpleFTP server v1.0r2
//
//	***** CODE NAME : KASUMI
//
// vars :
// $current_socket : socket en cours
// $mysql_cnx : connexion MySQL
// $servername : nom du serv (eg. Ringo.FF.st ) 

$socket_type=SOCK_STREAM;
$socket_proto=SOL_TCP;
$connect_error="500 Server not ready";
$unknown_command="500 Wakari-masen";
$socket_timeout=300; // 5 min

$srv_info=array();
$srv_info["name"]="SimpleFTP Server v1.0r2 by MagicalTux <MagicalTux@gmail.com>";
$srv_info["version"]="1.0.2";

function proto_check(&$socket,$clients) {
	global $max_users,$max_users_per_ip;
	if ($max_users == 0) {
		swrite($socket,"500 This FTP server is disabled.");
		sclose($socket);
		return false;
	}
	if (count($clients)>=$max_users) {
		swrite($socket,"500 Too many clients (".$max_users.") connected. Please try again later.");
		sclose($socket);
		return false;
	}
	$ip=$socket["remote_ip"];
	reset($clients);
	$count=0;
	while(list($pid,$arr)=each($clients)) {
		if ($arr["ip"] == $ip) $count++;
	}
	if ($count>=$max_users_per_ip) {
		swrite($socket,"500 Connexion number from your host reached its maximum (".$max_users_per_ip.").");
		sclose($socket);
		return false;
	}
	$socket["num_cli"] = count($clients)+1; // just because we weren't in the list when we got it
	return true;
}

function proto_welcome(&$socket) {
	global $max_users;
	$socket["log_fp"]=fopen(HOME_DIR."log/ftp-".date("Ymd-His")."-".$socket["remote_ip"].".log","w");
	fputs($socket["log_fp"],"Client : ".$socket["remote_ip"].":".$socket["remote_port"]." connected.\r\n");
	swrite($socket,"220-Looking up your hostname...");
}

function proto_welcome2(&$socket) {
	global $max_users,$locarray;
	$locale="en";
	$lng = substr($socket["remote_host"],-3); // 3 last
	if (substr($lng,0,1)!=".") $lng="en";
	$lng = substr($lng,1);
	if (isset($locarray[$lng])) $locale=$lng;
	$socket["locale"]=$locale;
	swrite($socket,"220-".locmsg($socket,"welcome"));
	swrite($socket,"220-".locmsg($socket,"usernfo",$socket["num_cli"],$max_users));
	swrite($socket,"220-".locmsg($socket,"userano"));
	swrite($socket,"220-".locmsg($socket,"userlog"));
	swrite($socket,"220 ".locmsg($socket,"welcinv"));
	$socket["logon"]=false;
	$socket["binary"]=false; // ASCII mode by default
	$socket["restore"]=0;
	$socket["mode"]=0; // pasv (-1) or port (1)
	$socket["noop"]=0; // on vas compter les noops....... :)
}

function proto_timeout(&$socket) {
	// timeout du socket ?
	swrite($socket,"500 ".locmsg($socket,"sock_timeout"));
}

function proto_handler(&$socket,$cmd,$cmdline) {
	global $unknown_command;
	// gestion globale :p
	$socket["noop"]++;
	if ( ($cmd != "noop") and ($cmd != "allo") ) $socket["noop"]=0;
	if (function_exists("pcmd_".$cmd)) {
		$cmd="pcmd_".$cmd;
		$cmd($socket,$cmdline);
	} else {
		swrite($socket,$unknown_command);
	}
}

function pcmd_quit(&$socket,$cmdline) {
	swrite($socket,"221 Ja mata !");
	sleep(2);
	sclose($socket);
	exit;
}

function pcmd_user(&$socket,$cmdline) {
	global $max_anonymous;
	if (!eregi("^USER( +)([^ ]+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." USER ".locmsg($socket,"login"));
		return;
	} elseif ($socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"already_logon"));
		return;
	}
	$login=strtolower($regs[2]);
	if ( ($login == "ftp") or ($login == "anonymous") or ($login == "anon") ) {
		// check for $max_anonymous
		if ($max_anonymous<$socket["num_cli"]) {
			swrite($socket,"415 Error : too many users for allowing anonymous access.");
			return;
		}
		if (!chdir('/usr/ftp')) {
			swrite($socket,'415 Error : No anonymous login accepted on this server.');
			return;
		}
		$login = NULL;
		$socket["access"]=-1;
		$socket["user"]=$login;
		$socket["pass"]=$login;
		$socket["logon"]=true;
		$socket["root"]=getcwd();
		chdir($socket["root"]);
		swrite($socket,"230 ".locmsg($socket,"anon_logon"));
		return;
	}
	$login=ereg_replace("[^a-z0-9_@.-]","",strtolower($regs[2]));
	$socket["user"]=$login;
	swrite($socket,"331 ".locmsg($socket,"user_need_pass",$login));
}

function pcmd_pass(&$socket,$cmdline) {
	global $ftp_server;
	if (!eregi("^PASS( +)([^ ]+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." PASS ".locmsg($socket,"password"));
		return;
	} elseif ($socket["logon"]) {
		if ($socket["user"] === NULL) {
			swrite($socket,"230 ".locmsg($socket,"anon_pass"));
		} else {
			swrite($socket,"500 ".locmsg($socket,"already_logon"));
		}
		return;
	} elseif (!isset($socket["user"])) {
		swrite($socket,"500 ".locmsg($socket,"pass_req_user"));
		return;
	}
	$pass=$regs[2];
	$req="SELECT sysroot, ftpenabled, ftppass, space, server, access FROM nezumi_host.domains WHERE domain = '".$socket["user"]."'";
	$res=mysql_query($req);
	if (!$res=@mysql_fetch_object($res)) {
		sleep(2);
		swrite($socket,"500 ".locmsg($socket,"pass_invalid"));
		return;
	}
	if ( ($res->ftppass != md5($pass)) or ($res->ftppass == "") ) {
		sleep(2);
		swrite($socket,"500 ".locmsg($socket,"pass_invalid"));
		return;
	}
	if ($res->ftpenabled != "Y") {
		sleep(2);
		swrite($socket,"500 ".locmsg($socket,"pass_disabled"));
		return;
	}
	if ($res->server != $ftp_server) {
		swrite($socket,"500 ".locmsg($socket,"server_wrongserver",$res->server));
		return;
	}
	if (!is_dir($res->sysroot)) {
		swrite($socket,"500 ".locmsg($socket,"pass_noroot"));
		return;
	}
	$dir=$res->sysroot;
	chdir($dir);
	$dir = getcwd();
	swrite($socket,"230-".locmsg($socket,"pass_ok",$socket["user"]));
	swrite($socket,"230-".locmsg($socket,"pass_fxp"));
	$socket["access"]=$res->access;
	$socket["logon"]=true;
	$socket["root"]=$dir;
	$socket["space"]=$res->space;
	update_quota($socket);
	show_quota($socket,"230 ");
}

function pcmd_syst(&$socket,$cmdline) {
	swrite($socket,"215 UNIX Type: L8");
}

function pcmd_type(&$socket,$cmdline) {
	if (!eregi("^TYPE( +)(A|I)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." TYPE A ".locmsg($socket,"str_or")." TYPE I");
		return;
	}
	$type=strtoupper($regs[2]);
	if ($type == "A") {
		swrite($socket,"200 TYPE ".locmsg($socket,"is_now","ASCII"));
		$socket["binary"]=false;
	} else {
		swrite($socket,"200 TYPE ".locmsg($socket,"is_now","8-bit binary"));
		$socket["binary"]=true;
	}
}

function pcmd_pwd(&$socket,$cmdline) {
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	}
	$dir=getcwd();
	$root=$socket["root"];
	if (substr($dir,0,strlen($root)) != $root) {
		// fatal o_O
		swrite($socket,"257-Impossible error - user got away from his chroot o_O - back to / ...");
		chdir($root);
		$dir=getpwd();
	}
	$dir=substr($dir,strlen($root));
	if (substr($dir,0,1)!="/") $dir="/".$dir;
	swrite($socket,"257 \"$dir\" is your Current Working Directory");
}

function pcmd_cdup(&$socket,$cmdline) {
	pcmd_cwd($socket,"CWD ..");
}

function pcmd_cwd(&$socket,$cmdline) {
	// Change Working Directory
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^CWD( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." CWD newdir");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ...
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't change location");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't change location");
		return;
	}
	clearstatcache();
	$stat = stat($dir);
	$mode = substr(decbin($stat["mode"]),-3);
	if (substr($mode,2,1)=="0")
	 if ($socket["user"] == NULL) {
		// accès non autorisé en anonymous...
		chdir($old);
		swrite($socket,"500 Access denied");
		return;
	}
	$dir=substr($dir,strlen($root));
	if (substr($dir,0,1)!="/") $dir="/".$dir;
	swrite($socket,"250 Current location : ".$dir);
}

function pcmd_rest(&$socket,$cmdline) {
	// restore function
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^REST( +)([0-9]+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." REST position_in_bytes");
		return;
	}
	$socket["restore"]=$regs[2]+0;
	swrite($socket,"350 Restarting at ".$socket["restore"].".");
}

function pcmd_port(&$socket,$cmdline) {
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^PORT( +)([0-9,]+)\$",$cmdline,$regs)) {
		swrite($socket,"500 Invalid PORT command.");
		return;
	}
	$ip=explode(",",$regs[2]);
	if (count($ip)!=6) {
		swrite($socket,"500 Invalid PORT command.");
		return;
	}
	reset($ip);
	$ip2=array();
	while(list($nm,$num)=each($ip)) {
		$num+=0;
		if ($num>255) {
			swrite($socket,"500 Invalid PORT command.");
			return;
		}
		$ip2[$nm] = $num + 0;
	}
	$ip=$ip2[0].".".$ip2[1].".".$ip2[2].".".$ip2[3];
	$port=$ip2[4]*256+$ip2[5];
	if ($ip == "0.0.0.0") $ip = $socket["remote_ip"];
	if ($socket["user"] == NULL) {
		if ($ip != $socket["remote_ip"]) {
			swrite($socket,"500 Invalid PORT command. (IP mismatch)");
			return;
		}
	}
	if ($port<1024) {
		swrite($socket,"500 Invalid PORT command. (port < 1024)");
		return;
	}
	// it's ok
	$socket["mode"]=1;
	$socket["mode_ip"]=$ip;
	$socket["mode_port"]=$port;
	if ($ip != $socket["remote_ip"]) {
		swrite($socket,"200-FXP transfert enabled to $ip");
	}
	swrite($socket,"200 PORT command successful");
}

function pcmd_pasv(&$socket,$cmdline) {
	// passive mode
global $pasv_ip;
	$myip="";
	socket_getsockname($socket["sock"],$myip);
	$sock=@socket_create (AF_INET, SOCK_STREAM, SOL_TCP);
	if (!$sock) {
		swrite($socket,"500 Couldn't create socket : ".socket_strerror($sock));
		return false;
	}
	// bind to a port near 40000 - 41000
	if (!@socket_bind($sock,$myip)) {
	swrite($socket,"500 Couldn't bind socket");
	socket_close($sock);
	return false;
}
	$res=socket_listen($sock,5);
	if (!$res) {
		socket_close($sock);
		swrite($socket,"500 Couldn't set socket listning : ".socket_strerror($res));
		return false;
	}
	$socket["mode"]=-1;
	$socket["mode_sock"]=$sock;
	socket_getsockname($sock,$myip,$myport);
	$myport2=( $myport >> 8 ) & 0xFF;
	$myport=($myport & 0xFF);
if (trim($pasv_ip)!="") $myip=$pasv_ip;
	$res="227 Entering passive mode (".str_replace(".",",",$myip).",".$myport2.",".$myport.")";
	swrite($socket,$res);
}

function proto_connect(&$socket) {
	// connects to remote and return io socket id (array("sock"=>socket socket))
	if ($socket["mode"]==1) {
		$socket["mode"]=0;
		// connect to remote
		$sock=@socket_create (AF_INET, SOCK_STREAM, SOL_TCP);
		if (!$sock) {
			swrite($socket,"500 Couldn't open new socket : ".socket_strerror($sock));
			return false;
		}
		$res= @socket_connect ($sock, $socket["mode_ip"], $socket["mode_port"]);
		if (!$res) {
			socket_close($sock);
			swrite($socket,"500 Couldn't connect. Reason : ".socket_strerror(socket_last_error()));
		}
		$mode="ASCII";
		if ($socket["binary"]) $mode="8-bit binary";
		swrite($socket,"150-Connected to port ".$socket["mode_port"]);
		swrite($socket,"150 The current transfert mode is $mode mode.");
		return array("sock" => $sock, "state" => true);
	} elseif ($socket["mode"] == -1) {
		// connect to remote...
		$sock=@socket_accept($socket["mode_sock"]);
		@socket_close($socket["mode_sock"]);
		if (!$sock) {
			swrite($socket,"500 No incoming connexion, or connexion failed.");
			return false;
		}
		socket_getpeername($sock,$peerip);
		$mode="ASCII";
		if ($socket["binary"]) $mode="8-bit binary";
		swrite($socket,"150-Accepted data connexion from $peerip");
		swrite($socket,"150 The current transfert mode is $mode mode.");
		return array("sock" => $sock, "state" => true);
	}
	swrite($socket,"500 No data connexion available");
	$socket["mode"]=0;
	return false;
}

function pcmd_list(&$socket,$cmdline) {
	// in any way, list here
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	}
	$sock=proto_connect($socket);
	if (!$sock) return;
	clearstatcache();
	$stat = stat(".");
	$mode = substr(decbin($stat["mode"]),-3);
	if (substr($mode,0,1)=="0")
	 if ($socket["user"] == NULL) {
		// lecture non autorisée en anonymous...
		sclose($sock);
		swrite($socket,"226-Directory listing denied.");
		swrite($socket,"226 Transfert done");
		return;
	}
	$dir=opendir(".");
	if (!$dir) {
//			swrite($sock,"total 0");
		sclose($sock);
		show_quota($socket);
		swrite($socket,"226 Transfert done");
		return;
	} 
	$tmpdir=array();
	while(($fil=readdir($dir))!==false) $tmpdir[]=$fil;
	closedir($dir);
	reset($tmpdir);
	while(list($num,$fil)=each($tmpdir)) {
		clearstatcache();
		$stat = stat($fil);
		$flag="-rwx";
		if (is_dir($fil)) $flag="drwx";
		if (is_link($fil)) $flag="lrwx";
		$mode=substr(decbin($stat["mode"]),-3);
		if (substr($mode,0,1)=="1") $xflg ="r"; else $xflg ="-";
		if (substr($mode,1,1)=="1") $xflg.="w"; else $xflg.="-";
		if (substr($mode,2,1)=="1") $xflg.="x"; else $xflg.="-";
		$flag.=$xflg.$xflg;
		$blocks=$stat["blocks"];
		while(strlen($blocks)<4) $blocks=" ".$blocks;
		$res=$flag.=" ".$blocks." ";
		$res.="0				"; // user
		$res.="0				"; // group
		$siz = $stat["size"];
		while(strlen($siz)<8) $siz=" ".$siz;
		$res.=$siz." "; // file size
		$ftime = filemtime($fil); // moment de modification
		$res.=date("M",$ftime); // month in 3 letters
		$day = date("j",$ftime);
		while(strlen($day)<3) $day=" ".$day;
		$res.=$day;
		$res.=" ".date("H:i",$ftime);
		$res.=" ".$fil;
		if (is_link($fil)) {
			// reseolve the link
			$dest = readlink($fil);
			$res.=" -> ".$dest;
		}
		swrite($sock,$res);
	}
	show_quota($socket);
	swrite($socket,"226 Transfert done");
	sclose($sock);
}

function pcmd_retr(&$socket,$cmdline) {
	// downloading a file
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^RETR( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." RETR file");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ...
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	chdir($old);
	if (substr($dir,-1)!="/") $dir.="/";
	$fn=$dir.$file;
	// now test for the file
	if (!file_exists($fn)) {
		swrite($socket,"500 This file doesn't exists !");
		return;
	}
	if (is_dir($fn)) {
		swrite($socket,"500 You can't RETR a directory. Try CWD instead");
		return;
	}
	clearstatcache();
	$stat = stat($fn);
	$mode = substr(decbin($stat["mode"]),-3);
	if (substr($mode,0,1)=="0")
	 if ($socket["user"] == NULL) {
		swrite($socket,"500 Access denied");
		return;
	}
	// mode ?
	$fm="r";
	if ($socket["binary"]) $fm.="b"; // on windows platforms, it cares. On *NIX it's ignored
	$fsiz=filesize($fn);
	$fil=@fopen($fn,$fm);
	$offset=0;
	if (!$fil) {
		swrite($socket,"500 Couldn't open file for reading");
		return;
	}
	if ($socket["restore"]!=0) {
		if ($socket["restore"] > $fsiz) {
			swrite($socket,"500 Can't restore : restore position greater than file size. Use REST 0 to restart as position 0.");
			fclose($fil);
			return;
		}
		$offset=$socket["restore"];
		$socket["restore"]=0; // reset it
		fseek($fil,$offset);
	}
	// open connexion now
	$sock=proto_connect($socket);
	if (!$sock) { fclose($fil); return; }
	$transfert=true; $ok=false;
	$throttle=false;
	if ($socket["user"] === NULL) $throttle=true;
logstr($socket["user"]." requesting file ".$fn);
	while ( ($offset<$fsiz) and ($transfert) ) {
		// boucle de transmission
		if ($socket["binary"]) {
			fseek($fil,$offset);
			$buf=fread($fil,16384);
			$val=socket_write($sock["sock"],$buf);
			if ($throttle) {
				// limitation a 16k/.25 sec
				usleep(250000);
			}
			if ($val === FALSE) {
				// hu? socket seems to be closed by the remote side
				sclose($sock);
				$transfert=false; // consider it as aborted
			} else {
				$offset+=$val; // make sure we do not lost data
			}
		} else {
			// ASCII transfert
			$line=fgets($fil,8192); // read a line of maximum 8k
			$offset+=strlen($line);
			if (feof($fil)) { $ok=true; $transfert=false; }
			swrite($sock,$line); // swrite() won't return until all data is transmitted
			if (!$sock["state"]) $transfert=false;
		}
		// don't support the ABRT function - it's sueless here
	}
	fclose($fil);
	sclose($sock);
	// control return codes
	if ( (!$transfert) and (!$ok) ) {
		// transmission aborted
		swrite($socket,"500 Transfert aborted by tunnel closing");
	} else {
		swrite($socket,"226 File successfuly transferred");
	}
}

function pcmd_noop(&$socket,$cmdline) {
	if ($socket["noop"] >= 20) {
		if ($socket["noop"] == 20) {
			swrite($socket,"200 Say only one more NOOP or ALLO and I'll close your link.");
			return;
		}
		swrite($socket,"500 This is my last word. Sayonara >_<");
		sleep(2);
		sclose($socket);
		exit;
	}
	// let's have fun
	global $fun;
	$mfun=$fun[$socket["locale"]];
	if (!is_array($mfun)) {
		swrite($socket,"200 ²zZ ...");
		return;
	}
	$c = strtoupper(substr($cmdline,0,4));
	$num = rand(1,count($mfun))-1;
	$txt=$mfun[$num];
	$txt=str_replace("%c",$c,$txt);
	swrite($socket,"200 ".$txt);
}

function pcmd_allo(&$socket,$cmdline) {
	pcmd_noop($socket,$cmdline);
}


function checkdir(&$socket,$old) {
	$root=$socket['root'];
	$dir=getcwd();
	$tdir=substr($dir,strlen($root));
	while($tdir{0}=='/') $tdir=substr($tdir,1);
	$tdir='/'.$tdir;
	if ( $tdir == '/' ) $tdir = '';
	if ( (count(explode("/", $tdir))<=$socket["access"]) or ($socket["access"]==-1) ) {
		// can't upload in sub-directory 'N-1' if user access is 'N'
		// anonymous users gets a '-1' access by default, so can't upload anywhere.
		chdir($old);
		swrite($socket,"500 Can't gain write access to this location.");
		return false;
	}
	return true;
}

function pcmd_appe(&$socket,$cmdline) {
	return pcmd_stor($socket,$cmdline,true);
}

function pcmd_stor(&$socket,$cmdline,$appe=false) {
	global $ftp_owner_u,$ftp_owner_g;
	// store a file :p
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^(STOR|APPE)( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." STOR|APPE filename");
		return;
	} elseif ($socket["user"] == NULL) {
		swrite($socket,"500 Anonymous users are not allowed to upload.");
		return;
	}
	if ($socket["space"] != NULL) {
		if ($socket["quota"]["free"] <= 0) {
			swrite($socket,"500 Quota exceed !");
			return;
		}
	}
	$cwd = $regs[3];	// spaces prefixed with \ ... (in some cases)
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	if (!checkdir($socket,$old)) return false;
	chdir($old);
	if (substr($dir,-1)!="/") $dir.="/";
	$fn=$dir.$file;
	// now test for the file
	if (is_dir($fn)) {
		swrite($socket,"500 Can't overwrite a directory !");
		return;
	}
	// mode ?
	$offset=0;
	$fsiz=@filesize($fn);
	if (!$fsiz) $fsiz=0;
	if ($appe) $socket["restore"]=$fsiz;
	if ($socket["restore"]!=0) {
		if ($socket["restore"] > $fsiz) {
			swrite($socket,"500 Can't restore : restore position greater than file size. Use REST $fsiz to restart at end of file.");
			fclose($fil);
			return;
		}
		$offset=$socket["restore"];
		$socket["restore"]=0; // reset it
	}
	$fm="a";

	// HOTFIX
$socket["binary"]=true;


	if ($socket["binary"]) $fm.="b"; // on windows platforms, it cares. On *NIX it's ignored
	$fil=@fopen($fn,$fm);
	if (!$fil) {
		swrite($socket,"500 Couldn't open file for writing");
		return;
	}
	// open connexion now
	$sock=proto_connect($socket);
	if (!$sock) { fclose($fil); return; }
	ftruncate($fil,$offset);
	fseek($fil,$offset);
	if ($socket["space"] == NULL) {
		$free=NULL;
	} else {
		$free=$socket["quota"]["free"];
	}
	$transfert=true; $ok=false; $nextlign=false; // ignore next line if the last line finish with \r and the new one contains only \n is ASCII mode
	while ($transfert) {
		// boucle de reception
		if ($socket["binary"]) {
			$buf="";
			socket_clear_error();
	socket_set_block($sock["sock"]);
			$buf=socket_read($sock["sock"],8192,PHP_BINARY_READ);
			if (!$buf) {	// only for blocking sockets
				// on eof, consider that that's the end of the file
				$transfert=false;
			} else {
				if ($free !== NULL) {
					if ($free > 0) {
						if (strlen($buf)>$free) {
							$buf=substr($buf,0,$free);
							$free=0;
						} else {
							$free=$free-strlen($buf);
						}
					} else {
						$buf="";
						$transfert=false;
					}
				}
				fwrite($fil,$buf);
			}
		} else {
			// ASCII transfert
			$line=sread($sock);
			if (substr($sock["lastread"],-1)=="\r") {
				$nextlign=true;
			}
			if ( ($sock["lastread"] == "\n") and ($nextlign) ) {
				$nextlign=false;
			} else {
				$line.="\n";
				if ($free !== NULL) {
					if ($free > 0) {
						if (strlen($line)>$free) {
							$line=substr($line,0,$free);
							$free=0;
						} else {
							$free=$free-strlen($line);
						}
					} else {
						$buf="";
						$transfert=false;
					}
				}
				fputs($fil,$line);
			}
			if (!$sock["state"]) $transfert=false;
		}
		// don't support the ABRT function - it's useless here
	}
	fclose($fil);
	@chown($fn,$ftp_owner_u);
	@chgrp($fn,$ftp_owner_g);
	sclose($sock);
	if ($free == 0) {
		swrite($socket,"500 Quota exceed !");
	} else {
		swrite($socket,"226 Transfert successful.");
	}
	update_quota($socket);
}

function pcmd_dele(&$socket,$cmdline) {
	// delete a file
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^DELE( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." DELE file");
		return;
	} elseif ($socket["user"] == NULL) {
		swrite($socket,"500 Anonymous users are not allowed to delete files.");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ...
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	if (!checkdir($socket,$old)) return false;
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	if (!is_file($file)) {
		chdir($old);
		swrite($socket,"500 File not found");
		return;
	}
	unlink($file);
	chdir($old);
	if (is_file($file)) {
		swrite($socket,"500 DELE failed for some reason (corrupted fs ou read only fs)");
		return;
	}
	swrite($socket,"226 File removed successfully");
	update_quota($socket);
}

function update_quota(&$socket) {
	// updates user's quota
	if ($socket["user"] == NULL) return; // no quota for anonymous
	if ($socket["space"] == NULL) return; // unlimited quota
	$used = quota_dirsize($socket["root"]) - filesize($socket["root"]);
	$total = $socket["space"]*1048576; // 1024*1024 - total in bytes, space in MB
	$free = $total - $used;
	$quota = array();
	$quota["used"] = $used;
	$quota["total"] = $total;
	$quota["free"] = $free;
	$socket["quota"] = $quota;
	$req="UPDATE nezumi_host.domains SET avail = $free WHERE domain = '".$socket["user"]."'";
	@mysql_query($req);
}

function quota_dirsize($dire) {
	if (is_file($dire)) return filesize($dire);
	$dir=@opendir($dire);
	if (!$dir) return;
	readdir($dir); readdir($dir); // . ..
	$sum = filesize($dire);	// 4096 ? ;)
	if (substr($dire,-1)!="/") $dire.="/";
	while($fil=readdir($dir)) {
		$sum+=quota_dirsize($dire.$fil);
	}
	closedir($dir);
	return $sum;
}

function show_quota(&$socket,$prefix="226-") {
	// 226-Using.... 
	if ($socket["user"] == NULL) return; // no quota for anonymous
	if ($socket["space"] == NULL) {
		swrite($socket,$prefix."You have unlimited space");
		return;
	}
	$quota=$socket["quota"];
	$str="You are using ".$quota["used"]." on ".$quota["total"];
	$pc = round (($quota["used"] * 1000) / $quota["total"]);
	$pc = $pc / 10;
	$str.=" (".$pc."%)";
	swrite($socket,$prefix.$str);
}

function pcmd_mkd(&$socket,$cmdline) {
	global $ftp_owner_u,$ftp_owner_g;
	// creates a directory
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^MKD( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." MKD dirname");
		return;
	} elseif ($socket["user"] == NULL) {
		swrite($socket,"500 Anonymous users are not allowed to create directories.");
		return;
	}
	if ($socket["space"] != NULL) {
		if ($socket["quota"]["free"] <= 4096) {
			swrite($socket,"500 You need 4k of quota to make directory");
			return;
		}
	}
	$cwd = $regs[2];	// spaces prefixed with \ ... (in some cases)
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	if (!checkdir($socket,$old)) return false;
	chdir($old);
	if (substr($dir,-1)!="/") $dir.="/";
	$fn=$dir.$file;
	// now test for the file
	if (is_dir($fn)) {
		swrite($socket,"500 This directory already exists");
		return;
	}
	if (is_file($fn)) {
		swrite($socket,"500 There's a file here. Can't replace it without your advice.");
		return;
	}
	if (mkdir($fn)) {
		@chown($fn,$ftp_owner_u);
		@chgrp($fn,$ftp_owner_g);
		swrite($socket,"221 Directory created.");
	} else {
		swrite($socket,"500 An error has occured.");
	}
	update_quota($socket);
}

function pcmd_rmd(&$socket,$cmdline) {
	// removes a directory
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^RMD( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." RMD file");
		return;
	} elseif ($socket["user"] == NULL) {
		swrite($socket,"500 Anonymous users are not allowed to remove directories.");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ...
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	if (!is_dir($file)) {
		chdir($old);
		swrite($socket,"500 Directory not found (or not a directory)");
		return;
	}
	if (!checkdir($socket,$old)) return false;
	chdir($dir);
	@rmdir($file);
	chdir($old);
	clearstatcache();
	if (is_dir($file)) {
		swrite($socket,"500 Directory not removed. May be not empty ? (try the RRMD for recrsive operation)");
		return;
	}
	swrite($socket,"226 Directory removed successfully");
	update_quota($socket);
}

function pcmd_rrmd(&$socket,$cmdline) {
	// removes a directory
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^RRMD( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." RRMD file");
		return;
	} elseif ($socket["user"] == NULL) {
		swrite($socket,"500 Anonymous users are not allowed to recursive remove directories.");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ...
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	if (!is_dir($file)) {
		chdir($old);
		swrite($socket,"500 Directory not found (or not a directory)");
		return;
	}
	if (!checkdir($socket,$old)) return false;
	chdir($dir);
	recurse_rmdir($file);
	chdir($old);
	if (is_dir($file)) {
		swrite($socket,"500 Directory not removed. Recursive operation failed");
		return;
	}
	swrite($socket,"226 Directory and its content removed successfully");
	update_quota($socket);
}

function recurse_rmdir($dire) {
	if (is_file($dire)) {
		unlink($dire);
		return;
	}
	$dir=@opendir($dire);
	if (substr($dire,-1)!="/") $dire.="/";
	if (!$dir) {
		@rmdir($dire);
		return;
	}
	readdir($dir); readdir($dir); // . ..
	while ($fil = readdir($dir)) {
		recurse_rmdir($dire.$fil);
	}
	closedir($dir);
	@rmdir($dire);
}

function pcmd_size(&$socket,$cmdline) {
	// get file's size (answer : 213 )
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^SIZE( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." SIZE file");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ...
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	chdir($old);
	if (substr($dir,-1)!="/") $dir.="/";
	$fn=$dir.$file;
	// now test for the file
	if (!file_exists($fn)) {
		swrite($socket,"500 This file doesn't exists !");
		return;
	}
	clearstatcache();
	$fsiz=filesize($fn);
	swrite($socket,"213 ".$fsiz);
}

function pcmd_rnfr(&$socket,$cmdline) {
	// rename FRom file
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^RNFR( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." RNFR filename");
		return;
	} elseif ($socket["user"] == NULL) {
		swrite($socket,"500 Anonymous users are not allowed to rename files.");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ... (in some cases)
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	if (!checkdir($socket,$old)) return false;
	chdir($old);
	if (substr($dir,-1)!="/") $dir.="/";
	$fn=$dir.$file;
	// now test for the file
	if (!file_exists($fn)) {
		swrite($socket,"500 No such file/directory !");
		return;
	}
	$socket["rename_from"]=$fn; // complete path to file
	swrite($socket,"221 rename ready. Please give destination with RNTO now.");
}

function pcmd_rnto(&$socket,$cmdline) {
	global $ftp_owner_u,$ftp_owner_g;
	// new name for file
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^RNTO( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." RNTO newfilename");
		return;
	} elseif ($socket["user"] == NULL) {
		swrite($socket,"500 Anonymous users are not allowed to rename files.");
		return;
	} elseif (!isset($socket["rename_from"])) {
		swrite($socket,"500 Please RNFR before RNTO");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ... (in some cases)
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return;
	}
	if (!checkdir($socket,$old)) return false;
	chdir($old);
	if (substr($dir,-1)!="/") $dir.="/";
	$fn=$dir.$file;
	// now test for the file
	if (is_dir($fn)) {
		swrite($socket,"500 Can't overwrite a directory !");
		return;
	}
	$from=$socket["rename_from"];
	unset($socket["rename_from"]);
	if (!file_exists($from)) {
		swrite($socket,"500 Source missing. Operation aborted");
		return;
	}
	if (rename($from,$fn)) {
		@chown($fn,$ftp_owner_u);
		@chgrp($fn,$ftp_owner_g);
		swrite($socket,"221 Rename successful.");
	} else {
		swrite($socket,"500 It seems that the rename failed.");
	}
	update_quota($socket);
}

function pcmd_mode(&$socket,$cmdline) {
	// geting Modification info on a file
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^MODE( +)(S)\$",$cmdline,$regs)) {
		swrite($socket,"504 Only MODE S(tream) is supported.");
		return;
	}
	swrite($socket,"200 S OK");
}

function pcmd_stru(&$socket,$cmdline) {
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^STRU( +)(F)\$",$cmdline,$regs)) {
		swrite($socket,"504 Only STRU F(ile) is supported.");
		return;
	}
	swrite($socket,"200 F OK");
}
function pcmd_mdtm(&$socket,$cmdline) {
	// geting Modification info on a file
	if (!$socket["logon"]) {
		swrite($socket,"500 ".locmsg($socket,"not_logged"));
		return;
	} elseif (!eregi("^MDTM( +)(.+)\$",$cmdline,$regs)) {
		swrite($socket,"501 ".locmsg($socket,"syntax")." MDTM file");
		return;
	}
	$cwd = $regs[2];	// spaces prefixed with \ ...
	$cwd = ereg_replace("\\\\(.)","\\1",$cwd); // replace \x with x
	$fn=testfile($socket,$cwd);
	if (!$fn) return;
	// now test for the file
	if (!file_exists($fn)) {
		swrite($socket,"500 This file doesn't exists !");
		return;
	}
	clearstatcache();
	$stat = filemtime($fn);
	swrite($socket,"213 ".date("YmdHis",$stat));
}
function pcmd_feat(&$socket,$cmdline) {
	// logged users only
	swrite($socket,"211-Extensions supported:");
//		swrite($socket,"211-EPRT");
//		swrite($socket,"211-IDLE");
	swrite($socket,"211-MDTM");
	swrite($socket,"211-SIZE");
	swrite($socket,"211-REST STREAM");
//		swrite($socket,"211-MLST type*;size*;sizd*;modify*;UNIX.mode*;UNIX.uid*;UNIX.gid*;unique*;");
//		swrite($socket,"211-MLSD");
//		swrite($socket,"211-TVFS");
//		swrite($socket,"211-ESTP");
//		swrite($socket,"211-PASV");
//		swrite($socket,"211-EPSV");
//		swrite($socket,"211-SPSV");
//		swrite($socket,"211-ESTA");
	swrite($socket,"211 End.");
}

function testfile(&$socket,$cwd) {
			$file = basename($cwd);
	$cwd = dirname($cwd);
	$toroot=false;
	$old=getcwd();
	$root=$socket["root"];
	while (substr($cwd,0,1)=="/") { $cwd=substr($cwd,1); $toroot=true; }
	if ($toroot) chdir($root);
	if ($cwd=="") $cwd=".";
	if (!@chdir($cwd)) {
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return false;
	}
	$dir=getcwd();
	if (substr($dir,0,strlen($root)) != $root) {
		// wrong chdir
		chdir($old);
		swrite($socket,"500 Couldn't get into object's location.");
		return false;
	}
	chdir($old);
	if (substr($dir,-1)!="/") $dir.="/";
	$fn=$dir.$file;
	return $fn;
}

function locmsg(&$socket,$msg,$s1="",$s2="",$s3="",$s4="") {
	global $locarray;
	if (isset($locarray[$socket["locale"]][$msg])) $msg=$locarray[$socket["locale"]][$msg];
	$msg=str_replace("%s1",$s1,$msg);
	$msg=str_replace("%s2",$s2,$msg);
	$msg=str_replace("%s3",$s3,$msg);
	$msg=str_replace("%s4",$s4,$msg);
	return $msg;
}

$locarray = array(
	"en" => array(
		"welcome" => "Welcome to SimpleFTPd v1.0r2 by MagicalTux <w@ff.st>",
		"usernfo" => "You are user number %s1 of %s2 allowed.",
		"userano" => "This server will accept anonymous and account connexions",
		"userlog" => "Your account login is your hostname without the www.",
		"welcinv" => "Now please give your USER login (or ftp for anonymous connexion)",
		"sock_timeout" => "Socket timeout reached. Please type a little faster next time !",
		"syntax" => "Syntax is :",
		"login" => "login",
		"already_logon" => "Already logged in",
		"anon_logon" => "Anonymous user logged on",
		"anon_pass" => "Any password will work",
		"user_need_pass" => "User %s1 ok. Please give PASSword",
		"password" => "password",
		"pass_req_user" => "Please give USER before PASS",
		"pass_invalid" => "Login or password invalid.",
		"pass_disabled" => "This FTP account is disabled !",
		"pass_noroot" => "Couldn't find your webroot. It seems that your web account isn't yet activated.",
		"server_wrongserver" => "Please use %s1.FF.st instead of this server.",
		"pass_ok" => "Access granted. Welcome to the webroot of %s1",
		"pass_fxp" => "FXP transferts accepted for you.",
		"str_or" => "or",
		"is_now" => "is now %s1.",
		"not_logged" => "You're not logged."
	),
	"fr" => array(
		"welcome" => "Bienvenue sur SimpleFTPd v1.0r2 par MagicalTux <w@ff.st>",
		"usernfo" => "Vous êtes l'utilisateur numéro %s1 sur un maxmum de %s2",
		"userano" => "Ce serveur accepte les connexions anonymes et utilisateur",
		"userlog" => "Votre login correspond à votre nom de domaine sans les www.",
		"welcinv" => "A résent veuillez donner votre nom d'USER (ou 'ftp' pour les connexions anonymes)",
		"sock_timeout" => "Dépassement de la patience du socket. Essayez de taper plus vite la prochaine fois !",
		"syntax" => "Syntaxe :",
		"login" => "nom_d'utilisateur",
		"already_logon" => "Vous êtes déjà identifié",
		"anon_logon" => "Utilisateur anonyme accepté.",
		"anon_pass" => "Le mot de passe n'a pas d'importance",
		"user_need_pass" => "Utilisater %s1 accepté. Veuillez donner le mot de passe (PASS)",
		"password" => "mot_de_passe",
		"pass_req_user" => "Veuillez utiliser USER avant d'utiliser PASS",
		"pass_invalid" => "Nom d'utilisateur ou mot de passe incorrect.",
		"pass_disabled" => "Ce compte FTP est désactivé",
		"pass_noroot" => "Impossible de trouver votre racine de site. Il semble que votre compte ne soit pas encore actif.",
		"server_wrongserver" => "Veuillez utiliser %s1.FF.st au lieu de ce serveur.",
		"pass_ok" => "Accès autorisé. Bienvenue sur la racine du site %s1",
		"pass_fxp" => "Les transferts en FXP vous sont autorisés.",
		"str_or" => "ou",
		"is_now" => "est à présent %s1.",
		"not_logged" => "Vous n'êtes pas identifié."
	)
);

$fun = array(
	"en" => array(
		'Can you say something else than %c ?',
		'Is there anybody on the other side of the socket ??',
		'My life is made of users\' %cs...',
		'Let\'s have a drink ;)',
		'Why must I listen to your %cs ????!',
		'I\'d like to teach you a lot of things, but you just can\'t be quiet...',
		'Would you accept to give me a drink ?',
		'Do you know my creator is the best ?',
		'Geeez ... again ?!!!',
		'²zZ',
		'Why does this stupid %c command exist ?',
		'Do you know that there\'s a great hosting company at http://ff.st/ ?',
		'MagicalTux is the coolest man all over the world (and the best PHP coder) !!',
		'You know you can have support at support@ff.st, don\'t you ?',
		'Please remember to disconnect from the FTP when you leave your computer...',
		'You\'re boring...',
		'%c... %c... %c... repeat it 500 times every morning.',
		'Why are you here since you don\'t do anything ?',
		'If you like this FTP daemon, please tell it me, it will help me to make it better',
		'If you want to send money to the creator of this ftp daemon, mail to w@ff.st ;)',
		'%c... %c... %c... %c... %c... yes, master ?',
		'I love %cs -_-',
		'o_O I wasn\'t expecting to see you saying %c ...',
		'*no comment*',
		'[14:31] <MagicalTux> You know ? My FTP daemon has random answers to %c :o)',
		'[21:14] <le-stratege_DND> ion canon locked to the user making %c !',
		'[03:47] <MagicalTux> is it late... or is it early in the morning? ... that is the question.... ²zZ',
		'[04:18] MagicalTux was kicked from #FF.ST by MagicalTux (go to sleep, you !!)',
		'Ogenki desu ka, %c-san ?',
		'Are you expecting something special from the useless %c command?'
	),
	"fr" => array(
		'Pourquoi t\'es là ? de toutes façons tu fout rien...',
		'Tu sais dire autre chose que %c ??',
		'²zZ',
		'%c... %c... %c... %c... %c... %c... oui maitre?',
		'*_*',
		'o_O encore ???',
		'^___________^	 (copyright Bevounet)',
		'Je vote POUR remplacer la commande \'%c\' par \'BOULET\' ...',
		'[14:31] <MagicalTux> Tu sais koi ? Mon serveur ftp il a des réponses au hasard aux commandes %c :o)',
		'[21:14] <le-stratege_DND> Canon à ions vérouillé sur le mec qui fait des %c !',
		'[03:47] <MagicalTux> est-il tard... ou est-il tôt ce matin ? ... telle est la question.... ²zZ',
		'[04:18] MagicalTux was kicked from #FF.ST by MagicalTux (vas dormir, toi !!)',
		'Si vous aimez mon serveur FTP, n\'hésitez pas à m\'envoyer un ptit peu d\'argent... :)',
		'Ogenki desu ka, %c-san ?',
		'Ma vie est faite de %cs...'
	)
);


// connexion	(port) :
// 200 PORT command successful
// 150-Connecting to port 1068
// 150 3039.5 kbytes to download
// 226-File successfully transferred
// 226 0.219 seconds (measured here), 13.55 Mbytes per second

// connxion (pasv) :
// 227 Entering Passive Mode (127,0,0,1,166,104)
// 150-Accepted data connection
// 150 3039.5 kbytes to download
// 226-File successfully transferred
// 226 0.165 seconds (measured here), 18.02 Mbytes per second
	
	
// ABOR = Abort Operation
// NOOP = NoOpération
// ALLO = Alias to NOOP
// USER = User Login following
// PASS = Password
// QUIT = Bye bye
// SYST = What's your system
// PORT = Active connexion
// EPRT - Extended active mode ?
// PASV = Passive connexion
// EPSV - Extended passive mode (not supported)
// SPSV - Simple passive mode (not supported)
// PWD	= Print Working Directory
// XPWD - Alias to PWD
// CWD	= Change Working Directory
// XCWD - alias to CWD
// CDUP = Change Directory UP
// XCUP - Alias to CDUP
// HELP = help
// RETR = RETRieve file
// REST = Restore transfert at position x
// DELE = DELEte file
// STOR = STORe file
// APPE - unknown (requires data connexion)
// STOU - creates temp file (probably) : 226-FILE: pureftpd.3ea368eb.e4.0000 (requires data connexion)
// MKD	= MaKe Dir
// XMKD - Alias to MKD
// RMD	= ReMove Dir
// XRMD - Alias to RMD
// LIST = List directory
// NLST - probably list (requires data connexion)
// TYPE = transfert mode I/A
// MODE = connexion mode ([S]TREAM only) - reply : 200 S OK
// STRU = unknown ([F]ILE only) - reply : 200 F OK
// XDBG - unknown
// MDTM - returns 20030421034244 (year, month, day, hour, min, secs)
// SIZE = Get file size
// RNFR = rename from (or move)
// RNTO = renae to (or move)
// STAT - 213-STAT\n -rw-r--r--		1 magicalt magicalt	2783232 Apr 21 05:42 test.mp3\n213 End.
// MLST - < 250-Begin\n type=file;size=2783232;modify=20030421034244;UNIX.mode=0644;UNIX.uid=1001;UNIX.gid=1001;unique=806g7f66; test.mp3\n250 End.
// MLSD - unknown (requires data connexion)
// FEAT = lists extended functions
// ESTA - requires active data conexion, unknown
// ESTP - requires data from passive connexion. unknown
// SITE IDLE <time>
// SITE CHMOD
// SITE HELP
	
	// passv : 227 Entering Passive Mode (127,0,0,1,191,246)
