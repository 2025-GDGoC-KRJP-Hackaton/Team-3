<?php
session_start();
ini_set('display_errors', 1); // For development, 0 for production
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';

// --- (1) GLOBAL CONFIGURATION AND DEFINITIONS ---
define('GOOGLE_API_KEY', 'AIzaSyBGqnTxfGMly2mQUsTBHtyqXLxsw2hHWI4'); // YOUR ACTUAL GOOGLE CLOUD API KEY
define('UPLOADS_DIR', __DIR__ . '/uploads/');
define('VISION_API_URL', 'https://vision.googleapis.com/v1/images:annotate?key=' . GOOGLE_API_KEY);
define('GEMINI_TEXT_MODEL_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . GOOGLE_API_KEY);

$googleClientConfig = [
    'clientId' => '177687992017-ppe7n3ljdpibqcqscdovn42ggvpva085.apps.googleusercontent.com',
    'clientSecret' => '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',
    'redirectUri' => 'http://localhost:8888/EmotionalApp/oauth_callback.php',
];

// --- (1.5) Check for a pre-selected date from calendar.php ---
$preselected_date_str = null;
$preselected_date_display = null;
if (isset($_GET['date'])) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
        $d = DateTime::createFromFormat('Y-m-d', $_GET['date']);
        if ($d && $d->format('Y-m-d') === $_GET['date']) {
            $preselected_date_str = $_GET['date'];
            $preselected_date_display = $d->format('F j, Y');
        }
    }
}

// --- (2) DATABASE CONNECTION ---
$connect = mysqli_connect("localhost", "root", "root", "emotional_diary");

// --- (3) HELPER FUNCTIONS ---
function sendJsonError($message, $statusCode = 400, $details = null) {
    if (!headers_sent()) { http_response_code($statusCode); header('Content-Type: application/json; charset=UTF-8'); }
    $errorResponse = ['success' => false, 'error' => $message]; if ($details) { $errorResponse['details'] = $details; }
    error_log("JSON Error Sent: " . json_encode($errorResponse)); echo json_encode($errorResponse); exit();
}
function callGoogleAPI($url, $payload, $apiName = 'Google API') {
    $jsonPayload = json_encode($payload);
    if ($jsonPayload === false) { $jsonError = json_last_error_msg(); error_log("{$apiName} - json_encode FAILED: " . $jsonError); throw new Exception("Failed to encode payload for {$apiName}. JSON Error: " . $jsonError, 500); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $jsonPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_POST => 1, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 45]);
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
function interpretUserVibe($userVibeText) {
    if (empty(trim($userVibeText))) { return "neutral"; }
    return strtolower(trim($userVibeText));
}

