<?php

require_once 'sessie_start.php';

session_unset();

session_destroy();

header("Location: login.php");

exit;
