<?php
// pmaild v2.0
  // POP3 server rewrote from scratch (missing old sourcecode)
  // vars :
  // $current_socket : socket en cours
  // $mysql_cnx : connexion MySQL
  // $home_dir : r�p home (fini par / )
  // $servername : nom du serv (eg. Ringo.FF.st ) 
  
  // http://www.faqs.org/rfcs/rfc1939.html

  $socket_type=SOCK_STREAM;
  $socket_proto=SOL_TCP;
  $connect_error="-ERR Please try again later";
  $unknown_command="-ERR Command unrecognized";
  
  $srv_info=array();
  $srv_info["name"]="POP3 Server v2.0 (pmaild v2.0 by MagicalTux <magicaltux@gmail.com>)";
  $srv_info["version"]="2.0.0";

function proto_welcome(&$socket) {
	global $home_dir,$servername;
	$socket["log_fp"]=fopen($home_dir."log/pop3-".date("Ymd-His")."-".$socket["remote_ip"].'-'.getmypid().".log","w");
	fputs($socket["log_fp"],"Client : ".$socket["remote_ip"].":".$socket["remote_port"]." connected.\r\n");
	swrite($socket,"+OK $servername POP3 server (phpMaild v1.1 by MagicalTux <magicaltux@gmail.com>) ready.");
}

function pcmd_quit(&$socket,$cmdline) {
	if (is_ident($socket,false)) {
		// UPDATE state
		$data=$socket['userinfo'];
		$p=$socket['userinfo']['prefix'];
		foreach($socket['dele'] as $id=>$dele) {
			$req='SELECT mailid,uniqname FROM '.$p.'mails` WHERE userid=\''.mysql_escape_string($data['userid']).'\' AND mailid=\''.mysql_escape_string($id).'\'';
			$res=@mysql_query($req);
			$res=@mysql_fetch_assoc($res);
			if (!$res) continue;
			@unlink($data['path'].'/'.$res['uniqname']);
			$req='DELETE FROM '.$p.'mails` WHERE mailid=\''.mysql_escape_string($res['mailid']).'\'';
			@mysql_query($req);
			$req='DELETE FROM '.$p.'mailheaders` WHERE mailid=\''.mysql_escape_string($res['mailid']).'\'';
			@mysql_query($req);
		}
	}
	swrite($socket,"+OK Ja mata !");
	sleep(2);
	sclose($socket);
	exit;
}

function pcmd_auth(&$socket,$cmdline) {
	if (is_ident($socket,false)) {
		swrite($socket,'-ERR You are already identified');
		return;
	}
	$cmdline=explode(' ',$cmdline);
	$cmd=array_shift($cmdline); // AUTH
	if (!$cmdline) {
		swrite($socket,'+OK list of SASL extensions follows');
		swrite($socket,'PLAIN');
		swrite($socket,'.');
		return;
	}
	$method=array_shift($cmdline);
	if ($method!='PLAIN') {
		swrite($socket,'-ERR Unknown AUTH method');
	}
	$response=array_shift($cmdline);
	if (!$response) {
		swrite($socket,'+ go ahead');
		$response=sread($socket);
	}
	// AUTH PLAIN RESPONSE
	// 0x00 login 0x00 password
	$response=explode("\0",base64_decode($response));
	array_shift($response);
	$login=array_shift($response);
	$pass=array_shift($response);
	$socket['login']=$login;
	pcmd_pass(&$socket,'PASS '.$pass);
}

function pcmd_user(&$socket,$cmdline) {
	if (is_ident($socket,false)) {
		swrite($socket,'-ERR You are already identified');
		return;
	}
  	$cmdline=explode(' ',$cmdline);
  	$cmd=array_shift($cmdline); // USER
  	$login=array_shift($cmdline);
  	if ( (strpos($login,'@')===false) && (strpos($login,'+')===false) ) {
  		swrite($socket,'-ERR You must provide your login in the form user@domain');
  		return;
  	}
  	$socket['login']=$login;
  	swrite($socket,'+OK Please give your password now!');
}

