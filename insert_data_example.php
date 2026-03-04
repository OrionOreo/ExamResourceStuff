<?php
$query = "INSERT INTO USERS (username, password) VALUES ('$username', '$hashed_password')";

$result = pg_query($conn, $query);
?>
