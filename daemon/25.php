<?php
// pmaild v2.0
// SMTP server rewrote from scratch (I miss my old source)
// vars :
// $current_socket : socket en cours
// $mysql_cnx : connexion MySQL
// $servername : nom du serv (eg. Ringo.FF.st ) 
  
if (!defined('PINETD_SOCKET_TYPE')) define('PINETD_SOCKET_TYPE', 'tcp');
$connect_error="420 Please try again later";
$unknown_command="500 Command unrecognized";
  
$srv_info=array();
$srv_info["name"]="ESMTP Server v2.0 (pmaild v2.0 by MagicalTux <MagicalTux@gmail.com>)";
$srv_info["version"]="2.0.0";

$tables_struct = array(
	'%s_accounts' => array(
		'id' => array(
			'type'=>'INT',
			'size'=>10,
			'null'=>false,
			'unsigned'=>true,
			'auto_increment'=>true,
			'key'=>'PRIMARY',
		),
		'user' => array(
			'type'=>'VARCHAR',
			'size'=>64,
			'null'=>false,
			'default'=>'',
			'key'=>'UNIQUE:user',
		),
		'password' => array(
			'type'=>'VARCHAR',
			'size'=>40,
			'null'=>true,
			'default'=>'',
		),
		'last_login' => array(
			'type'=>'DATETIME',
			'null'=>true,
			'default'=>NULL,
		),
		'redirect'=>array(
			'type'=>'VARCHAR',
			'size'=>255,
			'null'=>true,
			'default'=>NULL,
		),
	),
	'%s_alias' => array(
		'id' => array(
			'type'=>'INT',
			'size'=>10,
			'null'=>false,
			'unsigned'=>true,
			'auto_increment'=>true,
			'key'=>'PRIMARY',
		),
		'user' => array(
			'type'=>'VARCHAR',
			'size'=>64,
			'null'=>false,
			'default'=>'',
			'key'=>'UNIQUE:user',
		),
		'last_transit' => array(
			'type'=>'DATETIME',
			'null'=>true,
			'default'=>NULL,
		),
		'real_target' => array(
			'type'=>'INT',
			'size'=>10,
			'null'=>false,
			'unsigned'=>true,
		),
		'http_target' => array(
			'type'=>'VARCHAR',
			'size'=>255,
			'null'=>true,
			'default'=>NULL,
		),
	),
	'%s_folders' => array(
		'id' => array(
			'type'=>'INT',
			'size'=>10,
			'null'=>false,
			'unsigned'=>true,
			'auto_increment'=>true,
			'key'=>'PRIMARY',
		),
		'account' => array(
			'type'=>'INT',
			'size'=>10,
			'unsigned'=>true,
			'null'=>false,
			'key'=>'UNIQUE:folder',
		),
		'name' => array(
			'type'=>'VARCHAR',
			'size'=>32,
			'null'=>false,
			'key'=>'UNIQUE:folder',
		),
		'parent'=>array(
			'type'=>'INT',
			'size'=>11,
			'unsigned'=>false,
			'null'=>false,
			'default'=>0,
			'key'=>'UNIQUE:folder',
		),
	),
	'%s_mails' => array(
		'mailid' => array(
			'type'=>'INT',
			'size'=>10,
			'null'=>false,
			'unsigned'=>true,
			'auto_increment'=>true,
			'key'=>'PRIMARY',
		),
		'folder'=> array(
			'type'=>'INT',
			'size'=>10,
			'null'=>false,
			'unsigned'=>true,
			'key'=>'folder',
		),
		'userid' => array(
			'type'=>'INT',
			'size'=>10,
			'null'=>false,
			'unsigned'=>true,
			'key'=>'UNIQUE:userid',
		),
		'size' => array(
			'type'=>'INT',
			'size'=>10,
			'null'=>false,
			'unsigned'=>true,
		),
		'uniqname' => array(
			'type'=>'VARCHAR',
			'size'=>128,
			'null'=>false,
			'key'=>'UNIQUE:userid',
		),
		'flags' => array(
			'type'=>'SET',
			'values'=>array('seen','answered','flagged','deleted','draft','recent'),
			'default'=>'recent',
			'null'=>false,
		),
	),
	'%s_mailheaders' => array(
		'id' => array(
			'type'=>'BIGINT',
			'size'=>20,
			'unsigned'=>true,
			'null'=>false,
			'auto_increment'=>true,
			'key'=>'PRIMARY',
		),
		'userid' => array(
			'type'=>'INT',
			'size'=>10,
			'unsigned'=>true,
			'null'=>false,
			'key'=>'usermail',
		),
		'mailid' => array(
			'type'=>'INT',
			'size'=>10,
			'unsigned'=>true,
			'null'=>false,
			'key'=>'usermail',
		),
		'header' => array(
			'type'=>'VARCHAR',
			'size'=>64,
			'null'=>false,
			'key'=>'header',
		),
		'content' => array(
			'type'=>'TEXT',
			'null'=>false,
			'key'=>'FULLTEXT:content',
		),
	),
);

