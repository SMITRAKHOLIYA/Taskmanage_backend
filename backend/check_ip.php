<?php
// Get Public IP
$ip = file_get_contents('https://api.ipify.org');
echo "Server Public IP: " . $ip . "\n";

// Test Connectivity to Google (to verify general internet access)
$google = @fsockopen('www.google.com', 80, $errno, $errstr, 2);
if ($google) {
    echo "Internet Connection: OK\n";
    fclose($google);
} else {
    echo "Internet Connection: FAILED ($errstr)\n";
}

// Test Connectivity to DB Host (Port 3306)
$host = 'sql108.iceiy.com';
$port = 3306;
$connection = @fsockopen($host, $port, $errno, $errstr, 5);

if (is_resource($connection)) {
    echo "Connection to $host:$port: SUCCESS\n";
    fclose($connection);
} else {
    echo "Connection to $host:$port: FAILED ($errstr)\n";
    echo "Reason: likely blocked by firewall or strict IP allowlisting.\n";
}
?>