function pcmd_pass(&$socket,$cmdline) {
	if (is_ident($socket,false)) {
		swrite($socket,'-ERR You are already identified');
		return;
	}
	if (!isset($socket['login'])) {
		swrite($socket,'-ERR Please provide USER first.');
		return;
	}
  	$cmdline=explode(' ',$cmdline);
  	$cmd=array_shift($cmdline); // USER
  	$login=$socket['login'];
  	$pass=implode(' ',$cmdline); // RFC suggests to accept spaces in password :)
  	$pos=strrpos($login,'@');
  	if ($pos===false) $pos=strrpos($login,'+');
  	$domain=substr($login,$pos+1);
  	$login =substr($login,0,$pos);
  	$req='SELECT domainid, state, protocol FROM `phpinetd-maild`.`domains` WHERE domain = \''.mysql_escape_string($domain).'\'';
  	$res=@mysql_query($req);
  	$res=@mysql_fetch_assoc($res);
  	if (!$res) {
  		sleep(4);
  		swrite($socket,'-ERR Login or password invalid.');
  		return;
  	}
  	$proto=explode(',',$res['protocol']);
  	if (array_search('pop3',$proto)===false) {
  		sleep(4);
  		swrite($socket,'-ERR You are not allowed to use POP3 protocol !');
  		return;
  	}
  	if ($res['state']!='active') {
  		sleep(4);
  		swrite($socket,'-ERR Your domain isn\'t active yet. Please wait a little longer.');
  		return;
  	}
  	$did=$res['domainid'];
  	$p='`phpinetd-maild`.`z'.$did.'_';
  	$req='SELECT id, password FROM '.$p.'accounts` WHERE user=\''.mysql_escape_string($login).'\'';
  	$res=@mysql_query($req);
  	$res=@mysql_fetch_assoc($res);
  	if (!$res) {
  		sleep(4);
  		swrite($socket,'-ERR Login or password invalid.');
  		return;
  	}
  	if (is_null($res['password'])) {
  		// special case : password is to be learned !
  		$res['password']=crypt($pass);
  		$req='UPDATE '.$p.'accounts` SET password=\''.mysql_escape_string($res['password']).'\' WHERE id=\''.mysql_escape_string($res['id']).'\'';
  		@mysql_query($req);
  	}
  	switch(strlen($res['password'])) {
  		case 40: $pass=sha1($pass); break;
  		case 34: case 13: $pass=crypt($pass,$res['password']); break;
  		case 32: $pass=md5($pass); break;
  	}
  	if ($pass!=$res['password']) {
  		sleep(4);
  		swrite($socket,'-ERR Login or password invalid.');
  		return;
  	}
	$req = 'UPDATE '.$p.'accounts` SET `last_login`=NOW() WHERE id=\''.mysql_escape_string($res['id']).'\'';
	@mysql_query($req);
  	$path=PHPMAILD_STORAGE.'/domains';
  	$path.='/'.substr($did,-1).'/'.substr($did,-2).'/'.$did;
  	$acc=dechex($res['id']);
  	while(strlen($acc)<4) $acc='0'.$acc;
  	$path.='/'.substr($acc,-1).'/'.substr($acc,-2).'/'.$acc;
  	system('mkdir -p '.escapeshellarg($path));
  	// try to lock the mail dir, as RFC says
  	// It says that the account should not be readed or written while POP operates. Let's do our best !!
  	$lock=$path.'/lock';
  	$fil=fopen($lock,'w');
  	if (!$fil) {
  		swrite($socket,'-ERR Could not acquire a lock on your account. Did you receive emails yet?');
  		return;
  	}
  	if (!flock($fil, LOCK_EX | LOCK_NB)) {
  		swrite($socket,'-ERR could not acquire a lock on your account. You are probably already reading it.');
  		return;
  	}
  	fwrite($fil,'POP3');
  	$socket['userinfo']=array(
  		'domainid'=>$did,
  		'prefix'=>$p,
  		'userid'=>$res['id'],
  		'path'=>$path,
  		'lock'=>$lock,
  	);
  	$socket['dele']=array();
  	$socket['local_num']=array();
  	swrite($socket,'+OK');
}

function get_local_num(&$socket,$num) {
	$tnum=array_search($num,$socket['local_num']);
	if ($tnum!==false) return $tnum;
	$i=1;
	while(isset($socket['local_num'][$i])) {
		if ($socket['local_num'][$i]==$num) return $i;
		$i++;
	}
	$socket['local_num'][$i]=$num;
	return $i;
}

function resolve_local_num(&$socket,$num) {
	if (!isset($socket['local_num'][$num])) return false;
	return $socket['local_num'][$num];
}

function pcmd_noop(&$socket,$cmdline) {
	if (!is_ident($socket)) return;
	swrite($socket,'+OK');
}

