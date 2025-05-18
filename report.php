<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';

// --- (1) GLOBAL CONFIGURATION AND DEFINITIONS (Same as home.php) ---
define('GOOGLE_API_KEY', 'YOUR_API_KEY'); // YOUR ACTUAL GOOGLE CLOUD API KEY
define('GEMINI_TEXT_MODEL_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . GOOGLE_API_KEY);

$googleClientConfig = [
    'clientId' => '177687992017-ppe7n3ljdpibqcqscdovn42ggvpva085.apps.googleusercontent.com',
    'clientSecret' => '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',
    'redirectUri' => 'http://localhost:8888/EmotionalApp/oauth_callback.php',
];

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// --- (2) DATABASE CONNECTION ---
$connect = mysqli_connect("localhost", "root", "root", "emotional_diary");
if (!$connect) {
    die("Database connection failed: " . mysqli_connect_error());
}

// --- (3) HELPER FUNCTIONS (Same as home.php) ---
function callGoogleAPI($url, $payload, $apiName = 'Google API') { // Copied from home.php
    $jsonPayload = json_encode($payload);
    if ($jsonPayload === false) { $jsonError = json_last_error_msg(); error_log("{$apiName} - json_encode FAILED: " . $jsonError); throw new Exception("Failed to encode payload for {$apiName}. JSON Error: " . $jsonError, 500); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $jsonPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_POST => 1, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 45]); // Increased timeout slightly
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNo = curl_errno($ch); $curlErrorMessage = curl_error($ch); curl_close($ch);
    if ($curlErrorNo) { throw new Exception("Network error with {$apiName}: " . $curlErrorMessage, 503); }
    $responseData = json_decode($response, true);
    if ($responseData === null && json_last_error() !== JSON_ERROR_NONE) { throw new Exception("Invalid JSON from {$apiName}: " . json_last_error_msg(), 500); }
    if ($httpCode < 200 || $httpCode >= 300) {
        $apiErrorMessage = $apiName . " error" . (isset($responseData['error']['message']) ? (": " . $responseData['error']['message']) : (is_string($response) && strlen($response) < 500 ? (": " . $response) : ""));
        error_log("{$apiName} Error (HTTP {$httpCode}) for URL {$url}: " . ($responseData ? json_encode($responseData) : $response));
        throw new Exception($apiErrorMessage, $httpCode);
    } return $responseData;
}

// --- (4) FETCH AND PROCESS DATA FOR REPORT ---
$userId = $_SESSION['user_id'];
$reportContent = "No entries found to generate a report.";
$recentEmotions = [];
$emotionSummaryForPrompt = "User has not logged many distinct emotions recently.";
$dominantEmotion = "neutral"; // Default

// Fetch, for example, the last 30 entries with emotion data
$stmt = mysqli_prepare($connect, "SELECT emotion, timestamp FROM daily_entries WHERE user_id = ? AND emotion IS NOT NULL AND emotion != '' ORDER BY timestamp DESC LIMIT 30");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $recentEmotions[] = strtolower(trim($row['emotion']));
    }
    mysqli_stmt_close($stmt);
} else {
    error_log("Report page: DB error fetching emotions - " . mysqli_error($connect));
    $reportContent = "Could not retrieve data to generate a report at this time.";
}
mysqli_close($connect);