function mysql_quote_escape() {
	$args = func_get_args();
	if (count($args)==1) {
		$arg = $args[0];
		if (is_array($arg)) {
			$res = '';
			foreach($arg as $t) {
				$res.=($res==''?'':',').mysql_quote_escape($t);
			}
			return $res;
		}
		if (is_null($arg)) return 'NULL';
		return '\''.mysql_escape_string($arg).'\'';
	}
	$res='';
	foreach($args as $arg) {
		$res.=($res==''?'':',').mysql_quote_escape($arg);
	}
	return $res;
}

function struct_check_tables($prefix) {
	global $tables_struct;
	foreach($tables_struct as $table_name_o=>$struct) {
		$table_name = sprintf($table_name_o, $prefix);
		$f = array_flip(array_keys($struct)); // field list
		$req = 'SHOW FIELDS FROM `'.PHPMAILD_DB_NAME.'`.`'.$table_name.'`';
		$res = @mysql_query($req);
		if (!$res) {
			$req = gen_create_query($prefix, $table_name_o);
			@mysql_query($req);
			continue;
		}
		while($row = mysql_fetch_assoc($res)) {
			if (!isset($f[$row['Field']])) {
				// we got a field we don't know about
				$req = 'ALTER TABLE `'.PHPMAILD_DB_NAME.'`.`'.$table_name.'` DROP `'.$row['Field'].'`';
				@mysql_query($req);
				continue;
			}
			unset($f[$row['Field']]);
			$col = $struct[$row['Field']];
			if ($row['Type']!=col_gen_type($col)) {
				$req = 'ALTER TABLE `'.PHPMAILD_DB_NAME.'`.`'.$table_name.'` CHANGE `'.$row['Field'].'` '.gen_field_info($row['Field'], $col);
				@mysql_query($req);
			}
		}
		foreach($f as $k=>$ign) {
			$req = 'ALTER TABLE `'.PHPMAILD_DB_NAME.'`.`'.$table_name.'` ADD '.gen_field_info($k, $struct[$k]);
			@mysql_query($req);
		}
	}
}

function col_gen_type($col) {
	$res = strtolower($col['type']);
	switch($res) {
		case 'set': case 'enum':
			$res.='('.mysql_quote_escape($col['values']).')';
			break;
		case 'text': case 'blob': case 'datetime':
			break;
		default:
			if (isset($col['size'])) $res.='('.$col['size'].')';
			break;
	}
	if ($col['unsigned']) $res.=' unsigned';
	return $res;
}

function gen_field_info($cname, $col) {
	$tmp = '`'.$cname.'` '.col_gen_type($col);
	if (!$col['null']) $tmp.=' NOT NULL';
	if (isset($col['auto_increment'])) $tmp.=' auto_increment';
	if (array_key_exists('default',$col)) $tmp.=' DEFAULT '.mysql_quote_escape($col['default']);
	return $tmp;
}