// --- (4) POST REQUEST HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!headers_sent()) { header('Content-Type: application/json; charset=UTF-8'); }
    if (!$connect) { sendJsonError('DB Connection Failed', 503); }
    if (GOOGLE_API_KEY === 'YOUR_GOOGLE_CLOUD_API_KEY' || strpos(GOOGLE_API_KEY, 'AIzaSy') !== 0) { sendJsonError('API Key not configured.', 500); }
    if (!is_dir(UPLOADS_DIR)) { if (!mkdir(UPLOADS_DIR, 0775, true) && !is_dir(UPLOADS_DIR)) { sendJsonError('Failed to create uploads dir.', 500); } }
    if (!is_writable(UPLOADS_DIR)) { sendJsonError('Uploads dir not writable.', 500); }
    if (!isset($_SESSION['user_id'])) { sendJsonError('User not logged in.', 401); }

    if (!isset($_FILES['imageFile']) || $_FILES['imageFile']['error'] !== UPLOAD_ERR_OK) {
        $phpFileUploadErrors = [ UPLOAD_ERR_OK => 'No errors.', UPLOAD_ERR_INI_SIZE => 'Exceeds upload_max_filesize.', UPLOAD_ERR_FORM_SIZE => 'Exceeds MAX_FILE_SIZE.', UPLOAD_ERR_PARTIAL => 'Partially uploaded.', UPLOAD_ERR_NO_FILE => 'No file uploaded.', UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder.', UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk.', UPLOAD_ERR_EXTENSION => 'PHP extension stopped upload.' ];
        $errorCode = $_FILES['imageFile']['error'] ?? UPLOAD_ERR_NO_FILE;
        sendJsonError('Image upload failed: ' . ($phpFileUploadErrors[$errorCode] ?? 'Unknown error.'), 400, ['code' => $errorCode]);
    }

    $imageFile = $_FILES['imageFile'];
    $selectedEmotion = isset($_POST['selected_emotion']) ? htmlspecialchars(trim($_POST['selected_emotion']), ENT_QUOTES, 'UTF-8') : 'neutral';
    $interpretedMood = strtolower($selectedEmotion);
    $userVibeInputForDB = $selectedEmotion;
    $userWritingStyleExample = isset($_POST['writingStyle']) ? trim($_POST['writingStyle']) : '';

    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $imageMimeType = mime_content_type($imageFile['tmp_name']);
    if (!$imageMimeType || !in_array($imageMimeType, $allowedMimeTypes)) { sendJsonError('Invalid image type.', 415); }
    if ($imageFile['size'] > 4 * 1024 * 1024) { sendJsonError('Image file size exceeds 4MB.', 413); }
    $fileExtension = strtolower(pathinfo($imageFile['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    if (!in_array($fileExtension, ['jpg', 'jpeg', 'png', 'webp'])) $fileExtension = 'jpg';
    $imageFileName = uniqid('img_', true) . '.' . $fileExtension;
    $imagePath = UPLOADS_DIR . $imageFileName;
    if (!move_uploaded_file($imageFile['tmp_name'], $imagePath)) { sendJsonError('Failed to save image.', 500); }
    $imageDataForApi = file_get_contents($imagePath);
    if ($imageDataForApi === false || empty($imageDataForApi)) { if(file_exists($imagePath)) unlink($imagePath); sendJsonError('Could not read image data.', 500); }
    $imageBase64 = base64_encode($imageDataForApi);
    if ($imageBase64 === false || empty($imageBase64)) { if(file_exists($imagePath)) unlink($imagePath); sendJsonError('Failed to encode image.', 500); }
    
    $visionRequestPayload = ['requests' => [['image' => ['content' => $imageBase64],'features' => [['type' => 'LABEL_DETECTION', 'maxResults' => 10],['type' => 'WEB_DETECTION', 'maxResults' => 5],['type' => 'OBJECT_LOCALIZATION', 'maxResults' => 5]]]]];
    $extractedVisionInfo = "No specific features extracted."; // Default
    try {
        $visionDataFromApi = callGoogleAPI(VISION_API_URL, $visionRequestPayload, 'Google Vision API'); // Store full response if needed for debug, but only extract info for prompt
        $labels=[]; if(!empty($visionDataFromApi['responses'][0]['labelAnnotations'])){foreach($visionDataFromApi['responses'][0]['labelAnnotations'] as $a){if(isset($a['description'])&&($a['score']??0)>0.7)$labels[]=$a['description'];}}
        $webEntities=[]; if(!empty($visionDataFromApi['responses'][0]['webDetection']['webEntities'])){foreach($visionDataFromApi['responses'][0]['webDetection']['webEntities'] as $e){if(isset($e['description'])&&($e['score']??0)>0.6)$webEntities[]=$e['description'];}}
        $objects=[]; if(!empty($visionDataFromApi['responses'][0]['localizedObjectAnnotations'])){foreach($visionDataFromApi['responses'][0]['localizedObjectAnnotations'] as $o){if(isset($o['name'])&&($o['score']??0)>0.6)$objects[]=$o['name'];}}
        $significantFeatures=array_values(array_unique(array_filter(array_merge($labels,$webEntities,$objects))));
        if(!empty($significantFeatures)){$extractedVisionInfo="Key elements: ".implode(', ',array_slice($significantFeatures,0,5)).".";}
    } catch (Exception $e) { if(file_exists($imagePath)) unlink($imagePath); sendJsonError("Vision API call failed: " . $e->getMessage(), $e->getCode() ?: 500); }

    $writingStyleInstruction = "";
    if (!empty($userWritingStyleExample)) {
        $styleExampleForPrompt = strip_tags($userWritingStyleExample); $styleExampleForPrompt = preg_replace('/[\r\n]+/', "\n", $styleExampleForPrompt); $styleExampleForPrompt = trim($styleExampleForPrompt);
        if (mb_strlen($styleExampleForPrompt, 'UTF-8') > 10 && mb_strlen($styleExampleForPrompt, 'UTF-8') < 1500) {
             $writingStyleInstruction = "\n\nPlease also try to emulate the following writing style: \"{$styleExampleForPrompt}\". Pay attention to sentence structure, vocabulary, and tone.";
        }
    }
    $diaryPromptText = "You are writing a personal diary. Do **not** include any date. User's Mood: {$interpretedMood}. Image Elements: '{$extractedVisionInfo}'.{$writingStyleInstruction} Write a reflective, first-person diary entry of at least 100 words. If mood is negative, reflect that. If abstract, be observational. No preamble like 'Here is the diary entry:'.";
    $diaryPayload = ['contents' => [['parts' => [['text' => $diaryPromptText]]]], 'generationConfig' => [ 'temperature' => 0.75, 'maxOutputTokens' => 350 ]];
    $diaryEntry = "Could not generate entry."; $geminiApiErrorOccurred = false;
    try {
        $geminiApiResponse = callGoogleAPI(GEMINI_TEXT_MODEL_URL, $diaryPayload, 'Gemini Diary Generation');
        if (!empty($geminiApiResponse['candidates'][0]['content']['parts'][0]['text'])) {
            $diaryEntry = trim($geminiApiResponse['candidates'][0]['content']['parts'][0]['text']);
            $diaryEntry = trim(str_replace(['*', '"'], '', preg_replace('/^Here is the diary entry:/i', '', $diaryEntry)));
        } else { $diaryEntry = "Gemini: " . (isset($geminiApiResponse['error']['message']) ? $geminiApiResponse['error']['message'] : "No text content."); $geminiApiErrorOccurred = true; }
    } catch (Exception $e) { $diaryEntry = "Gemini API call failed: " . $e->getMessage(); error_log($diaryEntry); $geminiApiErrorOccurred = true; }

    $entry_timestamp_sql = "NOW()";
    if (isset($_POST['entry_date']) && !empty($_POST['entry_date'])) {
        $entry_date_from_form = $_POST['entry_date'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date_from_form)) {
            $d_form = DateTime::createFromFormat('Y-m-d', $entry_date_from_form);
            if ($d_form && $d_form->format('Y-m-d') === $entry_date_from_form) {
                $entry_timestamp_sql = "'" . mysqli_real_escape_string($connect, $entry_date_from_form . date(' H:i:s')) . "'";
            }
        }
    }

    if (!$geminiApiErrorOccurred && !empty(trim($diaryEntry)) && stripos($diaryEntry, "could not generate") === false && stripos($diaryEntry, "failed") === false) {
        try {
            $userId = $_SESSION['user_id'];
            $escapedImageFileName = mysqli_real_escape_string($connect, $imageFileName);
            $escapedUserVibeInputForDB = mysqli_real_escape_string($connect, $userVibeInputForDB);
            $escapedInterpretedMood = mysqli_real_escape_string($connect, $interpretedMood);
            $escapedExtractedVisionInfo = mysqli_real_escape_string($connect, $extractedVisionInfo);
            $escapedDiaryEntry = mysqli_real_escape_string($connect, $diaryEntry);
            $sql_insert = "INSERT INTO daily_entries (user_id, image_url, emotion, notes, timestamp, vision_api_summary, gemini_diary_text) VALUES (?, ?, ?, ?, {$entry_timestamp_sql}, ?, ?)";
            $stmt = mysqli_prepare($connect, $sql_insert);
            if (!$stmt) { throw new Exception("DB Prepare Error: " . mysqli_error($connect)); }
            mysqli_stmt_bind_param($stmt, "isssss", $userId, $escapedImageFileName, $escapedInterpretedMood, $escapedUserVibeInputForDB, $escapedExtractedVisionInfo, $escapedDiaryEntry);
            if (!mysqli_stmt_execute($stmt)) { throw new Exception('DB Execute Error: ' . mysqli_stmt_error($stmt)); }
            mysqli_stmt_close($stmt);
            // Only send back what's needed for display
            echo json_encode([ 'success' => true, 'interpretedVibe' => $interpretedMood, 'diaryEntry' => $diaryEntry ]);
            exit();
        } catch (Exception $e) { if(file_exists($imagePath)) unlink($imagePath); sendJsonError('Error saving entry: ' . $e->getMessage(), 500); }
    } else { if(file_exists($imagePath)) unlink($imagePath); $finalErrorMessage = $geminiApiErrorOccurred ? $diaryEntry : "Entry generation failed."; sendJsonError($finalErrorMessage, 500, [ 'interpretedVibe' => $interpretedMood ]); }
    exit();
}

// --- (5) HTML OUTPUT ---
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
    } catch (Exception $e) { error_log("OAuth UserInfo Display Error: " . $e->getMessage()); unset($_SESSION['access_token']); unset($_SESSION['user_id']); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EmoDiary - Create Entry</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@100..900&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Ubuntu", sans-serif;}
        body { background-color: #f8f9fa; color: #212529; line-height: 1.6; }
        .page-container { /*max-width: 1000px;*/ margin: auto; padding: 0 100px 100px 100px;}

        header { display: flex; justify-content: space-between; align-items: center; padding: 20px 0; margin-bottom: 30px; border-bottom: 1px solid #e0e0e0;}
        .logo { font-size: 28px; font-weight: bold; color: #000; }
        .header-user-info { display: flex; align-items: center; gap: 10px; }
        .header-user-info img { width: 40px; height: 40px; border-radius: 50%; }
        .header-user-info span { font-size: 0.9em; }
        .header-user-info a { color: #007bff; text-decoration: none; font-size: 0.9em; }
        .header-user-info a:hover { text-decoration: underline; }
        .login-prompt a { font-weight: bold; color: #007bff; }

        .main-content { margin-bottom: 30px; }
        .main-title { font-size: 28px; font-weight: bold; margin-bottom: 25px; color: #333; text-align: center; }
        
        .form-and-results-wrapper { display: flex; flex-wrap: wrap; gap: 30px; margin-top: 20px; }
        .form-column { flex: 1; min-width: 320px; }
        .results-column { flex: 1; min-width: 320px; display: flex; flex-direction: column; }
        .form-wrapper { background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); height: 100%;}
        
        #resultContainer { 
            margin-top: 0; padding: 25px; border: 1px solid #e0e0e0; 
            border-radius: 8px; background-color: #fff; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.08); 
            min-height: 400px; 
            display: flex; flex-direction: column; 
            flex-grow: 1; 
        }
        #resultsPlaceholder {
            text-align: center; color: #aaa; padding: 40px 20px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            flex-grow: 1; 
        }
        .placeholder-icon-style { font-size: 56px !important; color: #ddd; margin-top: 20px; }
        
        #vibeInterpretationArea, #geminiResultArea { display: none; } /* Initially hidden by JS logic */
        #visionResultArea { display: none !important; } /* Always hidden */

        #resultContainer.has-results #resultsPlaceholder { display: none; }
        #resultContainer.has-results #vibeInterpretationArea,
        #resultContainer.has-results #geminiResultArea { display: block; }

        #resultContainer > div:not(#resultsPlaceholder) { margin-bottom: 20px; }
        #resultContainer > div:last-child:not(#resultsPlaceholder) { margin-bottom: 0; }
        #resultContainer h2 { font-size: 1.3em; color: #333; margin-top: 0; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 8px;}
        #diarySentence { line-height: 1.6; font-size: 1em; overflow-y: auto; max-height: 350px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #495057; font-size: 1.1em; }
        .form-group input[type="text"], .form-group input[type="file"], .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 1em; }
        .form-group textarea { min-height: 80px; resize: vertical; }
        #imagePreview { display:none; max-width: 100%; max-height: 200px; margin-top: 10px; border-radius: 5px; border: 1px solid #eee;}

        .emotion-selector { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-top: 10px; margin-bottom: 20px;}
        .emotion-option { cursor: pointer; text-align: center; transition: transform 0.15s ease-in-out; padding: 5px; }
        .emotion-option img { width: 55px; height: 55px; border: 3px solid transparent; border-radius: 50%; padding: 3px; background-color: #f8f9fa; box-shadow: 0 2px 4px rgba(0,0,0,0.07); }
        .emotion-option input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
        .emotion-option:hover img { transform: translateY(-3px) scale(1.08); border-color: #007bff; }
        .emotion-option input[type="radio"]:checked + img { border-color: #28a745; transform: scale(1); box-shadow: 0 0 0 3px #28a745, 0 2px 5px rgba(0,0,0,0.2); }
        
        button[type="submit"] { display: block; width: 100%; padding: 12px 20px; background-color: black; color: white; border: none; border-radius: 5px; font-size: 1.1em; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease; }
        button[type="submit"]:hover { background-color: #333; }
        button[type="submit"]:disabled { background-color: #6c757d; cursor: not-allowed;}

        #loading { 
            text-align: center; padding: 20px; font-style: italic; color: #555; width:100%;
            display: none; /* Controlled by JS */
            align-items: center; justify-content: center; 
            min-height: 300px; 
            background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
        }
        #errorArea { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin-top:20px; border-radius: 5px; width:100%;}

        .bottom-nav { background-color: #ffffff; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-around; padding: 10px 0; position: fixed; bottom: 0; left: 0; width: 100%; height: 70px; box-shadow: 0 -2px 5px rgba(0,0,0,0.05); z-index: 100; }
        .bottom-nav-item { text-align: center; flex-grow: 1; display: flex; justify-content: center; align-items: center; }
        .bottom-nav-item a { text-decoration: none; display: flex; flex-direction: column; align-items: center; color: #6c757d; }
        .bottom-nav-icon, .material-icons { font-size: 28px !important; cursor: pointer; color: #6c757d; transition: color 0.2s ease; }
        .bottom-nav-item:hover .bottom-nav-icon, .bottom-nav-item:hover .material-icons,
        .bottom-nav-item.active .bottom-nav-icon, .bottom-nav-item.active .material-icons { color: #007bff; }
        .bottom-nav-label { font-size: 0.7em; margin-top: 2px; } 

        @media (max-width: 768px) {
            .form-and-results-wrapper { flex-direction: column; }
            .form-column, .results-column { min-width: 100%; margin-bottom: 20px; }
            .results-column { margin-bottom: 0; }
            .page-container { padding: 0 15px 90px 15px; }
            .main-title { font-size: 24px; }
            .logo { font-size: 24px; }
            .header-user-info span, .header-user-info a { font-size: 0.85em; }
            .form-group label { font-size: 1em; }
            .emotion-option img { width: 50px; height: 50px; }
        }
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
                    } else { echo "<span class='login-prompt'><a href='login.php'>Login to get started</a></span>"; }
                ?>
            </div>
        </header>

        <div class="main-content">
            <h1 class="main-title">Let your emotions speak, we are here to listen.<?php if ($preselected_date_display) echo "for " . htmlspecialchars($preselected_date_display); ?></h1>
            
            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="form-and-results-wrapper">
                    <div class="form-column">
                        <div class="form-wrapper">
                            <form id="diaryForm" method="POST" enctype="multipart/form-data">
                                <?php if ($preselected_date_str): ?>
                                    <input type="hidden" name="entry_date" value="<?php echo htmlspecialchars($preselected_date_str); ?>">
                                <?php endif; ?>

                                <div class="form-group">
                                    <label for="imageFile">1. Upload an Image:</label>
                                    <input type="file" id="imageFile" name="imageFile" accept="image/jpeg,image/png,image/webp" required>
                                    <img id="imagePreview" src="#">
                                </div>

                                <div class="form-group">
                                    <label>2. How are you feeling today?</label>
                                    <div class="emotion-selector">
                                        <?php
                                            $active_emotions = [
                                                'sad' => 'Sad', 'angry' => 'Angry', 'uneasy' => 'Uneasy',
                                                'worried' => 'Worried', 'sparkling' => 'Sparkling', 'bored' => 'Bored',
                                                'surprised' => 'Surprised', 'joyful' => 'Joyful'
                                            ];
                                            foreach ($active_emotions as $img_name => $emotion_value) {
                                                echo '<label class="emotion-option">';
                                                echo '<input type="radio" name="selected_emotion" value="' . htmlspecialchars($emotion_value) . '" required>';
                                                echo '<img src="images/emotions/' . htmlspecialchars($img_name) . '.png" alt="' . htmlspecialchars($emotion_value) . '" title="' . htmlspecialchars($emotion_value) . '">';
                                                echo '</label>';
                                            }
                                        ?>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="writingStyle">3. Your Writing Style (Optional):</label>
                                    <textarea id="writingStyle" name="writingStyle" rows="3" placeholder="Paste a short sample of your writing..."></textarea>
                                </div>
                                <button type="submit" id="submitButton">Generate Diary Entry</button>
                            </form>
                        </div> 
                    </div>

                    <div class="results-column">
                        <!-- Loading div will be shown/hidden by JS and appear here -->
                        <div id="loading"><p>Processing...</p></div>

                        <div id="resultContainer"> 
                            <!-- Placeholder is shown by default -->
                            <div id="resultsPlaceholder" class="results-placeholder-style">
                                <p>Your generated diary entry will appear here once you submit the form.</p>
                                <span class="material-icons placeholder-icon-style">auto_stories</span>
                            </div>
                            <!-- Actual results are hidden by default, shown by JS -->
                            <div id="vibeInterpretationArea"><h2>Selected Mood:</h2><p id="interpretedVibeText"></p></div>
                            <!-- Vision API results are always hidden from user -->
                            <div id="visionResultArea" style="display: none !important;"> 
                                <h2>Image Insights:</h2><pre id="visionData"></pre>
                            </div>
                            <div id="geminiResultArea"><h2>Generated Diary Entry:</h2><p id="diarySentence"></p></div>
                        </div>
                        <div id="errorArea" style="display:none;"></div>
                    </div>
                </div> 

            <?php else: ?>
                <p style="text-align:center; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">Please log in to create a diary entry.</p>
            <?php endif; ?>
        </div> 
    </div> 

    <div class="bottom-nav">
        <div class="bottom-nav-item active"> <a href="home.php" title="Create Entry"> <span class="material-icons">add_circle</span> </a> </div>
        <div class="bottom-nav-item"> <a href="calendar.php" title="View Diary"> <span class="material-icons">edit_calendar</span> </a> </div>
        <div class="bottom-nav-item"> <a href="logout.php" title="Logout">  <span class="material-icons">insights</span> </a> </div>
        <!-- <div class="bottom-nav-item"> <a href="#" title="Profile"> <span class="material-icons">settings</span> </a> </div> -->
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const diaryForm = document.getElementById('diaryForm');
        if (!diaryForm) return;

        const imageFile = document.getElementById('imageFile');
        const imagePreview = document.getElementById('imagePreview');
        const loadingDiv = document.getElementById('loading');
        const resultContainer = document.getElementById('resultContainer');
        const resultsPlaceholder = document.getElementById('resultsPlaceholder');
        const errorArea = document.getElementById('errorArea');
        const interpretedVibeTextP = document.getElementById('interpretedVibeText');
        // const visionDataPre = document.getElementById('visionData'); // Not strictly needed if not displaying
        const diarySentenceP = document.getElementById('diarySentence');
        const submitButton = document.getElementById('submitButton');

        // Get references to the specific result areas to hide/show them
        const vibeArea = document.getElementById('vibeInterpretationArea');
        const geminiArea = document.getElementById('geminiResultArea');


        imageFile.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) { imagePreview.src = e.target.result; imagePreview.style.display = 'block'; };
                reader.readAsDataURL(this.files[0]);
            } else { imagePreview.style.display = 'none'; imagePreview.src = '#'; }
        });

        diaryForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            errorArea.style.display = 'none'; errorArea.textContent = '';
            
            // Hide results sections, show placeholder, then show loading
            resultContainer.classList.remove('has-results'); // Removes visibility of specific result parts
            if(resultsPlaceholder) resultsPlaceholder.style.display = 'flex'; // Ensure placeholder is visible before loading
            if(vibeArea) vibeArea.style.display = 'none';
            if(geminiArea) geminiArea.style.display = 'none';
            
            loadingDiv.style.display = 'flex'; // Show loading
            resultContainer.style.display = 'none'; // Hide the main results box while loading

            submitButton.disabled = true;
            const formData = new FormData(diaryForm);

            try {
                const response = await fetch('home.php', { method: 'POST', body: formData });
                const responseText = await response.text();
                let data;
                try { data = JSON.parse(responseText); } catch (e) {
                    console.error("Failed to parse JSON response:", responseText);
                    throw new Error(`Server returned non-JSON response (status ${response.status}). Preview: ${responseText.substring(0, 300)}...`);
                }

                loadingDiv.style.display = 'none'; // Hide loading
                resultContainer.style.display = 'flex'; // Show results container again

                if (!response.ok || !data.success) {
                    const errorMessage = data.error || `HTTP error! Status: ${response.status}`;
                    console.error("Server error:", data); 
                    throw new Error(errorMessage);
                }

                // Success path
                interpretedVibeTextP.textContent = data.interpretedVibe || 'N/A';
                // No need to populate visionDataPre if #visionResultArea is always hidden
                diarySentenceP.textContent = data.diaryEntry || 'No diary entry generated.';
                
                if(resultsPlaceholder) resultsPlaceholder.style.display = 'none'; // Hide placeholder
                if(vibeArea) vibeArea.style.display = 'block'; // Show specific result parts
                if(geminiArea) geminiArea.style.display = 'block';
                resultContainer.classList.add('has-results'); // For CSS to manage internal visibility if needed

            } catch (error) {
                loadingDiv.style.display = 'none'; 
                resultContainer.style.display = 'flex'; // Show results container (to show error or placeholder)
                errorArea.textContent = error.message || 'A network or script error occurred.';
                errorArea.style.display = 'block';
                if(resultsPlaceholder) resultsPlaceholder.style.display = 'flex'; // Show placeholder again on error
                if(vibeArea) vibeArea.style.display = 'none';
                if(geminiArea) geminiArea.style.display = 'none';
                resultContainer.classList.remove('has-results');
                console.error('Fetch processing error:', error);
            } finally {
                submitButton.disabled = false;
            }
        });
    });
    </script>
</body>
</html>