<?php
session_start();
ini_set('display_errors', 1); // For development. Set to 0 for production.
error_reporting(E_ALL);

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); // Or your main login page
    exit();
}

// Database connection
$connect = mysqli_connect("localhost", "root", "root", "emotional_diary");
if (!$connect) {
    die("Database connection failed: " . mysqli_connect_error());
}

$userId = $_SESSION['user_id'];
$entriesByDate = []; // This will store diary entries, keyed by 'YYYY-MM-DD'

// Fetch all diary entries for the logged-in user
$stmt = mysqli_prepare($connect, "SELECT id, image_url, emotion, notes, timestamp, vision_api_summary, gemini_diary_text FROM daily_entries WHERE user_id = ? ORDER BY timestamp ASC");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $dateKey = date('Y-m-d', strtotime($row['timestamp']));
        if (!isset($entriesByDate[$dateKey])) { $entriesByDate[$dateKey] = []; }
        $entriesByDate[$dateKey][] = $row;
    }
    mysqli_stmt_close($stmt);
} else { error_log("Failed to prepare statement: " . mysqli_error($connect)); }
mysqli_close($connect);

require_once 'vendor/autoload.php';
$googleClientConfig = [
    'clientId' => '177687992017-ppe7n3ljdpibqcqscdovn42ggvpva085.apps.googleusercontent.com',
    'clientSecret' => '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',
    'redirectUri' => 'http://localhost:8888/EmotionalApp/oauth_callback.php',
];
$client = new Google_Client();
$client->setClientId($googleClientConfig['clientId']);
$client->setClientSecret($googleClientConfig['clientSecret']);
$client->setRedirectUri($googleClientConfig['redirectUri']);
$client->addScope(Google_Service_Oauth2::USERINFO_PROFILE);
$client->addScope(Google_Service_Oauth2::USERINFO_EMAIL);

