<?php
// Check timezone set by date_default_timezone_set
echo "Default Timezone Setting: " . date_default_timezone_get() . "<br>";

// Show current time formatted by PHP
echo "Current PHP Time: " . date('Y-m-d H:i:s') . "<br>";

// Display all PHP info, which includes the loaded configuration file path and timezone settings
phpinfo();
?>