function gen_create_query($prefix, $name) {
	global $tables_struct;
	if (!isset($tables_struct[$name])) return NULL;
	$struct = $tables_struct[$name];
	$name = sprintf($name, $prefix);
	$req = '';
	$keys = array();
	foreach($struct as $cname=>$col) {
		$req.=($req==''?'':', ').gen_field_info($cname, $col);
		if (isset($col['key'])) $keys[$col['key']][]=$cname;
	}
	foreach($keys as $kname=>$cols) {
		$tmp = '';
		foreach($cols as $c) $tmp.=($tmp==''?'':', ').'`'.$c.'`';
		$tmp='('.$tmp.')';
		if ($kname == 'PRIMARY') {
			$tmp = 'PRIMARY KEY '.$tmp;
		} elseif (substr($kname, 0, 7)=='UNIQUE:') {
			$kname = substr($kname, 7);
			$tmp = 'UNIQUE KEY `'.$kname.'` '.$tmp;
		} elseif (substr($kname, 0, 9)=='FULLTEXT:') {
			$kname = substr($kname, 9);
			$tmp = 'FULLTEXT KEY `'.$kname.'` '.$tmp;
		} else {
			$tmp = 'KEY `'.$kname.'` '.$tmp;
		}
		$req.=($req==''?'':', ').$tmp;
	}
	$req = 'CREATE TABLE `'.PHPMAILD_DB_NAME.'`.`'.$name.'` ('.$req.') ENGINE=MyISAM DEFAULT CHARSET=latin1';
	return $req;
}

function proto_welcome(&$socket) {
	global $servername;
	if (!$socket['mysql']) {
		swrite($socket, '400 Sorry, no database backend available for now. Please retry later.');
		sclose($socket);
		exit;
	}
	$socket["log_fp"]=fopen(HOME_DIR."log/smtp-".date("Ymd-His")."-".$socket["remote_ip"].".log","w");
	fputs($socket["log_fp"],"Client : ".$socket["remote_ip"].":".$socket["remote_port"]." connected.\r\n");
	swrite($socket,"220 $servername ESMTP (phpMaild v1.1 by MagicalTux <MagicalTux@gmail.com>) ready.");
}

function pcmd_quit(&$socket,$cmdline) {
	global $servername;
	swrite($socket,'221 '.$servername.' closing control connexion. Mata ne~ !');
	sclose($socket);
	exit;
}
  
function pcmd_expn(&$socket,$cmdline) {
	pcmd_vrfy($socket,$cmdline);
}
  
function pcmd_vrfy(&$socket,$cmdline) {
	// syntax : vrfy <mail>
	swrite($socket,'502 I won\'t let you check if this email exists, however RFC said I should reply with a 502 message.');
}
  
// useless function
function pcmd_noop(&$socket,$cmdline) {
	swrite($socket,'250 Ok');
}
  
function pcmd_ehlo(&$socket,$cmdline) {
	pcmd_helo($socket,$cmdline,false);
}
  
function pcmd_helo(&$socket,$cmdline,$oldprot=true) {
	global $servername, $ssl_settings;
	if ($socket["helo"]) {
		swrite($socket,'503 I don\'t know why you say good bye, I say hello~ (Hello goodbye, The Beatles)');
	} else {
		$socket["helo"]=true;
		$remote=explode(" ",$cmdline);
		$remote=$remote[1];
		$socket['helo_str'] = $remote;
		$real=$socket['remote_host'].' ['.$socket['remote_ip'].']';
		if ($remote) {
			$remote=$remote.' ('.$real.')';
		} else {
			$remote=$real;
		}
		$socket["remdat"]=$remote;
		if ($oldprot) {
			swrite($socket,'250 '.$servername.' Pleased to meet you, '.$remote.'.');
		} else {
			swrite($socket,'250-'.$servername.' Pleased to meet you, '.$remote.'.');
			swrite($socket,'250-PIPELINING');
			swrite($socket,'250-ETRN');
			if (isset($ssl_settings[25])) {
				if (function_exists('stream_socket_enable_crypto')) swrite($socket,'250-STARTTLS');
			}
			swrite($socket,"250 8BITMIME");
		}
	}
}

function pcmd_help(&$socket,$cmdline) {
	// help system...
	swrite($socket,'214 http://wiki.ooKoo.org/wiki/pinetd');
}

function pcmd_starttls(&$socket, $cmdline) {
	// NB: This function will not work for now
	// see: http://bugs.php.net/bug.php?id=36445
	if (!function_exists('stream_socket_enable_crypto')) {
		swrite($socket, '454 TLS not available. You need PHP 5 >= 5.1.0RC1 with OpenSSL module');
		return;
	}
	swrite($socket, '220 Ready to start TLS');
	$res = 0;
	while($res===0)
		$res = stream_socket_enable_crypto($socket['sock'], true, STREAM_CRYPTO_METHOD_TLS_SERVER);
	if ($res===false) {
		sclose($socket);
		exit;
	}
}

