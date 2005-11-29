<?
  function logstr($str) {
    global $home_dir;
    // logs string to main logfile
    $fil=@fopen($home_dir."log/main-".date("Y-m-d").".log","a");
    if (!$fil) return;
    $str="[".date("H:i:s")."] ".$str;
    fputs($fil,$str."\r\n");
    fclose($fil);
  }
?>