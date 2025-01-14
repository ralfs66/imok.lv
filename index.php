<?php
session_start();
header('Content-Type: application/json'); // Default for API endpoints

// Add CORS headers if needed
$allowedOrigins = [
    'http://localhost:3000',
    'https://imok.lv'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Methods: GET, POST, DELETE");
    header("Access-Control-Allow-Headers: Content-Type");
}

// Sanitize all incoming paths
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = filter_var($path, FILTER_SANITIZE_URL);

if (!$path) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid request path']);
    exit;
}

// Modify the root path handler
if ($path === '/') {
    header('Content-Type: text/html');
    include 'app.php';  // Changed from app.html to app.php
    exit;
}

// Load .env
function loadEnv() {
    $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}
loadEnv();

// Database connection
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

// Handle API endpoints
$path = $_SERVER['REQUEST_URI'];
if (strpos($path, '/generate') === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    
    if (!$email) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Invalid email address']);
        exit;
    }
    
    $hash = bin2hex(random_bytes(32));
    $validationToken = bin2hex(random_bytes(32));
    
    try {
        $stmt = $db->prepare("
            INSERT INTO devices 
            (hash, email, email_validated, email_notifications, ping_count, validation_token, created) 
            VALUES (?, ?, 0, 1, 0, ?, NOW())
        ");
        $stmt->execute([$hash, $email, $validationToken]);
        
        // Send verification email with better formatting
        $verifyUrl = "https://imok.lv/verify/" . $validationToken;
        $subject = "Verify your email for ImOK Monitor";
        $message = "
        <!DOCTYPE html>
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #2563EB;'>Verify Your Email</h2>
            <p>Please click the link below to verify your email address:</p>
            <p><a href='{$verifyUrl}' style='background: #2563EB; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Verify Email</a></p>
            <p style='margin-top: 20px;'><strong>Device ID:</strong> {$hash}</p>
            <p style='color: #666; font-size: 14px;'>This link will expire in 24 hours.</p>
            <hr style='border: 1px solid #eee; margin: 20px 0;'>
            <p style='color: #666; font-size: 12px;'>If the button doesn't work, copy and paste this URL into your browser:</p>
            <p style='font-size: 12px; word-break: break-all;'>{$verifyUrl}</p>
        </body>
        </html>
        ";
        
        $headers = [
            'From: ImOK Monitor <' . $_ENV['SMTP_USER'] . '>',
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: PHP/' . phpversion(),
            'MIME-Version: 1.0'
        ];
        
        if (!mail($email, $subject, $message, implode("\r\n", $headers))) {
            error_log("Failed to send verification email to: " . $email);
            throw new Exception('Failed to send verification email');
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'deviceId' => $hash,
            'needsVerification' => true
        ]);
    } catch (Exception $e) {
        error_log("Generate device error: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Failed to generate device']);
    }
    exit;
}

