<?php
/* IMAP 4rev1 Server
* $Id$
* RFC 3501
* http://www.faqs.org/rfcs/rfc3501.html
* 
* By RFC: RFC822 replaced with RFC2822 in entities
* 
* Messages flags :
*  - \Seen : The message has been "seen"
*  - \Answered : An answer to this message was posted
*  - \Flagged : This message is marked as "really urgent"
*  - \Deleted : Message will be deleted when xxx is issued
*  - \Draft : Not complete message
*  - \Recent : This message is new in this session (not alterable by client)
* 
*                 +----------------------+
*                 |connection established|
*                 +----------------------+
*                            ||
*                            \/
*          +--------------------------------------+
*          |          server greeting             |
*          +--------------------------------------+
*                    || (1)       || (2)        || (3)
*                    \/           ||            ||
*          +-----------------+    ||            ||
*          |Not Authenticated|    ||            ||
*          +-----------------+    ||            ||
*           || (7)   || (4)       ||            ||
*           ||       \/           \/            ||
*           ||     +----------------+           ||
*           ||     | Authenticated  |<=++       ||
*           ||     +----------------+  ||       ||
*           ||       || (7)   || (5)   || (6)   ||
*           ||       ||       \/       ||       ||
*           ||       ||    +--------+  ||       ||
*           ||       ||    |Selected|==++       ||
*           ||       ||    +--------+           ||
*           ||       ||       || (7)            ||
*           \/       \/       \/                \/
*          +--------------------------------------+
*          |               Logout                 |
*          +--------------------------------------+
*                            ||
*                            \/
*              +-------------------------------+
*              |both sides close the connection|
*              +-------------------------------+
*/

if (!defined('PINETD_SOCKET_TYPE')) define('PINETD_SOCKET_TYPE', 'tcp');
$connect_error="-ERR Please try again later";
$unknown_command="-ERR Command unrecognized";
$socket_timeout=1800; // 30 min (as per RFC)

if (!defined('DATE_RFC2822')) define('DATE_RFC2822','D, d M Y H:i:s O');

$srv_info=array();
$srv_info["name"]="IMAP4rev1 Server v2.0 (pmaild v2.0 by MagicalTux <magicaltux@gmail.com>)";
$srv_info["version"]="2.0.0";

function parse_imap_argv($string) {
	$res = array();
	$isin = $isin2 = 0;
	$j=-1;
	$len = strlen($string);
	for($i=0;$i<$len;$i++) {
		$c=$string{$i};
		if ((!$isin2) && ($c == '"')) {
			$isin=1-$isin;
			if ($isin) {
				$j++;
				$res[$j]='';
			}
			continue;
		}
		if ($isin) {
			if ($c=='\\') if (($string{$i+1}=='"') || ($string{$i+1}=='\\')) { $i++; $c=$string{$i}; }
			$res[$j] .= $c;
		} else {
			if (($c != " ") && ($c != "\t")) {
				if (!$isin2) {
					$j++;
					$isin2=1;
				}
				$res[$j].=$c;
			} else {
				if ($isin2) $isin2=0;
			}
		}
	}
	return $res;
}

function proto_welcome(&$socket) {
	global $servername;
	if (!function_exists('mb_convert_encoding')) {
		swrite($socket, '* NO mb_string extension missing in installation, IMAP not working');
		return;
	}
	$socket["log_fp"]=fopen(HOME_DIR."log/imap4-".date("Ymd-His")."-".$socket["remote_ip"].'-'.getmypid().".log","w");
	fputs($socket["log_fp"],"Client : ".$socket["remote_ip"].":".$socket["remote_port"]." connected.\r\n");
	$socket['namespace'] = 'pcmd';
	swrite($socket,'* OK [CAPABILITY IMAP4REV1 X-NETSCAPE LOGIN-REFERRALS AUTH=LOGIN] '.$servername.' IMAP4rev1 2001.305/pMaild at '.date(DATE_RFC2822));
	mysql_query('SET NAMES utf8');
}