function pcmd_mail(&$socket,$cmdline) {
	if (!$socket["helo"]) {
		swrite($socket,'503 Sorry, boy. I won\'t let you talk to me unless you say HELO or EHLO.');
	} else {
	// syntax : MAIL FROM: <Adress>
	if (eregi('^MAIL +FROM:? *<?([^>]*)>?.*$',$cmdline,$regs)) {
		$adress=$regs[1];
		if ( (strpos($adress,'@')===false) and ($adress!="") ) $adress.='@'.PHPMAILD_DEFAULT_DOMAIN;
		if ($adress=='') {
			swrite($socket,'250 No Return-Path');
		} else {
			swrite($socket,'250 '.$adress.' : Sender Ok.');
		}
			$socket["mail_from"]=$adress;
		} else {
			swrite($socket,"550 Address or syntax invalid.");
		}
	}
}

function resolve_email(&$socket,$addr) {
	if (isset($socket['antispam'])) return '400 Please provide only ONE RCPT TO';
	$pos=strrpos($addr,'@');
	if ($pos===false) return false;
	$user = substr($addr,0,$pos);
	$domain=substr($addr,$pos+1);
	$req='SELECT domainid, state, flags, antispam, antivirus, dnsbl FROM `'.PHPMAILD_DB_NAME.'`.`domains` WHERE domain=\''.mysql_escape_string($domain).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	if (!$res) return false;
	$trust = false;
	$t = dnsbl_check($socket, $trust, $res['dnsbl']);
	if ($t!==false) return $t;
	$domain_antispam=false;
	if ($trust) {
		// we trust this IP, so we'll skip antispam check
		$res['antispam'] = '';
	}
	if ( ($res['antispam']!='') or ($res['antivirus']!='')) {
		// special case : if we have to use an antispam system, we MUST have only one RCPT TO line
		$domain_antispam=true;
		if (count($socket['mail_to'])>0) return '400 Please provide only ONE RCPT TO';
	}
	$res['antispam']=explode(',',$res['antispam']);
	$res['antivirus']=explode(',',$res['antivirus']);
	$res['flags']=explode(',',$res['flags']);
	if ($res['state']=='new') {
		struct_check_tables('z'.$res['domainid']);
		$req='UPDATE `'.PHPMAILD_DB_NAME.'`.`domains` SET state=\'active\' WHERE domainid=\''.mysql_escape_string($res['domainid']).'\'';
		@mysql_query($req);
	} else {
		struct_check_tables('z'.$res['domainid']);
	}

	$domain_data=$res;
	// table prefix
	$p='`'.PHPMAILD_DB_NAME.'`.`z'.$res['domainid'].'_';
	// check for account
	$req='SELECT id FROM '.$p.'accounts` WHERE user=\''.mysql_escape_string($user).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	if (!$res) {
		// check for alias
		$req='SELECT `real_target`, `http_target`, `id` FROM '.$p.'alias` WHERE user=\''.mysql_escape_string($user).'\'';
		$res=@mysql_query($req);
		$res=@mysql_fetch_assoc($res);
		if ((!$res) && (array_search('create_account_on_mail',$domain_data['flags'])===false)) {
			// no alias found, and no create_account_on_mail... check for "default" alias
			$req='SELECT `real_target`, `http_target`, `id` FROM '.$p.'alias` WHERE user=\'default\'';
			$res=@mysql_query($req);
			$res=@mysql_fetch_assoc($res);
		}
		if ($res) {
			$account_id=$res['real_target'];
			if (!is_null($res['http_target'])) {
				$account_id = $res['http_target'];
				if (count($socket['mail_to'])>0) return '400 Please provide only ONE RCPT TO';
				$domain_antispam=true;
			}
			$req = 'UPDATE '.$p.'alias` SET `last_transit`=NOW() WHERE id=\''.mysql_escape_string($res['id']).'\'';
			@mysql_query($req);
		} else {
			// check for create_account_on_mail
			if (array_search('create_account_on_mail',$domain_data['flags'])!==false) {
				$req='INSERT INTO '.$p.'accounts` SET user=\''.mysql_escape_string($user).'\', password=NULL';
				if (!@mysql_query($req)) return '450 Temporary error in domain name. Please check SQL state.';
				$account_id=mysql_insert_id();
			} else {
				// no mailbox found !
				return '500 Mailbox not found';
			}
		}
	} else {
		$account_id=$res['id'];
	}
	$res=array(
		'account'=>$account_id,
		'domain'=>$domain_data['domainid'],
		'domain_data'=>$domain_data,
		'email'=>$user.'@'.$domain,
		'headers'=>'Delivered-To: <'.$user.'@'.$domain.'>'."\r\n",
	);
	$req = 'UPDATE `'.PHPMAILD_DB_NAME.'`.`domains` SET `last_recv`=NOW() WHERE `domainid` = \''.mysql_escape_string($domain_data['domainid']).'\'';
	@mysql_query($req);
	$socket['antispam']=$domain_antispam;
	return $res;
}

