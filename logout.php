<?php
// logout.php
session_start();
session_unset(); // Unset all session variables
session_destroy(); // Destroy the session
header('Location: home.php'); // Redirect to home page or login page
exit();
?>