function proto_handler(&$socket, $id, $dat) {
	$dat = substr($dat, strlen($id)+1);
	if ($dat == '') {
		swrite($socket, $id.' BAD Missing command');
		return;
	}
	$pos=strpos($dat,' ');
	if (!$pos) $pos=strlen($dat);
	$cmd=substr($dat,0,$pos);
	$cmd=strtolower($cmd);
	$func = $socket['namespace'].'_'.$cmd;
	if (function_exists($func)) {
		$func($socket, $dat, $id);
	} else {
		swrite($socket, $id.' BAD Unknown command');
	}
}

function ucmd_noop(&$socket, $cmdline, $id) {
	swrite($socket, $id.' OK NOOP completed');
}

function pcmd_capability(&$socket, $cmdline, $id) {
	swrite($socket, '* CAPABILITY IMAP4REV1 X-NETSCAPE NAMESPACE MAILBOX-REFERRALS SCAN SORT THREAD=REFERENCES THREAD=ORDEREDSUBJECT MULTIAPPEND LOGIN-REFERRALS AUTH=LOGIN');
	// * CAPABILITY IMAP4REV1 X-NETSCAPE NAMESPACE MAILBOX-REFERRALS SCAN SORT THREAD=R EFERENCES THREAD=ORDEREDSUBJECT MULTIAPPEND LOGIN-REFERRALS AUTH=LOGIN
//	B00000 OK CAPABILITY completed
	swrite($socket, $id.' OK CAPABILITY completed');
}

function ucmd_capability(&$socket, $cmdline, $id) {
	swrite($socket, '* CAPABILITY IMAP4REV1 X-NETSCAPE NAMESPACE MAILBOX-REFERRALS SCAN SORT THREAD=REFERENCES THREAD=ORDEREDSUBJECT MULTIAPPEND LOGIN-REFERRALS AUTH=LOGIN');
	swrite($socket, $id.' OK CAPABILITY completed');
}

function ucmd_namespace(&$socket, $cmdline, $id) {
	// * NAMESPACE (("" "/")("#mhinbox" NIL)("#mh/" "/")) (("~" "/")) (("#shared/" "/")("#ftp/" "/")("#news." ".")("#public/" "/"))
//	A OK NAMESPACE completed
	// TODO: find some documentation and adapt this function
	swrite($socket, '* NAMESPACE (("" "/")("#mhinbox" NIL)("#mh/" "/")) (("~" "/")) (("#shared/" "/")("#ftp/" "/")("#news." ".")("#public/" "/"))');
	swrite($socket, $id.' OK NAMESPACE completed');
}

function ucmd_lsub(&$socket, $cmdline, $id) {
	// LSUB "" "*"
/*
* LSUB () "/" INBOX
* LSUB () "/" "INBOX/FSLT & HL"
* LSUB () "/" INBOX/drafts
* LSUB () "/" INBOX/sent-mail
* LSUB () "/" "&AMk-l&AOk-ments envoy&AOk-s"
* LSUB () "/" Brouillons
B OK LSUB completed
*/
	$arg = parse_imap_argv($cmdline);
	$namespace = $arg[0];
	$param = $arg[1];
	if ($namespace=='') $namespace='/';
	if ($namespace!='/') {
		swrite($socket, $id.' NO Unknown namespace');
		return;
	}
	swrite($socket, '* LSUB () "/" INBOX'); // "root" box - reserved name
	$p = $socket['userinfo']['prefix'];
	$req = 'SELECT id, name, parent FROM '.$p.'folders` WHERE account=\''.mysql_escape_string($socket['userinfo']['userid']).'\' ';
	$req.= 'ORDER BY parent ASC';
	$cache = array(0=>'INBOX');
	$res = @mysql_query($req);
	while($resu = mysql_fetch_row($res)) {
		$name = $resu[1];
		$name = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8'); // convert UTF-8 -> modified UTF-7
		if ($resu[2]!=-1) { // non-root
			if (!isset($cache[$resu[2]])) continue; // bogus:inexisting parent
			$name = $cache[$resu[2]].'/'.$name;
		}
		$cache[$resu[0]] = $name;
		if ( (addslashes($name)!=$name) || (strpos($name, ' ')!==false))
			$name='"'.addslashes($name).'"';
		swrite($socket, '* LSUB () "/" '.$name);
	}
}

