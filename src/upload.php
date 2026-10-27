<?php
/**
 * Backend Image Handler, Sightengine AI Detector Inference & MySQL Logger
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

session_start();

/**
 * Retrieve configuration value from environment or fallback .env file
 */
function get_env_var(string $key, string $default = ''): string {
    // 1. Check getenv
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }

    // 2. Check $_ENV and $_SERVER
    if (!empty($_ENV[$key])) {
        return $_ENV[$key];
    }
    if (!empty($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    // 3. Fallback: Parse .env file if available
    static $envCache = null;
    if ($envCache === null) {
        $envCache = [];
        $candidates = [
            __DIR__ . '/../.env',
            __DIR__ . '/.env',
            '/var/www/.env',
            '/var/www/html/.env'
        ];
        foreach ($candidates as $filePath) {
            if (file_exists($filePath) && is_readable($filePath)) {
                $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (str_contains($line, '=')) {
                        list($k, $v) = explode('=', $line, 2);
                        $k = trim($k);
                        $v = trim($v);
                        $v = trim($v, "\"'");
                        $envCache[$k] = $v;
                    }
                }
                break;
            }
        }
    }

    if (isset($envCache[$key]) && $envCache[$key] !== '') {
        return $envCache[$key];
    }

    return $default;
}

/**
 * Establish a PDO database connection to the local MySQL server
 */
function get_db_connection(): PDO {
    $host = get_env_var('DB_HOST', '127.0.0.1');
    $port = get_env_var('DB_PORT', '3306');
    $db   = get_env_var('DB_NAME', 'aidb');
    $user = get_env_var('DB_USER', 'root');
    $pass = get_env_var('DB_PASS', 'secret_password');

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ];

    return new PDO($dsn, $user, $pass, $options);
}

/**
 * Helper to determine if the request is an AJAX/JSON call
 */
function is_ajax_request(): bool {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        return true;
    }
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        return true;
    }
    return false;
}

/**
 * Send JSON response and exit
 */
function send_json_response(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Handle redirect or error response
 */
function handle_error(string $errorMessage, int $statusCode = 400): void {
    if (is_ajax_request()) {
        send_json_response([
            'success' => false,
            'error'   => $errorMessage
        ], $statusCode);
    } else {
        $_SESSION['upload_error'] = $errorMessage;
        header('Location: index.php');
        exit;
    }
}

// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    handle_error('Invalid request method. Please submit an image using POST.', 405);
}

// Verify that an image file was uploaded
if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    handle_error('No image file was received.', 400);
}

$fileError = $_FILES['image']['error'];
if ($fileError !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected for upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
    ];
    $msg = $errorMessages[$fileError] ?? 'Unknown file upload error (code: ' . $fileError . ').';
    handle_error($msg, 400);
}

$tmpPath      = $_FILES['image']['tmp_name'];
$originalName = basename($_FILES['image']['name']);
$fileSize     = (int)$_FILES['image']['size'];

// Validate file size (max 20MB)
$maxSizeBytes = 20 * 1024 * 1024;
if ($fileSize > $maxSizeBytes) {
    handle_error('File is too large. Maximum allowed image size is 20MB.', 400);
}

// Validate image MIME type using finfo
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];

if (!isset($allowedMimes[$mimeType])) {
    handle_error("Unsupported file type ({$mimeType}). Only JPEG, PNG, WEBP, and GIF images are allowed.", 400);
}

// Verify image can be parsed
$imageInfo = @getimagesize($tmpPath);
if ($imageInfo === false) {
    handle_error('Uploaded file is corrupted or not a valid image.', 400);
}

// Ensure destination uploads directory exists
$targetDir = __DIR__ . '/uploads/';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
}

// Generate unique sanitized filename
$extension = $allowedMimes[$mimeType];
$safeName  = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
$destPath  = $targetDir . $safeName;
$relPath   = 'uploads/' . $safeName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    handle_error('Failed to save uploaded image to destination directory.', 500);
}

// ==========================================
// Sightengine GenAI Inference API Call
// ==========================================
$seUser   = trim(get_env_var('SE_API_USER', ''));
$seSecret = trim(get_env_var('SE_API_SECRET', ''));
$seKey    = trim(get_env_var('SE_API_KEY', ''));
$seUrl    = get_env_var('SE_API_URL') ?: 'https://api.sightengine.com/1.0/check.json';

// Handle user:secret composite in SE_API_KEY if applicable
if (empty($seSecret) && !empty($seKey)) {
    if (str_contains($seKey, ':')) {
        list($seUser, $seSecret) = explode(':', $seKey, 2);
    } else {
        $seSecret = $seKey;
    }
}

$rawResponseStr   = '';
$verdict          = 'Unknown';
$confidenceScore  = null;
$errorMessage     = null;

