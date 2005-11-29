<?
$dir=opendir("log");
$exp=time()-(7200);
while($fil=readdir($dir)) {
  $fil="log/".$fil;
  if (substr($fil,-4)==".log") {
    if (filemtime($fil)<$exp) {
	  echo $fil."\n";
	  unlink($fil);
	}
  }
}
echo "ok\n";
