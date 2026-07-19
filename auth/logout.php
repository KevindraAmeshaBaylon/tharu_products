<?php
/**
 * User Logout Script
 * File: auth/logout.php
 * 
 * This script handles the logout workflow:
 * 1. Initializes the session handler.
 * 2. Clears all session variables.
 * 3. Deletes the session cookie in the client's browser if cookies are used.
 * 4. Destroys the active session on the server.
 * 5. Redirects the user to the login page.
 */

// Start the session to access the current session data
session_start();

// Clear all session variables by resetting the $_SESSION superglobal to an empty array
$_SESSION = array();

// Check if the session is using cookies for session ID storage
if (ini_get("session.use_cookies")) {
    // Retrieve cookie parameters (path, domain, secure, etc.) from the active session configuration
    $params = session_get_cookie_params();
    
    // Invalidate the session cookie by setting its expiration time to the past (42000 seconds ago)
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["current"] // Note: Kept as in the original code to preserve specific configurations
    );
}

// Destroy the session context on the server side
session_destroy();

// Redirect the user back to the login page
header("Location: login.php");

// Terminate script execution to ensure no further code runs
exit;
?>