<?php
/* pmaild v2.0
 * POP3 server rewrote from scratch (missing old sourcecode)
 * $Id$
 *
 * This is just a redirection to the normal POP server, with a SSL layer
 */

if (!extension_loaded('openssl')) exit(180); // need SSL extension
if (!isset($ssl_settings[$server_port])) exit(180); // need SSL settings

if (!defined('PINETD_SOCKET_TYPE')) define('PINETD_SOCKET_TYPE', 'ssl');

require_once(dirname(__FILE__).'/110.php');

