<?php
$host = "127.0.0.1";
$dbname = "database";
$user = "worker";
$password = "xxxxxx";

$conn = pg_connect("host=$host dbname=$dbname user=$user password=$password")

if (!$conn) {
  echo 'Database Connection Failed.';
  die("Connection Failed: " . pg_last_error());
} else {
  echo 'Database Connection Succeeded.';
}
?>