if (!empty($recentEmotions)) {
    $emotionCounts = array_count_values($recentEmotions);
    arsort($emotionCounts); 

    $summaryParts = [];
    $first = true;
    foreach ($emotionCounts as $emotion => $count) {
        if ($first) {
            $dominantEmotion = $emotion; // Get the most frequent emotion
            $first = false;
        }
        $summaryParts[] = "{$emotion} (logged {$count} times)";
    }
    if (!empty($summaryParts)) {
        $emotionSummaryForPrompt = "In their recent entries, the user has reported the following emotions (most frequent first): " . implode(', ', $summaryParts) . ". The most dominant recent emotion appears to be '{$dominantEmotion}'.";
    }

    // --- (5) GENERATE AI REPORT WITH MUSIC RECOMMENDATION ---
    $reportPrompt = "You are a supportive and insightful AI assistant.
    Based on the user's recent emotion summary, provide:
    1.  A brief well-being analysis (around 150-250 words):
        -   Your tone should be empathetic, constructive, and encouraging.
        -   Focus on identifying potential patterns and offering gentle reflections.
        -   If challenging emotions are frequent, gently acknowledge them and suggest general positive coping strategies (e.g., mindfulness, journaling, talking to someone).
        -   If positive emotions are frequent, affirm these.
        -   Offer one or two general well-being recommendations.
    2.  Music Recommendations (3-5 suggestions):
        -   Based on the overall emotional trend or the most dominant emotion from the summary, suggest a few music genres or specific (well-known) artists/songs that might resonate or be helpful.
        -   For example, if the mood is often 'sad' or 'uneasy', you might suggest calming ambient music, uplifting indie pop, or introspective classical pieces. If 'joyful' or 'sparkling', suggest upbeat genres or energetic artists.
        -   Briefly explain why each suggestion might be suitable for the inferred mood.
        -   Format the music recommendations clearly, perhaps as a list.

    User's recent emotion summary:
    {$emotionSummaryForPrompt}

    IMPORTANT:
    -   Start your response directly. Do not use any preamble like 'Here is your report:'.
    -   Structure your response with a clear section for 'Well-being Analysis' and another for 'Music Recommendations'.
    -   Avoid making definitive diagnoses or giving prescriptive medical/psychological advice.
    -   Always include this exact disclaimer at the very end of your entire response: 'Disclaimer: This AI analysis and music recommendations are for informational and entertainment purposes only and not a substitute for professional mental health advice or therapy. If you have concerns, please consult a qualified professional.'";

    $reportPayload = [
        'contents' => [['parts' => [['text' => $reportPrompt]]]],
        'generationConfig' => [ 'temperature' => 0.7, 'maxOutputTokens' => 700 ] // Increased max tokens for more content
    ];

    try {
        $apiResponse = callGoogleAPI(GEMINI_TEXT_MODEL_URL, $reportPayload, 'Gemini Report Generation');
        if (!empty($apiResponse['candidates'][0]['content']['parts'][0]['text'])) {
            $reportContent = nl2br(htmlspecialchars(trim($apiResponse['candidates'][0]['content']['parts'][0]['text'])));
        } else {
            $reportContent = "Could not generate a detailed report at this time. Please try again later.";
            if(isset($apiResponse['error']['message'])) {
                $reportContent .= " (Error: " . htmlspecialchars($apiResponse['error']['message']) . ")";
            }
        }
    } catch (Exception $e) {
        error_log("Report Generation API call failed: " . $e->getMessage());
        $reportContent = "An error occurred while generating your report: " . htmlspecialchars($e->getMessage());
    }
}

