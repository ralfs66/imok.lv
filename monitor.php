<?php
// Load environment variables manually
function loadEnv() {
    $env = file_get_contents(__DIR__ . '/.env');
    $lines = explode("\n", $env);
    foreach($lines as $line) {
        if(!empty($line) && strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}
loadEnv();

// Database connection with error handling
try {
    $db = new PDO(
        "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    exit;
}

// Check offline devices (with notification cooldown of 1 hour)
try {
    $sql = "SELECT * FROM devices 
            WHERE email_validated = 1 
            AND email_notifications = 1 
            AND last_ping < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            AND (last_notification IS NULL OR last_notification < DATE_SUB(NOW(), INTERVAL 1 HOUR))";

    $stmt = $db->query($sql);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($devices as $device) {
        $to = $device['email'];
        $deviceIdentifier = $device['device_name'] ? "{$device['device_name']} ({$device['hash']})" : $device['hash'];
        $subject = "🔴 Device Offline Alert - {$deviceIdentifier}";
        
        // Calculate time since last ping
        $lastPing = new DateTime($device['last_ping']);
        $now = new DateTime();
        $interval = $now->diff($lastPing);
        $offlineFor = $interval->format('%h hours, %i minutes');
        
        $message = "
        <h2>🔴 Device Offline Alert</h2>
        <p>Your device" . ($device['device_name'] ? " <strong>{$device['device_name']}</strong>" : "") . " has been offline for {$offlineFor}.</p>
        <hr>
        <p><strong>Device Name:</strong> " . ($device['device_name'] ?: 'Not set') . "</p>
        <p><strong>Device ID:</strong> {$device['hash']}</p>
        <p><strong>Last Ping:</strong> {$device['last_ping']}</p>
        <p><strong>Last IP:</strong> {$device['last_ip']}</p>
        <p><strong>Ping Count:</strong> {$device['ping_count']}</p>
        <hr>
        <p>Check your device status at: <a href='https://imok.lv'>https://imok.lv</a></p>
        <p style='color: #666; font-size: 12px;'>To stop receiving these alerts, disable notifications in your device settings.</p>
        ";
        
        $headers = [
            'From: ImOK Monitor <' . $_ENV['SMTP_USER'] . '>',
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: PHP/' . phpversion(),
            'MIME-Version: 1.0'
        ];
        
        if(mail($to, $subject, $message, implode("\r\n", $headers))) {
            $update = $db->prepare("UPDATE devices SET last_notification = NOW() WHERE hash = ?");
            $update->execute([$device['hash']]);
            error_log("Notification sent to {$to} for device {$device['hash']}");
        } else {
            error_log("Failed to send notification to {$to} for device {$device['hash']}");
        }
    }
} catch(Exception $e) {
    error_log("Monitor script error: " . $e->getMessage());
} 