function pcmd_quit(&$socket,$cmdline, $id) {
	global $servername;
	swrite($socket,'* BYE '.$servername.' IMAP4rev1 server says bye !');
	swrite($socket,$id.' OK LOGOUT completed');
	sleep(2);
	sclose($socket);
	exit;
}
function ucmd_quit(&$socket,$cmdline, $id) {
	global $servername;
	swrite($socket,'* BYE '.$servername.' IMAP4rev1 server says bye !');
	swrite($socket,$id.' OK LOGOUT completed');
	sleep(2);
	sclose($socket);
	exit;
}

function pcmd_login(&$socket,$cmdline, $id) {
	$cmdline=explode(' ',$cmdline);
	$cmd=array_shift($cmdline); // "LOGIN"
	$login=array_shift($cmdline); // login
	$pass=implode(' ',$cmdline); // RFC suggests to accept spaces in password :)
	$pos=strrpos($login,'@');
	if ($pos===false) $pos=strrpos($login,'+');
	$domain=substr($login,$pos+1);
	$login =substr($login,0,$pos);
	$req='SELECT domainid, state, protocol FROM `'.PHPMAILD_DB_NAME.'`.`domains` WHERE domain = \''.mysql_escape_string($domain).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	if (!$res) {
		sleep(4);
		swrite($socket,$id.' NO Login or password invalid.');
		return;
	}
	$proto=explode(',',$res['protocol']);
	if (array_search('imap4',$proto)===false) {
		sleep(4);
		swrite($socket,$id.' NO You are not allowed to use IMAP4rev1 protocol !');
		return;
	}
	if ($res['state']!='active') {
		sleep(4);
		swrite($socket,$id.' NO Your domain isn\'t active yet. Please wait a little longer.');
		return;
	}
	$did=$res['domainid'];
	$p='`'.PHPMAILD_DB_NAME.'`.`z'.$did.'_';
	$req='SELECT id, password FROM '.$p.'accounts` WHERE user=\''.mysql_escape_string($login).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	if (!$res) {
		sleep(4);
		swrite($socket,$id.' NO Login or password invalid.');
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
		swrite($socket,$id.' NO Login or password invalid.');
		return;
	}
	// Update last_login
	$req = 'UPDATE '.$p.'accounts` SET `last_login`=NOW() WHERE id=\''.mysql_escape_string($res['id']).'\'';
	@mysql_query($req);
	// Insert IP to allow usage of MTA
	$req = 'REPLACE INTO `'.PHPMAILD_DB_NAME.'`.`hosts` SET `ip`=\''.mysql_escape_string($socket['remote_ip']).'\', ';
	$req.= '`type` = \'trust\', `regdate` = NOW(), `expires` = DATE_ADD(NOW(), INTERVAL 2 HOUR), ';
	$req.= '`user_email` = \''.mysql_escape_string($login.'@'.$domain).'\'';
	@mysql_query($req);
	$req = 'DELETE FROM `'.PHPMAILD_DB_NAME.'`.`hosts` WHERE `expires` < NOW()';
	@mysql_query($req);
	$path=$path=PHPMAILD_STORAGE.'/domains';
	$path.='/'.substr($did,-1).'/'.substr($did,-2).'/'.$did;
	$acc=dechex($res['id']);
	while(strlen($acc)<4) $acc='0'.$acc;
	$path.='/'.substr($acc,-1).'/'.substr($acc,-2).'/'.$acc;
	system('mkdir -p '.escapeshellarg($path));
	$socket['userinfo']=array(
		'domainid'=>$did,
		'prefix'=>$p,
		'userid'=>$res['id'],
		'path'=>$path,
	);
	$socket['namespace']='ucmd';
	swrite($socket,$id.' OK LOGIN accepted');
}
