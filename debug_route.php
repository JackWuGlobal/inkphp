<?php
header('Content-Type: text/plain');
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'N/A') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A') . "\n";
echo "PATH_INFO: " . ($_SERVER['PATH_INFO'] ?? 'N/A') . "\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
