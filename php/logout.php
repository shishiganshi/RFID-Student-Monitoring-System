<?php
session_start();
session_destroy();
setcookie("cmu_user", "", time() - 3600, "/");
header("Location: ../html/login.html");
exit;
?>