function pcmd_rset(&$socket,$cmdline) {
	if (!is_ident($socket)) return;
	$socket['dele']=array();
	swrite($socket,'+OK');
}

function is_ident(&$socket,$sw=true) {
	if (!isset($socket['userinfo'])) {
		if ($sw) swrite($socket,'-ERR Please identify first.');
		return false;
	}
	return true;
}

function pcmd_stat(&$socket,$cmdline) {
	if (!is_ident($socket)) return;
	$data=$socket['userinfo'];
	$p=$data['prefix'];
	$req='SELECT mailid, uniqname, flags FROM '.$p.'mails` WHERE folder=0 AND userid=\''.mysql_escape_string($data['userid']).'\'';
	$res=@mysql_query($req);
	$c=0; $s=0;
	while($resu=@mysql_fetch_assoc($res)) {
		if (isset($socket['dele'][$resu['mailid']])) continue;
		$flags=explode(',',$resu['flags']);
		if (array_search('deleted',$flags)!==false) continue;
		$c++;
		$s+=filesize($data['path'].'/'.$resu['uniqname']);
	}
	swrite($socket,'+OK '.$c.' '.$s);
}

function pcmd_list(&$socket,$cmdline) {
	if (!is_ident($socket)) return;
	$cmdline=explode(' ',$cmdline);
	$cmd=array_shift($cmdline); // LIST
	$id=null;
	if ($cmdline) {
		$id=intval(array_shift($cmdline));
	}
	$data=$socket['userinfo'];
	$p=$data['prefix'];
	$req='SELECT mailid, uniqname, flags FROM '.$p.'mails` WHERE folder=0 AND userid=\''.mysql_escape_string($data['userid']).'\'';
	if (!is_null($id)) {
		$req.=' AND mailid = \''.mysql_escape_string($id).'\'';
	} else {
		swrite($socket,'+OK');
	}
	$res=@mysql_query($req);
	while($resu=@mysql_fetch_assoc($res)) {
		if (!file_exists(($socket['userinfo']['path'].'/'.$resu['uniqname']))) {
			$req = 'DELETE FROM '.$p.'mails` WHERE mailid=\''.mysql_escape_string($resu['mailid']).'\'';
			@mysql_query($req);
			continue;
		}
		if (isset($socket['dele'][$resu['mailid']])) continue;
		$flags=explode(',',$resu['flags']);
		if (array_search('deleted',$flags)!==false) continue;
		$s=filesize($data['path'].'/'.$resu['uniqname']);
		if (!is_null($id)) {
			swrite($socket,'+OK '.$resu['mailid'].' '.$s);
			return;
		}
		swrite($socket,get_local_num($socket,$resu['mailid']).' '.$s);
	}
	if (!is_null($id)) {
		swrite($socket,'-ERR No such message');
		return;
	}
	swrite($socket,'.');
}

function pcmd_uidl(&$socket,$cmdline) {
	if (!is_ident($socket)) return;
	$cmdline=explode(' ',$cmdline);
	$cmd=array_shift($cmdline); // UIDL
	$id=null;
	if ($cmdline) {
		$id=intval(array_shift($cmdline));
	}
	$data=$socket['userinfo'];
	$p=$data['prefix'];
	$req='SELECT mailid, uniqname, flags FROM '.$p.'mails` WHERE folder=0 AND userid=\''.mysql_escape_string($data['userid']).'\'';
	if (!is_null($id)) {
		$req.=' AND mailid = \''.mysql_escape_string($id).'\'';
	} else {
		swrite($socket,'+OK');
	}
	$res=@mysql_query($req);
	while($resu=@mysql_fetch_assoc($res)) {
		if (isset($socket['dele'][$resu['mailid']])) continue;
		$flags=explode(',',$resu['flags']);
		if (array_search('deleted',$flags)!==false) continue;
		$s=$resu['uniqname'];
		if (!is_null($id)) {
			swrite($socket,'+OK '.get_local_num($socket,$resu['mailid']).' '.$s);
			return;
		}
		swrite($socket,get_local_num($socket,$resu['mailid']).' '.$s);
	}
	if (!is_null($id)) {
		swrite($socket,'-ERR No such message');
		return;
	}
	swrite($socket,'.');
}

