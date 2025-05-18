<?php
session_start(); // Start session to check if user is already logged in

// If user is already logged in, redirect to home.php
if (isset($_SESSION['user_id']) && isset($_SESSION['access_token'])) { // Check for access_token too for robustness
    header('Location: home.php');
    exit();
}

// Google OAuth setup
require_once 'vendor/autoload.php';

$client = new Google_Client();
// Ensure these are your actual credentials and redirect URI
$client->setClientId('177687992017-ppe7n3ljdpibqcqscdovn42ggvpva085.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('http://localhost:8888/EmotionalApp/oauth_callback.php');
$client->addScope(Google_Service_Oauth2::USERINFO_PROFILE);
$client->addScope(Google_Service_Oauth2::USERINFO_EMAIL);

$authUrl = $client->createAuthUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BEmo - Welcome</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap');

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: "Ubuntu", sans-serif;
            overflow: hidden; 
        }

        body {
            display: flex;
            flex-direction: column; /* Stack title and button vertically */
            justify-content: center;
            align-items: center;
            text-align: center;
            background-image: url('images/login.png'); /* <<< MAKE SURE THIS PATH IS CORRECT to your background image */
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            position: relative; 
        }

        /* Optional: Add a semi-transparent overlay for better text readability */
        /*
        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.3); 
            z-index: 1; 
        }
        */

        /* Content needs to be above the overlay if one is used */
        .app-title-login, .google-login-button {
            position: relative;
            z-index: 2;
        }

        .app-title-login {
            font-size: 72px; /* Even larger title */
            font-weight: 700;
            color: #ffffff; 
            margin-bottom: 40px; 
            text-shadow: 3px 3px 8px rgba(0, 0, 0, 0.6); 
        }

        .google-login-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: #ffffff; 
            color: #333; 
            border: 1px solid #dadce0;
            border-radius: 4px; 
            padding: 12px 28px; /* Slightly more padding */
            font-size: 15px; 
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
        }
        .google-login-button:hover {
            background-color: #f8f9fa;
            box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15);
        }
        .google-login-button:active {
            background-color: #e8eaed;
        }

        

    </style>
</head>
<body>
    <!-- Removed the .login-wrapper div -->
    <h1 class="app-title-login">BEmo</h1>
    <a href="<?php echo htmlspecialchars($authUrl); ?>" class="google-login-button">
        
        <span>Sign in with Google</span>
    </a>
</body>
</html>