if (empty($seUser)) {
    $rawResponseStr   = json_encode([
        'status' => 'failure',
        'error'  => [
            'type'    => 'missing_credentials',
            'code'    => 1,
            'message' => 'Sightengine requires both an API User ID and an API Secret. Please define SE_API_USER in your .env file.'
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $verdict          = 'Configuration Notice';
    $confidenceScore  = null;
    $errorMessage     = 'Missing Sightengine API User ID. Sightengine requires both your numeric API User ID (e.g. 1234567890) and your API Secret. Please add SE_API_USER to your .env file.';
} else {
    // Send image to Sightengine check.json with genai model
    $cFile = new CURLFile($destPath, $mimeType, $originalName);
    $postData = [
        'models'     => 'genai',
        'api_user'   => $seUser,
        'api_secret' => $seSecret,
        'media'      => $cFile
    ];

    $ch = curl_init($seUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_TIMEOUT        => 35,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($response === false) {
        $rawResponseStr   = json_encode(['error' => 'cURL Error: ' . $curlError]);
        $verdict          = 'Network Error';
        $confidenceScore  = null;
        $errorMessage     = 'Failed to connect to Sightengine API: ' . $curlError;
    } else {
        $rawResponseStr = $response;
        $resultData     = json_decode($response, true);

        if ($httpCode === 200 && is_array($resultData) && isset($resultData['status']) && $resultData['status'] === 'success') {
            if (isset($resultData['type']['ai_generated'])) {
                $aiScore = floatval($resultData['type']['ai_generated']);
                if ($aiScore >= 0.50) {
                    $verdict = 'AI-Generated';
                    $confidenceScore = round($aiScore, 4);
                } else {
                    $verdict = 'Human-Made';
                    $confidenceScore = round(1.0 - $aiScore, 4);
                }
            } else {
                $verdict         = 'Unknown';
                $confidenceScore = null;
                $errorMessage    = 'Sightengine response did not contain an ai_generated score.';
            }
        } else {
            $verdict         = 'API Notice';
            $confidenceScore = null;
            if (is_array($resultData) && isset($resultData['error']['message'])) {
                $errorMessage = 'Sightengine Error: ' . $resultData['error']['message'];
            } else {
                $errorMessage = "Sightengine returned HTTP status code {$httpCode}: " . substr((string)$response, 0, 300);
            }
        }
    }
}

// Optional simulation fallback if enabled and an error occurred
$enableMock = strtolower(get_env_var('ENABLE_MOCK_FALLBACK', 'false')) === 'true';
if (!empty($errorMessage) && $enableMock) {
    $isAi = (crc32($originalName . (string)$fileSize) % 2 === 0);
    $confidenceScore = round(0.88 + (abs(crc32($originalName)) % 110) / 1000.0, 4);
    $verdict = $isAi ? 'AI-Generated' : 'Human-Made';
    $rawResponseStr = json_encode([
        'simulation_mode' => true,
        'provider'        => 'sightengine',
        'reason'          => 'API error encountered. Fallback simulation active (ENABLE_MOCK_FALLBACK=true).',
        'original_error'  => $errorMessage,
        'type'            => [
            'ai_generated' => $isAi ? $confidenceScore : round(1.0 - $confidenceScore, 4)
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $errorMessage = "Note: API returned an error ({$errorMessage}). Displaying simulated verdict because ENABLE_MOCK_FALLBACK=true.";
}

// Log results into local MySQL database inside the same container
$logId = null;
$dbError = null;

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        INSERT INTO scan_logs (
            file_name,
            file_path,
            file_size,
            mime_type,
            verdict,
            confidence_score,
            raw_response,
            created_at
        ) VALUES (
            :file_name,
            :file_path,
            :file_size,
            :mime_type,
            :verdict,
            :confidence_score,
            :raw_response,
            NOW()
        )
    ");

    $stmt->bindValue(':file_name', $originalName);
    $stmt->bindValue(':file_path', $relPath);
    $stmt->bindValue(':file_size', $fileSize, PDO::PARAM_INT);
    $stmt->bindValue(':mime_type', $mimeType);
    $stmt->bindValue(':verdict', $verdict);
    $stmt->bindValue(':confidence_score', $confidenceScore !== null ? $confidenceScore : null);
    $stmt->bindValue(':raw_response', $rawResponseStr);

    $stmt->execute();
    $logId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    $dbError = 'Database logging error: ' . $e->getMessage();
}

// Return response payload
$payload = [
    'success'          => true,
    'id'               => $logId,
    'file_name'        => $originalName,
    'file_path'        => $relPath,
    'file_size'        => $fileSize,
    'mime_type'        => $mimeType,
    'verdict'          => $verdict,
    'confidence_score' => $confidenceScore,
    'raw_response'     => $rawResponseStr,
    'api_notice'       => $errorMessage,
    'db_error'         => $dbError
];

if (is_ajax_request()) {
    send_json_response($payload, 200);
} else {
    if ($logId) {
        header("Location: index.php?id={$logId}");
    } else {
        $_SESSION['flash_result'] = $payload;
        header("Location: index.php");
    }
    exit;
}
