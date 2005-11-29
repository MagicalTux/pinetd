<?
  function getsql() {
    global $sql_user,$sql_pass,$sql_host;
    return mysql_connect($sql_host,$sql_user,$sql_pass);
  }
?>
