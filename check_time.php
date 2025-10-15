<?php
require_once 'config.php';
$conn = getDBConnection();

$result = $conn->query("SELECT NOW() AS db_time, @@session.time_zone AS db_tz");
$row = $result->fetch_assoc();

echo "PHP time: " . date('Y-m-d H:i:s') . "<br>";
echo "DB time: " . $row['db_time'] . "<br>";
echo "DB timezone: " . $row['db_tz'] . "<br>";
?>
