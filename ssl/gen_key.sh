#!/bin/sh
# $Id$
# Autogen self-signed certificate
# Details found there :
# http://sial.org/howto/openssl/self-signed/

OPENSSL=`which openssl 2>/dev/null`
if [ x"$OPENSSL" = x ]; then
	echo "Openssl needed. Please install it!"
	exit 1
fi

openssl genrsa 1024 > newkey.key
chmod 0400 newkey.key

echo
echo '*****'
echo "** When asked for Common Name, enter the server's name"
echo '*****'
echo

openssl req -new -x509 -nodes -sha1 -days 365 -key newkey.key > newkey.cert

cat newkey.cert newkey.key >newkey.pem && rm -f newkey.key
chmod 0400 newkey.pem

