<?php
// pmaild v2.0
  // SMTP server rewrote from scratch (I miss my old source)
  // vars :
  // $current_socket : socket en cours
  // $mysql_cnx : connexion MySQL
  // $home_dir : r?p home (fini par / )
  // $servername : nom du serv (eg. Ringo.FF.st ) 
  
  $socket_type=SOCK_STREAM;
  $socket_proto=SOL_TCP;
  $connect_error="420 Please try again later";
  $unknown_command="500 Command unrecognized";
  
  $srv_info=array();
  $srv_info["name"]="ESMTP Server v2.0 (pmaild v2.0 by MagicalTux <MagicalTux@gmail.com>)";
  $srv_info["version"]="2.0.0";



  function proto_welcome(&$socket) {
    global $home_dir,$servername;
    $socket["log_fp"]=fopen($home_dir."log/smtp-".date("Ymd-His")."-".$socket["remote_ip"].".log","w");
    fputs($socket["log_fp"],"Client : ".$socket["remote_ip"].":".$socket["remote_port"]." connected.\r\n");
    swrite($socket,"220 $servername ESMTP (phpMaild v1.1 by MagicalTux <MagicalTux@gmail.com>) ready.");
  }
  function pcmd_quit(&$socket,$cmdline) {
  	global $servername;
    swrite($socket,'221 '.$servername.' closing control connexion. Mata ne~ !',true);
    sleep(2); // make sure buffer is flushed
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
  	global $servername;
    if ($socket["helo"]) {
      swrite($socket,'503 I don\'t know why you say good bye, I say hello~ (Hello goodbye, The Beatles)');
    } else {
      $socket["helo"]=true;
      $remote=explode(" ",$cmdline);
      $remote=$remote[1];
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
        swrite($socket,"250 8BITMIME");
      }
    }
  }
  function pcmd_help(&$socket,$cmdline) {
    // help system...
    swrite($socket,'214 http://www.faqs.org/rfcs/rfc2821.html');
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
	if ($pos===false) return NULL;
	$user = substr($addr,0,$pos);
	$domain=substr($addr,$pos+1);
	$req='SELECT domainid, defaultuser, state, flags, antispam, antivirus FROM `phpinetd-maild`.`domains` WHERE domain=\''.mysql_escape_string($domain).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	$domain_antispam=false;
	if ( ($res['antispam']!='') or ($res['antivirus']!='')) {
		// special case : if we have to use an antispam system, we MUST have only one RCPT TO line
		$domain_antispam=true;
		if (count($socket['mail_to'])>0) return '400 Please provide only ONE RCPT TO';
	}
	$res['antispam']=explode(',',$res['antispam']);
	$res['antivirus']=explode(',',$res['antivirus']);
	$res['flags']=explode(',',$res['flags']);
	if ($res['state']=='new') {
		$req='CREATE TABLE `phpinetd-maild`.`z'.$res['domainid'].'_accounts` ( `id` int(10) unsigned NOT NULL auto_increment, ';
		$req.='`user` varchar(64) NOT NULL default \'\', `password` varchar(40) default \'\', ';
		$req.='`redirect` varchar(255) default NULL, PRIMARY KEY  (`id`), ';
		$req.='UNIQUE KEY `user` (`user`)) ENGINE=MyISAM DEFAULT CHARSET=latin1';
		@mysql_query($req);
		$req='CREATE TABLE `phpinetd-maild`.`z'.$res['domainid'].'_folders` (`id` int(10) unsigned NOT NULL auto_increment, ';
		$req.='`account` int(10) unsigned NOT NULL default \'0\', `name` varchar(32) NOT NULL default \'\', ';
		$req.='`parent` int(10) unsigned NOT NULL default \'0\', PRIMARY KEY  (`id`), ';
		$req.='UNIQUE KEY `account` (`account`,`name`), KEY `parent` (`parent`)';
		$req.=') ENGINE=MyISAM DEFAULT CHARSET=latin1';
		@mysql_query($req);
		$req='CREATE TABLE `phpinetd-maild`.`z'.$res['domainid'].'_mailheaders` (`id` bigint(20) unsigned NOT NULL auto_increment, ';
		$req.='`userid` int(10) unsigned NOT NULL default \'0\', `mailid` int(10) unsigned NOT NULL default \'0\', ';
		$req.='`header` varchar(64) NOT NULL default \'\', `content` text NOT NULL, ';
		$req.='PRIMARY KEY  (`id`), KEY `userid` (`userid`), KEY `mailid` (`mailid`)) ';
		$req.='ENGINE=MyISAM DEFAULT CHARSET=latin1';
		@mysql_query($req);
		$req='CREATE TABLE `phpinetd-maild`.`z'.$res['domainid'].'_mails` (`mailid` int(10) unsigned NOT NULL auto_increment, ';
		$req.='`folder` int(10) unsigned NOT NULL default \'0\', `userid` int(10) unsigned NOT NULL default \'0\', ';
		$req.='`uniqname` varchar(128) NOT NULL default \'\', `flags` set(\'unread\',\'deleted\') NOT NULL default \'unread\', ';
		$req.='PRIMARY KEY  (`mailid`), UNIQUE KEY `userid` (`userid`,`uniqname`), KEY `folder` (`folder`) ';
		$req.=') ENGINE=MyISAM DEFAULT CHARSET=latin1';
		@mysql_query($req);
		$req='UPDATE `phpinetd-maild`.`domains` SET state=\'active\' WHERE domainid=\''.mysql_escape_string($res['domainid']).'\'';
		@mysql_query($req);
	}

	$domain_data=$res;
	// table prefix
	$p='`phpinetd-maild`.`z'.$res['domainid'].'_';
	// check for account
	$req='SELECT id FROM '.$p.'accounts` WHERE user=\''.mysql_escape_string($user).'\'';
	$res=@mysql_query($req);
	$res=@mysql_fetch_assoc($res);
	if (!$res) {
		if (array_search('create_account_on_mail',$domain_data['flags'])!==false) {
			$req='INSERT INTO '.$p.'accounts` SET user=\''.mysql_escape_string($user).'\', password=NULL';
			if (!@mysql_query($req)) return '450 Temporary error in domain name. Please check SQL state.';
			$account_id=mysql_insert_id();
		} else {
			if (!is_null($domain_data['defaultuser'])) {
				$account_id=$domain_data['defaultuser'];
			} else {
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
	$socket['antispam']=$domain_antispam;
	return $res;
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
				swrite($socket,'250 '.$rel['email'].' ('.$rel['domain'].':'.$rel['account'].') : Receipient Ok.');
				if (!is_array($socket["mail_to"])) $socket["mail_to"]=array();
				$socket["mail_to"][]=$rel;
			} elseif (is_string($rel)) {
				swrite($socket,$rel);
			} elseif ($rel===false) {
				swrite($socket,"550 Relaying denied.");
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
	system('mkdir -p '.escapeshellarg($path));
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
			$newmail.= "\tversion 1.0; ".date("D, d M Y H:i:s T") . "\r\n";
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
			foreach($socket["mail_to"] as $data) {
				if ( (run_antivirus($socket, $filename, $data['domain_data']['antivirus'])>0) 
					|| (run_antispam($socket, $filename, $wrmail, $data['domain_data']['antispam'])>0) )
				{
					if (isset($socket["mail_from"])) unset($socket["mail_from"]);
					if (isset($socket["mail_to"])) unset($socket["mail_to"]);
					if (isset($socket['antispam'])) unset($socket['antispam']);
					fclose($wrmail);
					unlink($filename);
					return;
				}
				$target=make_uniq('domains',$data['domain'],$data['account']);
				$out=fopen($target,'w');
				fwrite($out,'Return-Path: <'.$socket['mail_from'].'>'."\r\n");
				fwrite($out,$data['headers']);
				fseek($wrmail,0);
				stream_copy_to_stream($wrmail,$out);
				$p='`phpinetd-maild`.`z'.$data['domain'].'_';
				$req='INSERT INTO '.$p.'mails` SET folder=0, userid=\''.mysql_escape_string($data['account']).'\', ';
				$req.='uniqname=\''.mysql_escape_string(basename($target)).'\'';
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
			swrite($socket,'250 Mail delivered.');
			if (isset($socket["mail_from"])) unset($socket["mail_from"]);
			if (isset($socket["mail_to"])) unset($socket["mail_to"]);
			if (isset($socket['antispam'])) unset($socket['antispam']);
		}
	}
}

function run_antispam(&$socket, $filename, $fh, $as) {
	foreach($as as $a) {
		$fnc='run_as_'.$a;
		if (function_exists($fnc)) {
			$res=$fnc($socket,$filename,$fh);
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

function run_as_spamassassin(&$socket, $filename, $fh) {
	$out=make_uniq('tmp');
	$cmd='/usr/bin/spamassassin '.escapeshellarg($filename).' >'.escapeshellarg($out);
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