function pcmd_retr(&$socket,$cmdline) {
	if (!is_ident($socket)) return;
	$data=$socket['userinfo'];
	$p=$socket['userinfo']['prefix'];
	$cmdline=explode(' ',$cmdline);
	$cmd=array_shift($cmdline); // DELE
	$id=resolve_local_num($socket,intval(array_shift($cmdline)));
	if (isset($socket['dele'][$id])) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$req='SELECT mailid, uniqname, flags FROM '.$p.'mails` WHERE folder=0 AND userid=\''.mysql_escape_string($data['userid']).'\' ';
	$req.='AND mailid=\''.mysql_escape_string($id).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	if (!$res) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$flags=explode(',',$res['flags']);
	if (array_search('deleted',$flags)!==false) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$fil=$data['path'].'/'.$res['uniqname'];
	$fp=fopen($fil,'r');
	if (!$fp) {
		swrite($socket,'-ERR could not open mail for reading');
		return;
	}
	swrite($socket,'+OK Here\'s your message');
	while(!feof($fp)) {
		$lin=fgets($fp,4096);
		if ($lin===false) continue;
		if ($lin{0}=='.') $lin='.'.$lin;
		swrite($socket,$lin);
	}
	fclose($fp);
	swrite($socket,'.');
	$alt=false; // alteration flag for flags
	$t=array_search('seen',$flags);
	if ($t===false) { $flags[]='seen'; $alt = true; }
	$t=array_search('recent',$flags);
	if ($t!==false) { unset($flags[$t]); $alt = true; }
	if ($alt) {
		$flags=implode(',',$flags);
		$req='UPDATE '.$p.'mails` SET flags=\''.mysql_escape_string($flags).'\' WHERE mailid=\''.mysql_escape_string($res['mailid']).'\'';
		@mysql_query($req);
	}
}

function pcmd_top(&$socket,$cmdline) {
	if (!is_ident($socket)) return;
	$data=$socket['userinfo'];
	$p=$socket['userinfo']['prefix'];
	$cmdline=explode(' ',$cmdline);
	$cmd=array_shift($cmdline); // DELE
	$id=resolve_local_num($socket,intval(array_shift($cmdline)));
	$nlin=intval(array_shift($cmdline));
	if (isset($socket['dele'][$id])) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$req='SELECT mailid, uniqname, flags FROM '.$p.'mails` WHERE folder=0 AND userid=\''.mysql_escape_string($data['userid']).'\' ';
	$req.='AND mailid=\''.mysql_escape_string($id).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	if (!$res) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$flags=explode(',',$res['flags']);
	if (array_search('deleted',$flags)!==false) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$fil=$data['path'].'/'.$res['uniqname'];
	$fp=fopen($fil,'r');
	if (!$fp) {
		swrite($socket,'-ERR could not open mail for reading');
		return;
	}
	swrite($socket,'+OK Here\'s your message');
	$head=true;
	while(!feof($fp)) {
		$lin=fgets($fp,4096);
		if ($lin===false) continue;
		$lin=trim($lin);
		if ($head) {
			if ($lin=='') $head=false;
		} else {
			if ($nlin--<=0) break;
		}
		if ($lin{0}=='.') $lin='.'.$lin;
		swrite($socket,$lin);
	}
	fclose($fp);
	swrite($socket,'.');
}

function pcmd_dele(&$socket,$cmdline) {
	if (!is_ident($socket)) return;
	$data=$socket['userinfo'];
	$p=$socket['userinfo']['prefix'];
	$cmdline=explode(' ',$cmdline);
	$cmd=array_shift($cmdline); // DELE
	$id=resolve_local_num($socket,intval(array_shift($cmdline)));
	if (isset($socket['dele'][$id])) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$req='SELECT flags FROM '.$p.'mails` WHERE folder=0 AND userid=\''.mysql_escape_string($data['userid']).'\' ';
	$req.='AND mailid=\''.mysql_escape_string($id).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	if (!$res) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$flags=explode(',',$resu['flags']);
	if (array_search('deleted',$flags)!==false) {
		swrite($socket,'-ERR No such message');
		return;
	}
	$socket['dele'][$id]=true;
	swrite($socket,'+OK Message marked for deletion');
}

function pcmd_capa(&$socket,$cmdline) {
	$capa=array(
		'TOP',
//		'RESP-CODES',
		'USER',
//		'SASL CRAM-MD5 DIGEST-MD5 PLAIN',
		'PIPELINING',
		'UIDL',
		'IMPLEMENTATION phpMaild 1.1',
//		'AUTH-RESP-CODE',
	);
	swrite($socket,'+OK Capability list follows');
	foreach($capa as $cap) swrite($socket,$cap);
	swrite($socket,'.');
}
