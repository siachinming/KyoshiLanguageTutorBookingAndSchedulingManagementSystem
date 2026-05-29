<?php
$servername = "localhost";
$username = "web38";
$password = "ddwd2703web";
$database = "kyoshi";     

$conn = mysqli_connect($servername, $username, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

define('SMTP_USER','sohisabella87@gmail.com');
define('SMTP_PASS','lcgv pyln bhkr libc');
define('STRIPE_SECRET_KEY','sk_test_51TVVHPAjFaJboEtiYlcEc1imL3qWgIBzGa87CvWHFlyuZrhOEA8kDxnS1J7LItiLJJzHKLsgGyg5DNI8oVaJ6KmD00UN9FQYC9');
date_default_timezone_set('Asia/Kuala_Lumpur');
?>