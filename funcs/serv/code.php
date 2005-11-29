<?php

function code($nc,$char='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') {
	$res='';
	while(strlen($res)<$nc) $res.=$char{rand(0,strlen($char)-1)};
	return $res;
}

