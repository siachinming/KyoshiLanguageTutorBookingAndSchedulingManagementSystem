<?php
include "config.php";

$fullname = "Ali";
$email = "ali@gmail.com";
$password = password_hash("Ali@12345", PASSWORD_DEFAULT);
$role = "admin";

$sql = "INSERT INTO users (fullname, email, password, role)
        VALUES ('$fullname', '$email', '$password', '$role')";

if (mysqli_query($conn, $sql)) {
    echo "Admin created successfully!";
} else {
    echo "Error: " . mysqli_error($conn);
}
?>