<?php
/* pmaild v2.0
 * SimpleFTPd server, with SSL
 * $Id$
 *
 * This is just a redirection to the normal SimpleFTPd server, with a SSL layer
 */

if (!extension_loaded('openssl')) exit(180); // need SSL extension
if (!isset($ssl_settings[$server_port])) exit(180); // need SSL settings

if (!defined('PINETD_SOCKET_TYPE')) define('PINETD_SOCKET_TYPE', 'ssl');

require_once(dirname(__FILE__).'/21.php');

