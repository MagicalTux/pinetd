#!/bin/sh
MK_PHPNC_VERSION="1.4"

echo "Checking packages..."
apt-get -q=2 install gcc make libpng-dev libcurl3-dev curl libares-dev libjpeg-dev \
flex bison libxml2-dev ncurses-dev bzip2 openssl libssl-dev

echo -n "Checking for last version of PHP: "
PHP_VERSION=`curl -s http://fr2.php.net/downloads.php | grep -m1 "^<h1>PHP" | sed -r -e 's/^.*PHP +//;s/<.*>//'`
echo "$PHP_VERSION"

PHP_FILE="php-$PHP_VERSION.tar.bz2"
PHP_DIR="php-$PHP_VERSION"
PHP_NCDIR="php-nc-$PHP_VERSION"
if [ ! -f "$PHP_FILE" ]; then
	echo -n "Downloading PHP $PHP_VERSION..."
	wget -q -O "$PHP_FILE" "http://fr2.php.net/get/$PHP_FILE/from/this/mirror"
	if [ $? != "0" ]; then
		wget -q -O "$PHP_FILE" "http://fr3.php.net/get/$PHP_FILE/from/this/mirror"
		if [ $? != "0" ]; then
			echo "Could not download $PHP_FILE"
			exit 1
		fi
	fi
	echo "done"
fi
if [ ! -d "$PHP_NCDIR" ]; then
	if [ ! -d "$PHP_DIR" ]; then
		echo -n "Extracting PHP..."
		tar xjf "$PHP_FILE"
		if [ $? != "0" ]; then
			echo "failed"
			exit 1
		fi
		echo "done"
	fi
	mv "$PHP_DIR" "$PHP_NCDIR"
fi
cd "$PHP_NCDIR"

echo -n "Configure ...";
./configure >configure.log 2>&1 --prefix=/usr/local/php-nc --without-pear --disable-cgi --enable-sigchild \
--enable-dba --enable-dbx --enable-dio --enable-filepro --enable-ftp --enable-mbstring --with-ncurses \
--enable-pcntl --disable-session --enable-sockets --enable-sysvmsg --enable-sysvsem --enable-sysvshm \
--with-gd --with-jpeg-dir=/usr/lib --with-png-dir --with-zlib --enable-gd-native-ttf --enable-dbase \
--with-mysql=/usr/local/mysql --with-mysqli=/usr/local/mysql/bin/mysql_config --with-openssl=/usr
if [ x"$?" != x"0" ]; then
	echo "FAILED"
	cat configure.log
	exit 1
fi

echo ""
echo -n "Compiling..."
make >make.log 2>&1
echo ""
echo -n "Installing..."
make install >make_install.log 2>&1
echo ""
rm -f /bin/php
ln -s /usr/local/php-nc/bin/php /bin/php
echo "Installation complete."