$userNameForDisplay = 'Guest';
$userPictureForDisplay = '';
$isUserLoggedIn = false;
if (isset($_SESSION['access_token']) && $_SESSION['access_token']) {
    try {
        $client->setAccessToken($_SESSION['access_token']);
        if ($client->isAccessTokenExpired()) {
            unset($_SESSION['access_token']); unset($_SESSION['user_id']);
        } else {
            $oauth2 = new Google_Service_Oauth2($client);
            $userinfo = $oauth2->userinfo->get();
            $userNameForDisplay = isset($userinfo['name']) ? htmlspecialchars($userinfo['name']) : 'User';
            $userPictureForDisplay = isset($userinfo['picture']) ? htmlspecialchars($userinfo['picture']) : '';
            $isUserLoggedIn = true;
        }
    } catch (Exception $e) {
        error_log("OAuth UserInfo Display Error (calendar.php): " . $e->getMessage());
        unset($_SESSION['access_token']); unset($_SESSION['user_id']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EmoDiary - Calendar</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@100..900&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Ubuntu", sans-serif;}
        body { background-color: #f8f9fa; color: #212529; line-height: 1.6; }
        .page-container { margin: auto; padding: 0 100px 100px 100px;} /* Consistent padding */

        header { display: flex; justify-content: space-between; align-items: center; padding: 20px 0; margin-bottom: 30px; border-bottom: 1px solid #e0e0e0;}
        .logo { font-size: 28px; font-weight: bold; color: #000; }
        .header-user-info { display: flex; align-items: center; gap: 10px; }
        .header-user-info img { width: 40px; height: 40px; border-radius: 50%; }
        .header-user-info span { font-size: 0.9em; margin-right: 10px; }
        .header-user-info a { color: #007bff; text-decoration: none; font-size: 0.9em; }
        .header-user-info a:hover { text-decoration: underline; }
        .login-prompt a { font-weight: bold; color: #007bff; }

        .main-content { margin-bottom: 30px; }
        .main-title { font-size: 28px; font-weight: bold; margin-bottom: 25px; color: #333; text-align: center; }
        
        .calendar-wrapper { display: flex; flex-direction: column; align-items: center; width: 100%; max-width: 700px; margin: 0 auto 30px auto; }
        .calendar-navigation-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; background-color: #fff; border: 1px solid #ddd; border-radius: 8px 8px 0 0; width: 100%; box-sizing: border-box; margin-bottom: -1px; }
        .calendar-navigation-header button { padding: 8px 15px; cursor: pointer; background-color: black; color: white; border: none; border-radius: 5px; font-size: 0.9em; transition: background-color 0.2s ease; }
        .calendar-navigation-header button:hover { background-color: #5a6268; }
        #monthYearDisplayJs { font-size: 1.5em; font-weight: 600; color: #343a40; }
        .weekday-header { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-weight: 600; color: #495057; background: #f8f9fa; padding: 8px 0; border-left: 1px solid #ddd; border-right: 1px solid #ddd; width: 100%; box-sizing: border-box; font-size: 0.85em; }
        .calendar-grid-container { display: grid; grid-template-columns: repeat(7, 1fr); grid-gap: 6px; background: white; padding: 15px; border-radius: 0 0 8px 8px; border: 1px solid #ddd; border-top: none; width: 100%; box-sizing: border-box; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        
        .day {
            aspect-ratio: 1 / 1; border-radius: 6px; background-color: #f8f9fa;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; position: relative; cursor: pointer;
            border: 1px solid #e9ecef; transition: transform 0.1s ease-out, box-shadow 0.1s ease-out;
        }
        .day.other-month { background-color: #e9ecef; opacity: 0.7; cursor: default; }
        .day:not(.other-month):hover { transform: scale(1.03); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .day.has-entry { border: 2px solid #007bff; }

        .day img.entry-image { width: 100%; height: 100%; object-fit: cover; position: absolute; top:0; left:0; z-index: 0; }
        .day img.placeholder-icon { width: 45%; height: 45%; object-fit: contain; opacity: 0.3; position: relative; z-index: 0;}
        
        .day span.date-number { /* Date number overlay */
            position: absolute; top: 3px; left: 5px; /* MOVED TO TOP-LEFT for clarity with icon bottom-right */
            font-size: 0.65em; color: #fff;
            background-color: rgba(0, 0, 0, 0.6);
            padding: 2px 4px; border-radius: 3px;
            line-height: 1; font-weight: bold;
            z-index: 2; /* Above emotion icon and main image */
        }
        .day.other-month span.date-number { color: #6c757d; background-color: rgba(0, 0, 0, 0.1); }

        .day .emotion-icon-calendar { /* Small emotion icon overlay */
            position: absolute;
            bottom: 3px;
            right: 3px;  /* POSITIONED TO BOTTOM-RIGHT */
            width: 18px; 
            height: 18px;
            border-radius: 50%;
            z-index: 1; /* Above main image/placeholder, below date number if overlap */
            background-color: rgba(255,255,255,0.3); 
            padding: 1px; 
            box-shadow: 0 0 2px rgba(0,0,0,0.2); 
        }
        
        /* Modal Styles (same as before) */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.65); backdrop-filter: blur(3px); }
        .modal-content { background-color: #fff; margin: 8% auto; padding: 25px 30px; border: none; width: 90%; max-width: 680px; border-radius: 10px; position: relative; box-shadow: 0 8px 25px rgba(0,0,0,0.15); animation: modalAppear 0.3s ease-out; }
        @keyframes modalAppear { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .modal-close { color: #888; position: absolute; right: 15px; top: 10px; font-size: 32px; font-weight: bold; cursor: pointer; transition: color 0.2s ease; }
        .modal-close:hover, .modal-close:focus { color: #333; text-decoration: none; }
        #modalDateDisplay { font-size: 1.6em; margin-bottom: 20px; color: #212529; font-weight: 600; border-bottom: 1px solid #eee; padding-bottom: 10px;}
        #modalEntriesContainer .entry-item { margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px dashed #e0e0e0; }
        #modalEntriesContainer .entry-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        #modalEntriesContainer h3 { margin-top: 0; font-size: 1.15em; color: #495057; margin-bottom: 8px; }
        #modalEntriesContainer img.modal-entry-image { max-width: 100%; height: auto; max-height: 300px; display: block; margin: 10px auto 15px; border-radius: 6px; border: 1px solid #ddd; }
        #modalEntriesContainer strong { color: #343a40; font-weight: 500; }
        #modalEntriesContainer .diary-text-content { margin-top:8px; padding:12px; background-color:#f8f9fa; border:1px solid #e9ecef; border-radius:5px; line-height:1.7; white-space: pre-wrap; font-size: 0.95em; color: #495057; }
        button.modal-add-entry-btn { display: block; width: calc(100% - 20px); margin: 0 auto 15px auto; padding: 10px 15px; background-color: #28a745; color: white; border: none; border-radius: 5px; font-size: 1em; cursor: pointer; text-align: center;}
        button.modal-add-entry-btn:hover { background-color: #218838; }
        #modalEntriesContainer .entry-emotion-details { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        #modalEntriesContainer .entry-emotion-details img { width: 30px; height: 30px; border-radius: 50%; }

        /* Bottom Navigation (same as home.php) */
        .bottom-nav { background-color: #ffffff; border-top: 1px solid #e0e0e0; display: flex; justify-content: space-around; padding: 10px 0; position: fixed; bottom: 0; left: 0; width: 100%; height: 70px; box-shadow: 0 -2px 5px rgba(0,0,0,0.05); z-index: 100; }
        .bottom-nav-item { text-align: center; flex-grow: 1; display: flex; justify-content: center; align-items: center; }
        .bottom-nav-item a { text-decoration: none; display: flex; flex-direction: column; align-items: center; color: #6c757d; }
        .bottom-nav-icon, .material-icons { font-size: 28px !important; cursor: pointer; color: #6c757d; transition: color 0.2s ease; }
        .bottom-nav-item:hover .bottom-nav-icon, .bottom-nav-item:hover .material-icons,
        .bottom-nav-item.active .bottom-nav-icon, .bottom-nav-item.active .material-icons { color: #007bff; }
        .bottom-nav-label { font-size: 0.7em; margin-top: 2px; } 

        @media (max-width: 768px) { /* Responsive adjustments */ }
        @media (max-width: 480px) { /* Further adjustments */ }
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
            <h1 class="main-title">Your Diary Calendar</h1> 
            <div class="calendar-wrapper">
                <div class="calendar-navigation-header">
                    <button id="prevMonthBtnJs">< Prev</button>
                    <div id="monthYearDisplayJs"></div>
                    <button id="nextMonthBtnJs">Next ></button>
                </div>
                <div class="weekday-header">
                    <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                </div>
                <div class="calendar-grid-container" id="calendarGridJs"></div>
            </div>
        </div> 

        <div id="entryModal" class="modal">
            <div class="modal-content">
                <span class="modal-close" id="modalCloseBtn">×</span>
                <h2 id="modalDateDisplay"></h2>
                <div id="modalEntriesContainer"></div>
            </div>
        </div>
    </div> 

    <div class="bottom-nav">
        <div class="bottom-nav-item"> <a href="home.php" title="Create Entry"> <span class="material-icons">add_circle</span> </a> </div>
        <div class="bottom-nav-item active"> <a href="calendar.php" title="View Diary"> <span class="material-icons">edit_calendar</span> </a> </div>
        <div class="bottom-nav-item"> <a href="report.php" title="Report">  <span class="material-icons">insights</span> </a> </div>
        <!-- <div class="bottom-nav-item"> <a href="settings.php" title="Settings"> <span class="material-icons">settings</span> </a> </div> -->
    </div>

    <script>
        const entriesByDate = <?php echo json_encode($entriesByDate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
        const UPLOADS_URL_PREFIX = 'uploads/'; 
        const PLACEHOLDER_ICON_URL = 'images/icon.png'; 
        const EMOTION_IMAGES_URL_PREFIX = 'images/emotions/'; 
        const emotionToFilenameMap = {
            'sad': 'sad', 'angry': 'angry', 'uneasy': 'uneasy', 'worried': 'worried',
            'sparkling': 'sparkling', 'bored': 'bored', 'surprised': 'surprised', 'joyful': 'joyful',
            'happy': 'joyful', 'content': 'joyful', 'exhausted': 'sad',
            'apathetic dismissive': 'bored', 'contemplative': 'uneasy',
            'content pleased': 'joyful', 'excited enthusiastic': 'sparkling',
            'frustrated anxious': 'angry', 'content optimistic': 'joyful',
            'peaceful content': 'joyful', 'playful energetic': 'sparkling',
            'energetic': 'sparkling'
        };

        const calendarGridJs = document.getElementById('calendarGridJs');
        const monthYearDisplayJs = document.getElementById('monthYearDisplayJs');
        const prevMonthBtnJs = document.getElementById('prevMonthBtnJs');
        const nextMonthBtnJs = document.getElementById('nextMonthBtnJs');
        const modal = document.getElementById('entryModal');
        const modalCloseBtn = document.getElementById('modalCloseBtn');
        const modalDateDisplay = document.getElementById('modalDateDisplay');
        const modalEntriesContainer = document.getElementById('modalEntriesContainer');
        let currentDate = new Date(); 

        function renderCalendar(dateToRender) {
            calendarGridJs.innerHTML = ''; 
            const year = dateToRender.getFullYear();
            const month = dateToRender.getMonth(); 
            monthYearDisplayJs.textContent = `${dateToRender.toLocaleString('default', { month: 'long' })} ${year}`;
            const firstDayOfMonth = new Date(year, month, 1);
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            let startDayOfWeek = firstDayOfMonth.getDay(); 

            for (let i = 0; i < startDayOfWeek; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.classList.add('day', 'other-month');
                calendarGridJs.appendChild(emptyCell);
            }

            for (let d = 1; d <= daysInMonth; d++) {
                const dayDiv = document.createElement('div');
                dayDiv.classList.add('day');
                const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const entriesForDay = entriesByDate[dateKey];

                if (entriesForDay && entriesForDay.length > 0) {
                    dayDiv.classList.add('has-entry');
                    const firstEntry = entriesForDay[0];
                    if (firstEntry.image_url) {
                        const img = document.createElement('img');
                        img.src = UPLOADS_URL_PREFIX + firstEntry.image_url;
                        img.alt = `Diary entry for ${dateKey}`;
                        img.classList.add('entry-image');
                        img.onerror = function() { this.remove(); addPlaceholderIcon(dayDiv); };
                        dayDiv.appendChild(img);
                    } else { addPlaceholderIcon(dayDiv); }

                    if (firstEntry.emotion) {
                        let dbEmotionText = firstEntry.emotion.toLowerCase();
                        let emotionFilenamePart = emotionToFilenameMap[dbEmotionText]; 
                        if (emotionFilenamePart) { 
                            const emotionIcon = document.createElement('img');
                            emotionIcon.src = EMOTION_IMAGES_URL_PREFIX + emotionFilenamePart + '.png';
                            emotionIcon.alt = firstEntry.emotion;
                            emotionIcon.title = firstEntry.emotion;
                            emotionIcon.classList.add('emotion-icon-calendar');
                            emotionIcon.onerror = function() { this.style.display = 'none'; console.warn(`Calendar emotion icon not found: ${this.src}`); };
                            dayDiv.appendChild(emotionIcon);
                        } else { console.warn(`No map for DB emotion: "${firstEntry.emotion}" (lc: "${dbEmotionText}").`); }
                    }
                    dayDiv.addEventListener('click', () => showEntriesForDate(dateKey, entriesForDay));
                } else {
                    addPlaceholderIcon(dayDiv);
                    dayDiv.addEventListener('click', () => { window.location.href = `home.php?date=${dateKey}`; });
                }
                const label = document.createElement("span");
                label.classList.add('date-number');
                label.textContent = d;
                dayDiv.appendChild(label);
                calendarGridJs.appendChild(dayDiv);
            }
        }

        function addPlaceholderIcon(parentDiv) {
            if (parentDiv.querySelector('.placeholder-icon') || parentDiv.querySelector('.entry-image')) { return; }
            const icon = document.createElement("img");
            icon.src = PLACEHOLDER_ICON_URL; 
            icon.alt = "No entry image";
            icon.classList.add('placeholder-icon');
            icon.onerror = function() { this.style.display='none'; console.error(`Failed to load placeholder icon: ${this.src}`); };
            parentDiv.appendChild(icon);
        }

        function showEntriesForDate(dateKey, entries) {
            const displayDate = new Date(dateKey + 'T00:00:00'); 
            modalDateDisplay.textContent = `Entries for ${displayDate.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })}`;
            modalEntriesContainer.innerHTML = ''; 

            const addEntryButton = document.createElement('button');
            addEntryButton.textContent = 'Add New Entry for this Date';
            addEntryButton.classList.add('modal-add-entry-btn'); 
            addEntryButton.onclick = () => { window.location.href = `home.php?date=${dateKey}`; };
            modalEntriesContainer.appendChild(addEntryButton);
            
            if (entries && entries.length > 0) {
                modalEntriesContainer.appendChild(document.createElement('hr')); 
                entries.forEach(entry => {
                    const entryDiv = document.createElement('div');
                    entryDiv.classList.add('entry-item');
                    let entryHtml = `<h3>Entry at ${new Date(entry.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true })}</h3>`;
                    
                    if (entry.emotion) {
                        let dbEmotionTextModal = entry.emotion.toLowerCase();
                        let emotionFilenamePartModal = emotionToFilenameMap[dbEmotionTextModal];
                        if (emotionFilenamePartModal) { 
                            entryHtml += `<div class="entry-emotion-details">`;
                            entryHtml += `<img src="${EMOTION_IMAGES_URL_PREFIX}${emotionFilenamePartModal}.png" alt="${escapeHtml(entry.emotion)}" title="${escapeHtml(entry.emotion)}">`;
                            entryHtml += `<p><strong>Mood:</strong> ${escapeHtml(entry.emotion)}</p>`;
                            entryHtml += `</div>`;
                        } else {
                             entryHtml += `<p><strong>Mood:</strong> ${escapeHtml(entry.emotion)} (No icon mapped)</p>`;
                             console.warn(`No map in MODAL for DB emotion: "${entry.emotion}" (lc: "${dbEmotionTextModal}")`);
                        }
                    } else { entryHtml += `<p><strong>Mood:</strong> N/A</p>`; }
                                        
                    if (entry.image_url) { entryHtml += `<img src="${UPLOADS_URL_PREFIX}${entry.image_url}" alt="Diary Image" class="modal-entry-image"><br>`; }
                    entryHtml += `<p><strong>Diary Entry:</strong></p><div class="diary-text-content">${escapeHtml(entry.gemini_diary_text) || 'No text generated.'}</div>`;
                    entryDiv.innerHTML = entryHtml;
                    modalEntriesContainer.appendChild(entryDiv);
                });
            } else {
                const noEntriesP = document.createElement('p');
                noEntriesP.textContent = 'No entries for this date yet.';
                noEntriesP.style.textAlign = 'center'; noEntriesP.style.marginTop = '10px';
                modalEntriesContainer.appendChild(noEntriesP);
            }
            modal.style.display = 'block';
        }

        // THIS FUNCTION IS NOW CORRECT
        function escapeHtml(unsafe) {
            if (unsafe === null || typeof unsafe === 'undefined') {
                return '';
            }
            return unsafe.toString()
                 .replace(/&/g, "&")
                 .replace(/</g, "<")
                 .replace(/>/g, ">")
                 .replace(/"/g, "")
                 .replace(/'/g, "'");
        }

        prevMonthBtnJs.addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(currentDate); });
        nextMonthBtnJs.addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(currentDate); });
        modalCloseBtn.addEventListener('click', () => { modal.style.display = 'none'; });
        window.addEventListener('click', (event) => { if (event.target === modal) { modal.style.display = 'none'; } });
        renderCalendar(currentDate);
    </script>
</body>
</html>