/* DNSBL LIST
 * spews1 : Spews Level 1 (spammers)
 * spews2 : Spews Level 2 (Level 1 + spam friendly)
 * 
 */
function dnsbl_check(&$socket, &$trust, $dnsbl) {
	if (!is_array($dnsbl)) {
		$dnsbl = explode(',', $dnsbl);
	}
	$ip = $socket['remote_ip'];
	$req = 'DELETE FROM `'.PHPMAILD_DB_NAME.'`.`dnsbl_cache` WHERE `regdate` < DATE_SUB(NOW(), INTERVAL 1 DAY)';
	@mysql_query($req);
	// check for relaying
	$req = 'SELECT `type` FROM `'.PHPMAILD_DB_NAME.'`.`hosts` ';
	$req.= 'WHERE `ip` = \''.mysql_escape_string($ip).'\' ';
	$req.= 'AND `type` = \'trust\' AND (`expires` > NOW() OR `expires` IS NULL) ';
	$res = @mysql_query($req);
	$res = @mysql_fetch_row($res);
	if ($res) {
		if ($res[0]=='trust') {
			$trust = true;
			return false; // trusted ip
		} else {
			return '400 Your host is blocked by internal dynamic rule.';
		}
	}
	$rev_ip = implode('.', array_reverse(explode('.', $ip)));
	foreach($dnsbl as $bl) {
		$req = 'SELECT `clear`, `answer` FROM `'.PHPMAILD_DB_NAME.'`.`dnsbl_cache` WHERE `ip` = \''.mysql_escape_string($ip).'\' AND `list` = \''.mysql_escape_string($bl).'\'';
		$res = @mysql_query($req);
		$res = @mysql_fetch_assoc($res);
		if ($res) {
			if ($res['clear']=='Y') continue; // clear on this list
			if ($res['clear']=='N') return dnsbl_error($socket, $bl, $res['answer']); // not clear
		}
		// check DNSBL
		switch($bl) {
			case 'spews1':
				$dns = $rev_ip.'.l1.spews.dnsbl.sorbs.net';
				break;
			case 'spews2':
				$dns = $rev_ip.'.l2.spews.dnsbl.sorbs.net';
				break;
			case 'spamcop':
				$dns = $rev_ip.'.bl.spamcop.net';
				break;
			case 'spamhaus':
				$dns = $rev_ip.'.sbl-xbl.spamhaus.org'; // http://www.spamhaus.org/sbl/howtouse.html
				break;
			default:
				$dns = NULL;
		}
		if (is_null($dns)) continue;
		$bl_answer = gethostbyname($dns);
		switch($bl) {
			case 'spews1': case 'spews2': case 'spamcop':
				if ($bl_answer == '127.0.0.2') return dnsbl_error($socket, $bl, $bl_answer, false);
				break;
			case 'spamhaus':
				if ($bl_answer != $dns) return dnsbl_error($socket, $bl, $bl_answer, false);
				break;
		}
		@mysql_query('REPLACE INTO `'.PHPMAILD_DB_NAME.'`.`dnsbl_cache` SET `ip` = \''.mysql_escape_string($ip).'\', `list` = \''.mysql_escape_string($bl).'\', `regdate` = NOW(), clear = \'Y\'');
	}
	return false;
}

