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
 */

$socket_type=SOCK_STREAM;
$socket_proto=SOL_TCP;
$connect_error="-ERR Please try again later";
$unknown_command="-ERR Command unrecognized";

$srv_info=array();
$srv_info["name"]="IMAP4rev1 Server v2.0 (pmaild v2.0 by MagicalTux <magicaltux@gmail.com>)";
$srv_info["version"]="2.0.0";

function proto_welcome(&$socket) {
	global $home_dir,$servername;
	$socket["log_fp"]=fopen($home_dir."log/pop3-".date("Ymd-His")."-".$socket["remote_ip"].'-'.getmypid().".log","w");
	fputs($socket["log_fp"],"Client : ".$socket["remote_ip"].":".$socket["remote_port"]." connected.\r\n");
	swrite($socket,"+OK $servername POP3 server (phpMaild v1.1 by MagicalTux <magicaltux@gmail.com>) ready.");
}