if (preg_match('#^/status/([a-f0-9]+)$#i', $path, $matches)) {
    try {
        $hash = trim($matches[1], '/');
        
        // First check if device exists and email is verified
        $stmt = $db->prepare("SELECT 
            *,
            CASE 
                WHEN last_ping >= NOW() - INTERVAL 5 MINUTE THEN 'Online'
                WHEN last_ping IS NULL THEN 'Never Connected'
                ELSE 'Offline'
            END as status
            FROM devices 
            WHERE hash = ?");
            
        $stmt->execute([$hash]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Device not found']);
            exit;
        }
        
        // Force numeric value for email_validated
        $device['email_validated'] = (int)$device['email_validated'];
        
        header('Content-Type: application/json');
        echo json_encode($device);
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Server error']);
    }
    exit;
}

if (strpos($path, '/u/') === 0) {
    try {
        $hash = trim(substr($path, 3), '/');
        
        // First check when was the last ping
        $stmt = $db->prepare("SELECT last_ping FROM devices WHERE hash = ?");
        $stmt->execute([$hash]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Device not found']);
            exit;
        }
        
        // If there's a last_ping, check if it's been at least 59 seconds
        if ($device['last_ping']) {
            $lastPing = strtotime($device['last_ping']);
            $timeSinceLastPing = time() - $lastPing;
            
            if ($timeSinceLastPing < 59) {
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: ' . (59 - $timeSinceLastPing));
                echo json_encode([
                    'error' => 'Rate limit exceeded',
                    'wait' => 59 - $timeSinceLastPing
                ]);
                exit;
            }
        }
        
        // Update the device ping info
        $stmt = $db->prepare("
            UPDATE devices 
            SET last_ping = NOW(), 
                last_ip = ?, 
                ping_count = ping_count + 1
            WHERE hash = ?
        ");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $hash]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        error_log("Error in ping endpoint: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Server error']);
    }
    exit;
}

// Handle notifications toggle
if (strpos($path, '/notifications/') === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hash = trim(substr($path, 14), '/');
        $data = json_decode(file_get_contents('php://input'), true);
        
        // First get current state
        $stmt = $db->prepare("SELECT email_notifications FROM devices WHERE hash = ?");
        $stmt->execute([$hash]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Only update if state actually changes
        if ($device && $device['email_notifications'] != $data['enabled']) {
            $stmt = $db->prepare("UPDATE devices SET email_notifications = ? WHERE hash = ?");
            $result = $stmt->execute([$data['enabled'] ? 1 : 0, $hash]);
            
            // Removed the toggle state change log
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'enabled' => $data['enabled']]);
        
    } catch (Exception $e) {
        error_log("Toggle error: " . $e->getMessage()); // Only log actual errors
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Server error']);
    }
    exit;
}

// Handle device deletion
if (strpos($path, '/device/') === 0 && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $hash = trim(substr($path, 8), '/');
        
        // First check if device exists
        $stmt = $db->prepare("SELECT hash FROM devices WHERE hash = ?");
        $stmt->execute([$hash]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Device not found']);
            exit;
        }
        
        $stmt = $db->prepare("DELETE FROM devices WHERE hash = ?");
        $result = $stmt->execute([$hash]);
        
        if ($result && $stmt->rowCount() > 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => 'Failed to delete device']);
        }
    } catch (Exception $e) {
        error_log("Error deleting device: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Server error']);
    }
    exit;
}

// Add this to your existing endpoints in index.php
if (strpos($path, '/verify/') === 0) {
    try {
        $token = trim(substr($path, 8), '/');
        
        $stmt = $db->prepare("SELECT hash FROM devices WHERE validation_token = ?");
        $stmt->execute([$token]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($device) {
            $stmt = $db->prepare("UPDATE devices SET email_validated = 1, validation_token = NULL WHERE validation_token = ?");
            $stmt->execute([$token]);
            
            header('Location: /?device=' . $device['hash'] . '&verified=1&status=verified');
        } else {
            header('Location: /?error=invalid_or_expired');
        }
        exit;
    } catch (Exception $e) {
        error_log("Verification error: " . $e->getMessage());
        header('Location: /?error=verification_failed');
        exit;
    }
}

if (strpos($path, '/name/') === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hash = trim(substr($path, 6), '/');
        
        // First check if email is validated
        $stmt = $db->prepare("SELECT email_validated FROM devices WHERE hash = ?");
        $stmt->execute([$hash]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device || $device['email_validated'] != 1) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Email not verified']);
            exit;
        }
        
        // If validated, update name
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $db->prepare("UPDATE devices SET device_name = ? WHERE hash = ?");
        $stmt->execute([$data['name'], $hash]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Server error']);
    }
    exit;
}