function dnsbl_error(&$socket, $list, $answer, $cached = true) {
	$ip = $socket['remote_ip'];
	if (!$cached) @mysql_query('REPLACE INTO `'.PHPMAILD_DB_NAME.'`.`dnsbl_cache` SET `ip` = \''.mysql_escape_string($ip).'\', `list` = \''.mysql_escape_string($list).'\', `regdate` = NOW(), clear = \'N\', `answer` = \''.mysql_escape_string($answer).'\'');
	switch($list) {
		case 'spews1':
			return '500 You are listed on SPEWS LEVEL1 list - see http://www.spews.org/ask.cgi?x='.$ip.' for details';
		case 'spews2':
			return '500 You are listed on SPEWS LEVEL2 list - see http://www.spews.org/ask.cgi?x='.$ip.' for details';
		case 'spamcop':
			return '500 You are listed on SpamCop - see http://www.spamcop.net/w3m?action=checkblock&ip='.$ip.' for details';
		case 'spamhaus':
			if ($answer=='127.0.0.2') return '500 You are listed on Spamhaus Block List - check http://www.spamhaus.org/query/bl?ip='.$ip;
			if ($answer=='127.0.0.4') return '500 You are listed in CBL - check http://cbl.abuseat.org/lookup.cgi?ip='.$ip;
			if ($answer=='127.0.0.5') return '500 You are listed in NJABL - check http://www.njabl.org/cgi-bin/lookup.cgi?query='.$ip;
			return '500 You are listed on Spamhaus Block List - check http://www.spamhaus.org/query/bl?ip='.$ip;
	}
}

function pcmd_rcpt(&$socket,$cmdline) {
	if (!isset($socket['mail_from'])) {
		swrite($socket,"503 You must precede with MAIL FROM: <>");
	} else {
		// syntax : RCPT TO: <Adress>
		if (eregi('^RCPT +TO:? *<?([^>]+)>?.*$',$cmdline,$regs)) {
			$adress=$regs[1];
			if (!strpos($adress,'@')) $adress.='@'.PHPMAILD_DEFAULT_DOMAIN;
			$rel=resolve_email($socket,$adress);
			if (is_array($rel)) {
				if (substr($rel['account'], 0, 4)!='http') {
					swrite($socket,'250 '.$rel['email'].' ('.$rel['domain'].':'.$rel['account'].') : Receipient Ok.');
				} else {
					swrite($socket,'250 '.$rel['email'].' (special target) : Receipient Ok.');
				}
				if (!is_array($socket["mail_to"])) $socket["mail_to"]=array();
				$socket["mail_to"][]=$rel;
			} elseif (is_string($rel)) {
				swrite($socket,$rel);
			} elseif ($rel===false) {
				// check for relaying
				$req = 'SELECT `user_email` FROM `'.PHPMAILD_DB_NAME.'`.`hosts` ';
				$req.= 'WHERE `ip` = \''.mysql_escape_string($socket['remote_ip']).'\' ';
				$req.= 'AND `type` = \'trust\' AND (`expires` > NOW() OR `expires` IS NULL)';
				$res = @mysql_query($req);
				$res = @mysql_fetch_row($res);
				if ($res) {
					if (!is_array($socket["mail_to"])) $socket["mail_to"]=array();
					$socket["mail_to"][]=array(
						'account'=>null,
						'origin'=>$res[0],
						'email'=>$adress,
					);
					swrite($socket, '250 Relaying granted for you, '.$res[0]);
				} else {
					swrite($socket,"550 Relaying denied.");
				}
			} else {
				swrite($socket,"550 mailbox doesn't exists");
			}
		} else {
			swrite($socket,"550 Address or syntax invalid.");
		}
	}
}

  function pcmd_rset(&$socket,$cmdline) {
    if (isset($socket["mail_from"])) unset($socket["mail_from"]);
    if (isset($socket["mail_to"])) unset($socket["mail_to"]);
    if (isset($socket['antispam'])) unset($socket['antispam']);
    swrite($socket,"250 Wakarimasu !");
  }
  
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