// --- (6) HTML OUTPUT SETUP (User Info) ---
// ... (User info fetching logic remains the same as your provided code) ...
$client = new Google_Client();
$client->setClientId($googleClientConfig['clientId']); $client->setClientSecret($googleClientConfig['clientSecret']);
$client->setRedirectUri($googleClientConfig['redirectUri']);
$client->addScope(Google_Service_Oauth2::USERINFO_PROFILE); $client->addScope(Google_Service_Oauth2::USERINFO_EMAIL);
$userNameForDisplay = 'Guest'; $userPictureForDisplay = ''; $isUserLoggedIn = false;
if (isset($_SESSION['access_token']) && $_SESSION['access_token']) {
    try {
        $client->setAccessToken($_SESSION['access_token']);
        if ($client->isAccessTokenExpired()) { unset($_SESSION['access_token']); unset($_SESSION['user_id']); }
        else {
            $oauth2 = new Google_Service_Oauth2($client); $userinfo = $oauth2->userinfo->get();
            $userNameForDisplay = isset($userinfo['name']) ? htmlspecialchars($userinfo['name']) : 'User';
            $userPictureForDisplay = isset($userinfo['picture']) ? htmlspecialchars($userinfo['picture']) : '';
            $isUserLoggedIn = true;
        }
    } catch (Exception $e) { error_log("OAuth UserInfo Display Error (report.php): " . $e->getMessage()); unset($_SESSION['access_token']); unset($_SESSION['user_id']); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EmoDiary - Your Emotional Report</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@100..900&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Ubuntu", sans-serif;}
        body { background-color: #f8f9fa; color: #212529; line-height: 1.6; }
        .page-container { margin: auto; padding: 0 100px 100px 100px;}

        header { display: flex; justify-content: space-between; align-items: center; padding: 20px 0; margin-bottom: 30px; border-bottom: 1px solid #e0e0e0;}
        .logo { font-size: 28px; font-weight: bold; color: #000; }
        .header-user-info { display: flex; align-items: center; gap: 10px; }
        .header-user-info img { width: 40px; height: 40px; border-radius: 50%; }
        .header-user-info span { font-size: 0.9em; margin-right: 10px; }
        .header-user-info a { color: #007bff; text-decoration: none; font-size: 0.9em; }
        .header-user-info a:hover { text-decoration: underline; }
        .login-prompt a { font-weight: bold; color: #007bff; }

        .main-content { margin-bottom: 30px; }
        .main-title { font-size: 28px; font-weight: bold; margin-bottom: 15px; color: #333; text-align: center; }
        .report-subtitle { text-align: center; font-size: 1.1em; color: #555; margin-bottom: 30px; }
        
        .report-card {
            background-color: #fff;
            padding: 25px 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-top: 20px;
        }
        .report-card h2 { /* For sections like "Well-being Analysis" and "Music Recommendations" */
            font-size: 1.4em; /* Slightly smaller than main page title */
            color: #0056b3; /* A different accent color for section titles */
            margin-top: 20px; /* Space above section title if multiple sections */
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        .report-card h2:first-child { /* No top margin for the very first h2 in the card */
             margin-top: 0;
        }
        .report-card p {
            margin-bottom: 1em;
            line-height: 1.7;
            color: #454545;
        }
        .report-card ul {
            list-style-position: inside;
            padding-left: 5px;
            margin-bottom: 1em;
        }
        .report-card li {
            margin-bottom: 0.5em;
        }
        .report-card .disclaimer {
            font-size: 0.85em;
            color: #777;
            margin-top: 25px;
            border-top: 1px solid #eee;
            padding-top: 15px;
            font-style: italic;
        }

        .bottom-nav { background-color: #ffffff; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-around; padding: 10px 0; position: fixed; bottom: 0; left: 0; width: 100%; height: 70px; box-shadow: 0 -2px 5px rgba(0,0,0,0.05); z-index: 100; }
        .bottom-nav-item { text-align: center; flex-grow: 1; display: flex; justify-content: center; align-items: center; }
        .bottom-nav-item a { text-decoration: none; display: flex; flex-direction: column; align-items: center; color: #6c757d; }
        .bottom-nav-icon, .material-icons { font-size: 28px !important; cursor: pointer; color: #6c757d; transition: color 0.2s ease; }
        .bottom-nav-item:hover .bottom-nav-icon, .bottom-nav-item:hover .material-icons,
        .bottom-nav-item.active .bottom-nav-icon, .bottom-nav-item.active .material-icons { color: #007bff; }
        .bottom-nav-label { font-size: 0.7em; margin-top: 2px; } 

        @media (max-width: 768px) { /* ... (responsive styles as before) ... */ }
        @media (max-width: 480px) { /* ... (responsive styles as before) ... */ }
    </style>
</head>
<body>
    <div class="page-container">
        <header>
            <div class="logo">BEmo</div>
            <div class="header-user-info">
                <?php
                    if ($isUserLoggedIn) {
                        if (!empty($userPictureForDisplay)) { echo "<img src='" . $userPictureForDisplay . "' alt='User Profile'>"; }
                        echo "<span>" . $userNameForDisplay . "</span>";
                        echo "<a href='logout.php'>(Logout)</a>";
                    } else { echo "<span class='login-prompt'><a href='index.php'>Login to get started</a></span>"; }
                ?>
            </div>
        </header>

        <div class="main-content">
            <h1 class="main-title">Your Emotional Well-being Report</h1>
            <p class="report-subtitle">An AI-generated reflection and music recommendations based on your recent diary entries.</p>
            
            <div class="report-card">
                <!-- PHP will echo the report content here, which might include its own H2s -->
                <?php echo $reportContent; ?>
            </div>
        </div> 
    </div> 

    <div class="bottom-nav">
        <div class="bottom-nav-item"> <a href="home.php" title="Create Entry"> <span class="material-icons">add_circle</span> </a> </div>
        <div class="bottom-nav-item"> <a href="calendar.php" title="View Diary"> <span class="material-icons">edit_calendar</span> </a> </div>
        <div class="bottom-nav-item active"> <a href="report.php" title="Report">  <span class="material-icons">insights</span> </a> </div>
        <!-- <div class="bottom-nav-item"> <a href="settings.php" title="Settings"> <span class="material-icons">settings</span> </a> </div> -->
    </div>
    <script>
        // No specific JavaScript needed for this page to function for now.
    </script>
</body>
</html>