// Create new device
if ($path === '/device' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
        
        if (!$email) {
            throw new Exception('Invalid email');
        }
        
        // First check if this email is already validated in other devices
        $stmt = $db->prepare("SELECT email_validated FROM devices WHERE email = ? AND email_validated = 1 LIMIT 1");
        $stmt->execute([$email]);
        $isEmailValidated = $stmt->fetch(PDO::FETCH_COLUMN);
        
        // Generate tokens
        $hash = bin2hex(random_bytes(32));
        $validationToken = $isEmailValidated ? null : bin2hex(random_bytes(32));
        
        // If email is already validated elsewhere, create device with notifications ON
        // If not, create with notifications OFF and pending validation
        $stmt = $db->prepare("
            INSERT INTO devices 
            (hash, email, validation_token, email_validated, email_notifications) 
            VALUES 
            (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $hash,
            $email,
            $validationToken,
            $isEmailValidated ? 1 : 0,
            $isEmailValidated ? 1 : 0
        ]);
        
        // Only send validation email if email isn't already validated
        if (!$isEmailValidated) {
            $validationUrl = "https://imok.lv/validate/{$validationToken}";
            // ... send validation email ...
        }
        
        header('Content-Type: application/json');
        echo json_encode(['hash' => $hash]);
        
    } catch (Exception $e) {
        error_log("Device creation error: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Email validation endpoint
if (strpos($path, '/validate/') === 0) {
    try {
        $token = trim(substr($path, 9), '/');
        
        // Only validate email, don't touch notifications
        $stmt = $db->prepare("UPDATE devices SET email_validated = 1, validation_token = NULL WHERE validation_token = ?");
        $result = $stmt->execute([$token]);
        
        if ($stmt->rowCount() > 0) {
            header('Location: /?verified=1');
        } else {
            header('Location: /?error=invalid_token');
        }
        
    } catch (Exception $e) {
        error_log("Validation error: " . $e->getMessage());
        header('Location: /?error=server_error');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ImOK - Simple Device Monitoring</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="container mx-auto px-4 py-8 max-w-3xl">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-800 mb-2">ImOK</h1>
            <h2 class="text-xl text-gray-600">Simple Device Status Monitoring</h2>
        </div>

        <!-- Main Functionality First -->
        <div class="space-y-6">
            <!-- Generate Device Form -->
            <div class="bg-white rounded-xl shadow-lg p-6 transform transition-all duration-200 hover:shadow-xl">
                <div class="space-y-4">
                    <label class="block">
                        <span class="text-gray-700 text-sm font-medium mb-2">Email Address</span>
                        <div class="mt-1 relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                                </svg>
                            </div>
                            <input type="email" 
                                   id="ownerEmail" 
                                   class="block w-full pl-10 pr-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                   placeholder="Enter your email address">
                        </div>
                    </label>
                    <button onclick="generateDevice()" 
                            class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-3 px-4 rounded-lg transition-all duration-200 transform hover:translate-y-[-1px] hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50">
                        Generate Device ID
                    </button>
                </div>
            </div>

            <!-- Check Status Form -->
            <div class="mb-4">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="mb-4">
                        <label class="block mb-2">Device ID</label>
                        <input type="text" 
                               id="deviceId" 
                               class="w-full p-2 border rounded"
                               placeholder="Enter your Device ID">
                    </div>

                    <button onclick="checkStatus()" 
                            class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600">
                        Check Status
                    </button>
                </div>
            </div>
        </div>

        <!-- Result Section -->
        <div id="result" class="hidden bg-white rounded-lg shadow p-6 mt-4"></div>

        <!-- Move How It Works and other sections below -->
        <div class="mt-8">
            <!-- How It Works Section -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">How It Works</h3>
                <div class="space-y-4 text-gray-600">
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0 w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center">1</div>
                        <p>Generate a unique device ID by entering your email address. This ID will be used to track your device's status.</p>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0 w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center">2</div>
                        <p>Set up your device (computer, server, IoT device) to periodically ping our service using the provided URL.</p>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0 w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center">3</div>
                        <p>Validate your email to receive notifications if your device stops responding for more than 5 minutes.</p>
                    </div>
                </div>
            </div>

            <!-- Use Cases Section -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">Use Cases</h3>
                <div class="grid md:grid-cols-2 gap-4">
                    <div class="p-4 border rounded-lg">
                        <h4 class="font-semibold text-lg mb-2">Server Monitoring</h4>
                        <p class="text-gray-600">Monitor your servers' uptime and get instant notifications if they go down.</p>
                    </div>
                    <div class="p-4 border rounded-lg">
                        <h4 class="font-semibold text-lg mb-2">IoT Devices</h4>
                        <p class="text-gray-600">Keep track of your IoT devices and ensure they're functioning properly.</p>
                    </div>
                    <div class="p-4 border rounded-lg">
                        <h4 class="font-semibold text-lg mb-2">Cron Jobs</h4>
                        <p class="text-gray-600">Monitor scheduled tasks and get notified if they fail to run.</p>
                    </div>
                    <div class="p-4 border rounded-lg">
                        <h4 class="font-semibold text-lg mb-2">Network Monitoring</h4>
                        <p class="text-gray-600">Track network connectivity and detect outages quickly.</p>
                    </div>
                </div>
            </div>

            <!-- Setup Examples Section -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">Setup Examples</h3>
                <div class="space-y-6">
                    <div>
                        <h4 class="font-semibold text-lg mb-2">Windows PowerShell</h4>
                        <p class="text-gray-600 mb-2">1. Create a new file named <code class="bg-gray-100 px-2 py-1">monitor.ps1</code> with this content:</p>
                        <code class="bg-gray-100 p-3 rounded block text-sm whitespace-pre">while ($true) {
    try {
        Invoke-RestMethod https://imok.lv/u/your-device-id
        Write-Host "Ping sent successfully at $(Get-Date)"
    } catch {
        Write-Host "Error sending ping: $_"
    }
    Start-Sleep -Seconds 60
}</code>
                        <p class="text-gray-600 mt-2">2. Run it in PowerShell:</p>
                        <code class="bg-gray-100 p-3 rounded block text-sm">powershell.exe -ExecutionPolicy Bypass -File monitor.ps1</code>
                    </div>

                    <div>
                        <h4 class="font-semibold text-lg mb-2">Linux/Mac Bash Script</h4>
                        <p class="text-gray-600 mb-2">1. Create a new file named <code class="bg-gray-100 px-2 py-1">monitor.sh</code>:</p>
                        <code class="bg-gray-100 p-3 rounded block text-sm">#!/bin/bash
while true; do
    curl -s https://imok.lv/u/your-device-id
    echo "Ping sent at $(date)"
    sleep 60
done</code>
                        <p class="text-gray-600 mt-2">2. Make it executable and run:</p>
                        <code class="bg-gray-100 p-3 rounded block text-sm">chmod +x monitor.sh
./monitor.sh</code>
                    </div>

                    <div>
                        <h4 class="font-semibold text-lg mb-2">Docker Container</h4>
                        <p class="text-gray-600 mb-2">Add to your Dockerfile:</p>
                        <code class="bg-gray-100 p-3 rounded block text-sm"># Add monitoring script
COPY monitor.sh /monitor.sh
RUN chmod +x /monitor.sh

# Run your application and the monitor
CMD ["/bin/bash", "-c", "/monitor.sh & your-main-application"]</code>
                        <p class="text-gray-600 mt-2">Or use Docker Compose:</p>
                        <code class="bg-gray-100 p-3 rounded block text-sm">services:
  your-service:
    image: your-image
    healthcheck:
      test: ["CMD", "curl", "-f", "https://imok.lv/u/your-device-id"]
      interval: 60s
      timeout: 3s
      retries: 1</code>
                    </div>

                    <div class="bg-blue-50 p-4 rounded-lg">
                        <h4 class="font-semibold text-lg mb-2">Important Notes:</h4>
                        <ul class="list-disc list-inside text-gray-600 space-y-2">
                            <li>Replace <code class="bg-gray-100 px-2 py-1">your-device-id</code> with the ID generated above</li>
                            <li>Scripts will run continuously until stopped</li>
                            <li>Consider running scripts as a service for production use</li>
                            <li>Ensure your device has a stable internet connection</li>
                            <li>The 60-second interval is recommended to avoid missing the 5-minute notification threshold</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- FAQ Section -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">Frequently Asked Questions</h3>
                <div class="space-y-4">
                    <div>
                        <h4 class="font-semibold text-lg mb-2">How often should my device ping?</h4>
                        <p class="text-gray-600">We recommend a 60-second interval. The system will send you an email if no ping is received for 5 minutes.</p>
                    </div>
                    <div>
                        <h4 class="font-semibold text-lg mb-2">Is there a rate limit?</h4>
                        <p class="text-gray-600">Yes, pings are limited to once per minute per device to prevent system overload.</p>
                    </div>
                    <div>
                        <h4 class="font-semibold text-lg mb-2">How do notifications work?</h4>
                        <p class="text-gray-600">Once your email is validated, you'll receive email alerts when your device goes offline for more than 5 minutes.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center text-gray-500 text-sm mt-8">
            <p>ImOK - Simple Device Status Monitoring</p>
            <p class="mt-2">Need help? Contact <a href="mailto:ralfs@artificial.lv" class="text-blue-500 hover:text-blue-600">ralfs@artificial.lv</a></p>
        </div>

        <!-- Loading Spinner -->
        <div id="loading" class="hidden fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm flex items-center justify-center z-50">
            <div class="bg-white p-5 rounded-lg shadow-xl flex items-center space-x-4">
                <div class="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-blue-500"></div>
                <span class="text-gray-700">Processing...</span>
            </div>
        </div>
    </div>

    <script>
        const loading = document.getElementById('loading');
        const result = document.getElementById('result');
        const resultContent = document.getElementById('resultContent');

        function showLoading() {
            loading.classList.remove('hidden');
            // Disable all buttons while loading
            document.querySelectorAll('button').forEach(button => {
                button.disabled = true;
            });
        }

        function hideLoading() {
            loading.classList.add('hidden');
            // Re-enable all buttons
            document.querySelectorAll('button').forEach(button => {
                button.disabled = false;
            });
        }

        function showResult(html, isError = false) {
            result.classList.remove('hidden');
            resultContent.innerHTML = html;
            
            if (isError) {
                resultContent.classList.add('text-red-500');
                // Scroll to error message
                result.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                resultContent.classList.remove('text-red-500');
            }
        }

        async function copyToClipboard(text) {
            try {
                await navigator.clipboard.writeText(text);
                const button = event.currentTarget;
                button.innerHTML = '<i class="fas fa-check text-green-500"></i>';
                setTimeout(() => {
                    button.innerHTML = '<i class="fas fa-copy"></i>';
                }, 1000);
            } catch (err) {
                console.error('Failed to copy:', err);
            }
        }

        function getPowershellScript(deviceId) {
            return `while ($true) {
    try {
        $response = Invoke-RestMethod https://imok.lv/u/${deviceId}
        Write-Host "Ping sent successfully at $(Get-Date)"
        if ($response.error) {
            Write-Host "Error: $($response.error)"
        }
    } catch {
        Write-Host "Error sending ping: $_"
    }
    Start-Sleep -Seconds 60
}`;
        }

        function getBashScript(deviceId) {
            return `#!/bin/bash
while true; do
    response=$(curl -s https://imok.lv/u/${deviceId})
    echo "Ping sent at $(date)"
    if [[ $response == *"error"* ]]; then
        echo "Error: $response"
    fi
    sleep 60
done`;
        }

        function showDeviceGenerationResult(data) {
            const deviceUrl = `https://imok.lv/u/${data.deviceId}`;
            showResult(`
                <div class="space-y-4">
                    <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4">
                        <p class="font-bold">Verification Required</p>
                        <p>Please check your email to verify your address before notifications will be sent.</p>
                    </div>
                    
                    <div class="font-medium">Your Device URL:</div>
                    <div class="flex items-center space-x-2">
                        <code class="bg-gray-100 p-2 rounded flex-1 break-all">${deviceUrl}</code>
                        <button onclick="copyToClipboard('${deviceUrl}')" 
                                class="p-2 text-blue-500 hover:text-blue-600 flex-shrink-0">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    
                    <div class="text-sm text-gray-600 mt-4">
                        <p class="font-medium mb-2">PowerShell example:</p>
                        <code class="bg-gray-100 p-2 rounded block whitespace-pre-wrap">${getPowershellScript(data.deviceId)}</code>
                        <button onclick="copyToClipboard(getPowershellScript('${data.deviceId}'))" 
                                class="mt-2 text-blue-500 hover:text-blue-600">
                            <i class="fas fa-copy"></i> Copy PowerShell script
                        </button>
                    </div>
                    
                    <div class="text-sm text-gray-600 mt-4">
                        <p class="font-medium mb-2">Bash example:</p>
                        <code class="bg-gray-100 p-2 rounded block whitespace-pre-wrap">${getBashScript(data.deviceId)}</code>
                        <button onclick="copyToClipboard(getBashScript('${data.deviceId}'))" 
                                class="mt-2 text-blue-500 hover:text-blue-600">
                            <i class="fas fa-copy"></i> Copy bash script
                        </button>
                    </div>
                </div>
            `);
        }

        async function generateDevice() {
            const email = document.getElementById('ownerEmail').value.trim();
            if (!email) {
                showResult('Please enter your email address', true);
                return;
            }
            
            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showResult('Please enter a valid email address', true);
                return;
            }

            showLoading();
            try {
                const response = await fetch('generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                if (data.error) {
                    throw new Error(data.error);
                }
                
                showDeviceGenerationResult(data);
                
            } catch (error) {
                showResult(`<div class="text-red-500">Failed to generate device: ${error.message}</div>`, true);
            } finally {
                hideLoading();
            }
        }

        async function checkStatus() {
            const deviceId = document.getElementById('deviceId').value.trim();
            
            if (!deviceId) {
                alert('Please enter a Device ID');
                return;
            }

            try {
                const button = document.querySelector('button');
                button.disabled = true;
                button.textContent = 'Checking...';
                
                const response = await fetch(`status/${deviceId}`);
                const device = await response.json();
                
                if (response.ok) {
                    const resultDiv = document.getElementById('result');
                    resultDiv.classList.remove('hidden');
                    resultDiv.innerHTML = getDeviceStatusHTML(device);
                    
                    // Remove this part since we're not using deviceNameContainer anymore
                    // if (device.email_validated === 1) {
                    //     document.getElementById('deviceNameContainer').classList.remove('hidden');
                    // }
                } else {
                    throw new Error(device.error || 'Failed to get status');
                }
                
            } catch (error) {
                alert(error.message);
            } finally {
                const button = document.querySelector('button');
                button.disabled = false;
                button.textContent = 'Check Status';
            }
        }

        async function toggleNotifications(hash) {
            const checkbox = event.target;
            // Disable checkbox while processing
            checkbox.disabled = true;
            
            try {
                const response = await fetch(`notifications/${hash}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ enabled: checkbox.checked })
                });
                
                if (!response.ok) {
                    // If failed, revert checkbox state
                    checkbox.checked = !checkbox.checked;
                }
            } catch (e) {
                // If error, revert checkbox state
                checkbox.checked = !checkbox.checked;
                console.error('Toggle failed:', e);
            } finally {
                // Re-enable checkbox
                checkbox.disabled = false;
            }
        }

        async function deleteDevice(hash) {
            if (!confirm('Are you sure you want to delete this device? This action cannot be undone.')) {
                return;
            }

            showLoading();
            try {
                const response = await fetch(`device/${hash}`, {
                    method: 'DELETE'
                });

                if (!response.ok) {
                    throw new Error('Failed to delete device');
                }

                document.getElementById('deviceId').value = '';
                result.classList.add('hidden');
                showResult('<div class="text-green-500">Device deleted successfully</div>');
                
            } catch (error) {
                showResult('<div class="text-red-500">Failed to delete device</div>', true);
            } finally {
                hideLoading();
            }
        }

        function getDeviceStatusHTML(device) {
            // Check URL parameter for verification
            const urlParams = new URLSearchParams(window.location.search);
            const justVerified = urlParams.get('verified') === '1';
            
            // If just verified, override the email validation status
            const isVerified = justVerified || device.email_validated === 1;
            
            return `
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="font-medium">Status:</div>
                    <div class="text-right">
                        <span class="px-3 py-1 rounded-full ${device.status === 'Online' ? 'bg-green-500' : 'bg-red-500'} text-white">
                            ${device.status}
                        </span>
                    </div>
                    
                    <div class="font-medium">Email Status:</div>
                    <div class="text-right">
                        <span class="px-3 py-1 rounded-full ${isVerified ? 'bg-green-500' : 'bg-yellow-500'} text-white">
                            ${isVerified ? 'Verified' : 'Not Verified'}
                        </span>
                    </div>
                    
                    <div class="font-medium">Last Seen:</div>
                    <div class="text-right">${device.last_ping ? device.last_ping : 'Never'}</div>
                    
                    <div class="font-medium">Created:</div>
                    <div class="text-right">${device.created || 'N/A'}</div>
                    
                    <div class="font-medium">Email:</div>
                    <div class="text-right">${device.email || 'N/A'}</div>
                    
                    <div class="font-medium">Last IP:</div>
                    <div class="text-right">${device.last_ip || 'N/A'}</div>
                    
                    <div class="font-medium">Ping Count:</div>
                    <div class="text-right">${device.ping_count || '0'}</div>
                    
                    <div class="font-medium">URL:</div>
                    <div class="text-right flex items-center justify-end gap-2">
                        <div class="bg-gray-100 px-2 py-1 rounded text-sm font-mono flex-shrink overflow-hidden">
                            <span class="opacity-50">https://imok.lv/u/</span><span>${device.hash}</span>
                        </div>
                        <button onclick="copyToClipboard('https://imok.lv/u/${device.hash}')" 
                                class="text-blue-500 hover:text-blue-600 flex-shrink-0">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    
                    <div class="font-medium">Email Notifications:</div>
                    <div class="text-right">
                        <div class="relative inline-block w-12 align-middle select-none">
                            <input type="checkbox" 
                                   class="toggle-checkbox absolute block w-6 h-6 rounded-full bg-white border-4 appearance-none cursor-pointer transition-transform duration-200 ease-in-out"
                                   style="top: 2px; left: 2px;"
                                   ${device.email_notifications === 1 ? 'checked' : ''}
                                   ${Number(device.email_validated) === 1 ? '' : 'disabled'}
                                   onchange="toggleNotifications('${device.hash}')"
                                   data-device="${device.hash}">
                            <label class="toggle-label block overflow-hidden h-8 rounded-full bg-gray-300 cursor-pointer"></label>
                        </div>
                        ${Number(device.email_validated) === 1 ? '' : '<span class="text-xs text-yellow-600 ml-2">Verify email first</span>'}
                    </div>
                </div>
                
                <div class="mt-6">
                    <button onclick="deleteDevice('${device.hash}')" 
                            class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600 transition-colors">
                        Delete Device
                    </button>
                </div>
            </div>`;
        }

        async function updateDeviceName(hash, name) {
            const input = event.target;
            const originalValue = input.value;
            
            try {
                const response = await fetch(`name/${hash}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: name.trim() })
                });
                
                if (!response.ok) {
                    input.value = originalValue;
                    throw new Error('Failed to update name');
                }
            } catch (e) {
                console.error('Name update failed:', e);
                input.value = originalValue;
            }
        }

        // Handle URL parameters on page load
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            const deviceHash = urlParams.get('device');
            const verified = urlParams.get('verified');
            const error = urlParams.get('error');
            
            if (error) {
                showResult(`<div class="text-red-500">
                    ${error === 'invalid_or_expired' ? 'Verification link is invalid or has expired.' : 'Verification failed.'}
                </div>`, true);
            } else if (verified === '1') {
                showResult(`<div class="text-green-500">Email verified successfully!</div>`);
            }
            
            if (deviceHash) {
                document.getElementById('deviceId').value = deviceHash;
                checkStatus();
            }
        }
    </script>

    <style>
        .toggle-checkbox:checked {
            transform: translateX(100%);
            border-color: #10B981;
        }

        .toggle-checkbox:checked + .toggle-label {
            background-color: #10B981;
        }

        .toggle-label {
            transition: background-color 0.2s ease-in-out;
        }
    </style>
</body>
</html> 