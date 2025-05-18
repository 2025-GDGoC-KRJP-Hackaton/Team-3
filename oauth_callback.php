<?php
session_start();

require_once 'vendor/autoload.php';

$connect = mysqli_connect("localhost", "root", "root", "emotional_diary");

if (!$connect) {
    // Log this error instead of die() for a better user experience in production
    error_log("Database Connection failed: " . mysqli_connect_error());
    die("A configuration error occurred. Please try again later.");
}

$client = new Google_Client();
$client->setClientId('177687992017-ppe7n3ljdpibqcqscdovn42ggvpva085.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('http://localhost:8888/EmotionalApp/oauth_callback.php');
$client->addScope(Google_Service_Oauth2::USERINFO_PROFILE);
$client->addScope(Google_Service_Oauth2::USERINFO_EMAIL);

if (isset($_GET['code'])) {
    try {
        $tokenData = $client->fetchAccessTokenWithAuthCode($_GET['code']); // Renamed to $tokenData for clarity

        // Important: Check if the token was successfully fetched
        if (!is_array($tokenData) || !isset($tokenData['access_token'])) {
            // Log the error for debugging
            error_log("OAuth Error: Failed to obtain access token. Response: " . print_r($tokenData, true));
            die("Error: Could not obtain a valid access token from Google. Please try logging in again.");
        }

        // Set the access token for the client to use for the next API call
        $client->setAccessToken($tokenData); // Pass the whole array

        // Store the FULL token data in the session for home.php
        $_SESSION['access_token'] = $tokenData; // <<<<<<<< KEY FIX #1

        $oauth2 = new Google_Service_Oauth2($client);
        $userInfo = $oauth2->userinfo->get();

        $oauth_provider = 'google';
        $oauth_id = $userInfo->id;
        $name = $userInfo->name;
        $email = $userInfo->email;
        $avatar_url = $userInfo->picture;
        $created_at = date('Y-m-d H:i:s');

        $stmt = mysqli_prepare($connect, "SELECT id FROM users WHERE oauth_provider = ? AND oauth_id = ?");
        if (!$stmt) {
            error_log("DB Prepare Error (SELECT): " . mysqli_error($connect));
            die("Database error. Please try again.");
        }
        mysqli_stmt_bind_param($stmt, "ss", $oauth_provider, $oauth_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);

        $user_id = null; // Initialize user_id

        if (mysqli_stmt_num_rows($stmt) > 0) {
            mysqli_stmt_bind_result($stmt, $user_id);
            mysqli_stmt_fetch($stmt);
        } else {
            mysqli_stmt_close($stmt); // Close previous statement before preparing a new one

            $stmt_insert = mysqli_prepare($connect, "INSERT INTO users (oauth_provider, oauth_id, name, email, avatar_url, created_at) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt_insert) {
                error_log("DB Prepare Error (INSERT): " . mysqli_error($connect));
                die("Database error. Please try again.");
            }
            mysqli_stmt_bind_param($stmt_insert, "ssssss", $oauth_provider, $oauth_id, $name, $email, $avatar_url, $created_at);
            if (mysqli_stmt_execute($stmt_insert)) {
                $user_id = mysqli_insert_id($connect);
            } else {
                error_log("DB Insert Error: " . mysqli_stmt_error($stmt_insert));
                mysqli_stmt_close($stmt_insert);
                die("Error creating user account. Please try again.");
            }
            mysqli_stmt_close($stmt_insert);
        }
        mysqli_stmt_close($stmt); // Close the SELECT statement if not already closed

        if ($user_id) {
            $_SESSION['user_id'] = $user_id;
            // Optionally store other details if needed frequently, to avoid DB lookups on every page
            // $_SESSION['user_name'] = $name;
            // $_SESSION['user_picture'] = $avatar_url;
            // However, home.php is already designed to fetch these from Google using the access_token

            header('Location: home.php');
            exit();
        } else {
            // This case should ideally not be reached if DB operations are successful
            error_log("OAuth Callback: User ID was not set after DB operations.");
            die("An unexpected error occurred during login. Please try again.");
        }

    } catch (Exception $e) {
        error_log("OAuth Exception: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
        // Provide a user-friendly error message
        die("An error occurred during the login process (" . htmlspecialchars($e->getMessage()) . "). Please try again or contact support.");
    }
} else {
    // If 'code' is not set, it might be an invalid callback or direct access.
    // Redirecting to login or home is a common practice.
    header('Location: index.php'); // Or wherever your login page is
    exit();
}
mysqli_close($connect);
?>