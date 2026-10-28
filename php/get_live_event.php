<?php
// Path: portal/php/get_live_event.php
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

$liveEventFile = __DIR__ . '/../data/live_event.json';
// --- [IMPORTANT] Set to 20 seconds ---
$max_age_seconds = 20; 

if (file_exists($liveEventFile)) {
    $file_mod_time = filemtime($liveEventFile);
    if ((time() - $file_mod_time) < $max_age_seconds) {
        $content = @file_get_contents($liveEventFile);
        echo $content ? $content : json_encode(null);
    } else {
        echo json_encode(null); // Event is too old
    }
} else {
    echo json_encode(null); // No event file exists
}
exit;
?>