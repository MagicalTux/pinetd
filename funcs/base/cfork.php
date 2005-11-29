<?
  // cfork() : forking
  function cfork($complete = true) {
    $pid=pcntl_fork();
    if ($pid == -1) return $pid;
    if ($pid) return $pid;
    if ($complete) {
      if (!posix_setsid()) {
        exit;
      }
    }
//    putenv("SHUTDOWN=0");
//    putenv("RELOAD=0");
    pcntl_signal(SIGUSR1,SIG_IGN);
    pcntl_signal(SIGUSR2,SIG_IGN);
    pcntl_signal(SIGHUP,SIG_IGN);
    pcntl_signal(SIGTERM,SIG_IGN);
    pcntl_signal(SIGCHLD,SIG_DFL); // fixes bug on error return (-1; 0) if php was compiled with built-in support for sig_chld
    return $pid;
  }
//  putenv("SHUTDOWN=0");
//  putenv("RELOAD=0");
//  $lastsig=array();
//  function dosignal($signo) {
//    logstr("Sig $signo caught");
//    global $lastsig;
//    switch($signo) {
//      case SIGTERM: case SIGUSR1: 
//        if (getenv("SHUTDOWN")!= 0) exit;
//        putenv("SHUTDOWN=1"); break;
//      case SIGHUP: putenv("RELOAD=1"); break;
//    }
//    $lastsig[]=$sig;
//  }
//  pcntl_signal(SIGUSR1,"dosignal");
//  pcntl_signal(SIGUSR2,"dosignal");
//  pcntl_signal(SIGHUP,"dosignal");
//  pcntl_signal(SIGTERM,"dosignal");
?>