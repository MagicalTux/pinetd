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

// List of daemons to *not* load
$daemon_noload = array(
);

// phpmaild specific
define('PHPMAILD_STORAGE','/var/spool/phpmaild');
define('PHPMAILD_DEFAULT_DOMAIN','example.com');
define('PHPMAILD_DB_NAME','phpinetd-maild');
// pmaild : max processes count for sending outgoing emails
$pmaild_mta_max_processes = 5;
$pmaild_mta_thread_start_threshold = 5; // start 1 thread each 5 mails to send
$pmaild_mta_max_attempt = 10; // Number of attempts to send an outgoing email
$pmaild_mta_mail_max_lifetime = 48; // Max lifetime (in hours) of an email
// The system will try X times to send the email during Y hours. The two first
// attempts will be followed  by ~2 minutes, if the remote server has some difficulties
// accepting mail, or is running greylisting.

// SSL specific
// Read documentation there :
// http://php.net/transports
$ssl_settings = array(
	25=>array( // SMTP's STARTTLS
		'tls'=>array(
			'verify_peer' => false, // Verify peer certificate?
			'allow_self_signed' => true,
//			'cafile' => HOME_DIR.'ssl/newkey.pem', // Certificate Authority
//			'capath' => HOME_DIR.'ssl/',
			'local_cert' => HOME_DIR.'ssl/newkey.pem', // Local certificate
//			'passphrase' => '', // passphrase for certificate
//			'CN_match' => '', // Common Name of certificate
		),
	),
	995=>array( // POP3S
		'ssl'=>array(
			'verify_peer' => false, // Verify peer certificate?
			'allow_self_signed' => true,
			'local_cert' => HOME_DIR.'ssl/newkey.pem',
//			'passphrase' => '', // passphrase for certificate
		),
	),
);


