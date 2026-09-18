<?php
require_once 'includes/auth.php';

// Redirect logged in users to their dashboard
if (isLoggedIn()) {
    redirectByRole();
}

// Redirect to login
header('Location: login.php');
exit();
?>