// 451 Please send this email again - Anti-spam protection (should use code 451)
function pcmd_data(&$socket,$cmdline) {
	global $servername;
	if (!isset($socket["mail_to"])) {
		swrite($socket,'503 You must give at least one receipient');
	} else {
		$filename=make_uniq('tmp');
		$wrmail = fopen($filename,'w+');
		if (!$wrmail) {
			swrite($socket,'450 Error while creating output file. Please retry again later.');
		} else {
			$newmail = "Received: from ".$socket["remdat"]."\r\n";
			$newmail.= "\tby $servername (phpinetd by MagicalTux <magicaltux@gmail.com>) with SMTP\r\n";
			$newmail.= "\tversion 1.0; ".date("D, d M Y H:i:s O") . "\r\n";
			fputs($wrmail,$newmail);
			$headers=array();
			$headers[]=str_replace("\r",'',str_replace("\n",'',str_replace("\t",' ',$newmail)));
			swrite($socket,"354 Start mail input. End with a <CRLF>.<CRLF>");
			$lin=sread($socket);
			$head=true;
			do {
				if (!$socket["state"]) {
					fclose($wrmail);
					unlink($filename);
					return; // dead socket
				}
				if ($lin=='') $head=false;
				if ( (substr($lin,0,1)==".") and ($lin!=".") ) $lin=substr($lin,1);
				if ($lin !== null) fputs($wrmail,$lin."\r\n");
				if ($head) {
					if (($lin{0}==' ') or ($lin{0}=="\t")) {
						$h.=' '.ltrim($lin);
					} else {
						$h=&$headers[];
						$h=$lin;
					}
				}
				$lin=sread($socket);
			} while($lin!=".");
			unset($h);
			$q = false;
			foreach($socket["mail_to"] as $data) {
				// Remote delivery
				if (is_null($data['account'])) {
					// mail to MQueue
					$target=make_uniq('mailqueue');
					$out=fopen($target,'w');
					fwrite($out,'Received: (pmaild '.getmypid().' invoked for user '.$data['origin'].'); '.date("D, d M Y H:i:s O")."\r\n");
					fseek($wrmail,0);
					stream_copy_to_stream($wrmail,$out);
					fclose($out);
					$req = 'INSERT INTO `'.PHPMAILD_DB_NAME.'`.`mailqueue` SET ';
					$req.= '`mlid`=\''.mysql_escape_string(basename($target)).'\', ';
					if ((isset($socket["mail_from"])) && (!empty($socket["mail_from"]))) $req.= '`from`=\''.mysql_escape_string($socket["mail_from"]).'\', ';
					$req.= '`to`=\''.mysql_escape_string($data['email']).'\', ';
					$req.= '`queued` = NOW()';
					@mysql_query($req);
					$q = true;
					continue;
				}
				// pre-Local delivery (antivirus/antispam check)
				if ( (run_antivirus($socket, $filename, $data['domain_data']['antivirus'])>0) 
					|| (run_antispam($socket, $filename, $wrmail, $data['domain_data']['antispam'], $data)>0) )
				{
					if (isset($socket["mail_from"])) unset($socket["mail_from"]);
					if (isset($socket["mail_to"])) unset($socket["mail_to"]);
					if (isset($socket['antispam'])) unset($socket['antispam']);
					fclose($wrmail);
					unlink($filename);
					return;
				}
				// Special delivery to HTTP server
				if (substr($data['account'], 0, 4)=='http') {
					$url = $data['account'];
					$c = '?';
					if (strpos($url, '?')!==false) $c='&';
					$adata = array(
						'helo'=>$socket['helo_str'],
						'remote_ip'=>$socket['remote_ip'],
						'remote_host'=>$socket['remote_host'],
						'from'=>$socket['mail_from'],
						'to'=>$data['email'],
					);
					foreach($adata as $var=>$val) {
						$url.=$c.$var.'='.urlencode($val);
						$c='&';
					}
					$ch = curl_init($url);
					curl_setopt($ch, CURLOPT_PUT, true);
					fseek($wrmail, 0);
					curl_setopt($ch, CURLOPT_INFILE, $wrmail);
					curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filename));
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					$res = curl_exec($ch);
					if (!preg_match('/^[0-9]{3} /', $res)) {
						swrite($socket, '450 Remote error while transferring mail, please retry later');
					} else {
						swrite($socket, $res);
					}
					if (isset($socket["mail_from"])) unset($socket["mail_from"]);
					if (isset($socket["mail_to"])) unset($socket["mail_to"]);
					if (isset($socket['antispam'])) unset($socket['antispam']);
					fclose($wrmail);
					unlink($filename);
					return;
				}
				// Local delivery
				$target=make_uniq('domains',$data['domain'],$data['account']);
				$out=fopen($target,'w');
				fwrite($out,'Return-Path: <'.$socket['mail_from'].'>'."\r\n");
				fwrite($out,$data['headers']);
				fseek($wrmail,0);
				stream_copy_to_stream($wrmail,$out);
				fclose($out);
				$size = filesize($target); // get final size
				$p='`'.PHPMAILD_DB_NAME.'`.`z'.$data['domain'].'_';
				$req='INSERT INTO '.$p.'mails` SET `folder`=0, `userid`=\''.mysql_escape_string($data['account']).'\', ';
				$req.='`uniqname`=\''.mysql_escape_string(basename($target)).'\', ';
				$req.='`size` = \''.mysql_escape_string($size).'\'';
				@mysql_query($req);
				$mid=mysql_insert_id();
				$req='';
				foreach($headers as $h) {
					$h=explode(':',$h);
					$head=array_shift($h);
					$cont=implode(':',$h);
					$req.=($req==''?'':', ').'(\''.mysql_escape_string($data['account']).'\', \''.mysql_escape_string($mid).'\', ';
					$req.='\''.mysql_escape_string($head).'\', \''.mysql_escape_string($cont).'\')';
				}
				$req='INSERT INTO '.$p.'mailheaders` (userid, mailid, header, content) VALUES '.$req;
				@mysql_query($req);
			}
			fclose($wrmail);
			unlink($filename);
			swrite($socket,($q?'250 Mail queued, pending delivery.':'250 Mail delivered.'));
			if (isset($socket["mail_from"])) unset($socket["mail_from"]);
			if (isset($socket["mail_to"])) unset($socket["mail_to"]);
			if (isset($socket['antispam'])) unset($socket['antispam']);
		}
	}
}

