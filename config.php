<?php

die('Please edit configuration'."\n");

// configuration for PHP Inetd
$servername="";
$pidfile="system.pid";
$bind_ip=""; // ip de bind pour les services
//  $pasv_ip=""; // a ne définir qu'en cas de NAT
  
$sql_user="";
$sql_pass="";
$sql_host="";
  
  
// FTP specific
$max_users=120;
$max_anonymous=80;
$ftp_server=""; // which server is it ?
$max_users_per_ip=3;
$ftp_owner_u="nobody";
$ftp_owner_g="nogroup";

// phpmaild specific
define('PHPMAILD_STORAGE','/var/spool/phpmaild');
define('PHPMAILD_DEFAULT_DOMAIN','example.com');
define('PHPMAILD_DB_NAME','phpinetd-maild');
// pmaild : max processes count for sending outgoing emails
$pmaild_mta_max_processes = 5;
$pmaild_mta_thread_start_threshold = 5; // start 1 thread each 5 mails to send

