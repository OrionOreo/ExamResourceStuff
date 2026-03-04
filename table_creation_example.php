<?php
$host = "127.0.0.1";
$dbname = "database";
$user = "worker";
$password = "xxxxxx";

$conn = pg_connect("host=$host dbname=$dbname user=$user password=$password")

if (!$conn) {
  die("Error creating $table_name: " . pg_last_error());
}

$table_name = "users";
$columns = "
    username VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
";

$query = "
CREATE TABLE IF NOT EXISTS $table_name (
    id UUID PRIMARY KEY DEFAULT uuidv4(),
    $columns
)";

$res = pg_query($conn, $query);

if ($res) {
    echo "Table $table_name created sucessfully";
} else {
    echo "Error creating $table_name: " . pg_last_error($conn);
}

pg_close($conn);
?>