function run_antispam(&$socket, $filename, $fh, $as, $data) {
	foreach($as as $a) {
		$fnc='run_as_'.$a;
		if (function_exists($fnc)) {
			$res=$fnc($socket,$filename,$fh, $data);
			if ($res>0) return $res;
		}
	}
	return 0;
}

function run_antivirus(&$socket, $filename, $av) {
	foreach($av as $a) {
		$fnc='run_av_'.$a;
		if (function_exists($fnc)) {
			$res=$fnc($socket,$filename);
			if ($res>0) return $res;
		}
	}
	return 0;
}

function run_as_spamassassin(&$socket, $filename, $fh, $data) {
	$out=make_uniq('tmp');
	$cmd='/usr/bin/spamassassin --exit-code';
	if (is_executable('/usr/bin/spamc')) $cmd='/usr/bin/spamc -E <';
	$cmd.=' '.escapeshellarg($filename).' >'.escapeshellarg($out);
	$res=system($cmd,$rc);
	if (filesize($out)<50) {
		swrite($socket,'400 Problem while running spamassassin...');
		unlink($out);
		return 1;
	}
	$in=fopen($out,'r');
	if (!$in) {
		swrite($socket,'400 Problem opening output form spamassassin...');
		unlink($out);
		return 1;
	}
	fseek($fh,0);
	ftruncate($fh,0);
	stream_copy_to_stream($in,$fh);
	fclose($in);
	unlink($out);
	if (array_search('drop_email_on_spam',$data['domain_data']['flags'])!==false) {
		if ($rc>0) {
			swrite($socket,'500 Message detected as spam, please check your mail.');
			return $rc;
		}
	}
	return 0;
}

function run_av_clam(&$socket, $filename) {
	$sock=fsockopen('127.0.0.1',3310);
	if (!$sock) {
		swrite($socket,'400 Antivirus not responding, please try again later.');
		return 4;
	}
	fwrite($sock,'SCAN '.$filename."\n");
	$res=fgets($sock,4096);
	fclose($sock);
	$res=explode(':',$res);
	if (count($res)>1) array_shift($res);
	$res=trim(implode(':',$res));
	if ($res=='OK') return 0;
	$virus=str_replace(' FOUND','',$res);
	if ($virus!=$res) {
		swrite($socket,'500 Virus '.$virus.' found in your mail. Delivery won\'t be done.');
		return 1;
	}
	swrite($socket,'400 Antivirus answered: '.$res.'. Please try again later.');
	return 3;
}

