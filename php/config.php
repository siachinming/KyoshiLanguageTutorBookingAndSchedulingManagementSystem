<?php
$servername = "localhost";
$username = "web38";
$password = "ddwd2703web";
$database = "kyoshi";     

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>