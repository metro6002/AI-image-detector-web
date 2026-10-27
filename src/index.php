
<?php
/**
 * Frontend Upload Form and UI Results Display
 * Connects to local MySQL database (aidb) to display current verdict & recent scan history
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

session_start();

// Helper to read environment variables with fallback to .env file
function get_env_var(string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (!empty($_ENV[$key])) {
        return $_ENV[$key];
    }
    if (!empty($_SERVER[$key])) {
        return $_SERVER[$key];
    }

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
                        $envCache[trim($k)] = trim(trim($v), "\"'");
                    }
                }
                break;
            }
        }
    }

    return $envCache[$key] ?? $default;
}

// Connect to MySQL
$dbConnected = false;
$dbError = null;
$pdo = null;

try {
    $host = get_env_var('DB_HOST', '127.0.0.1');
    $port = get_env_var('DB_PORT', '3306');
    $db   = get_env_var('DB_NAME', 'aidb');
    $user = get_env_var('DB_USER', 'root');
    $pass = get_env_var('DB_PASS', 'secret_password');

    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 3,
    ]);
    $dbConnected = true;
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// Fetch scan record if requested by ?id=
$currentScan = null;
if (isset($_GET['id']) && $pdo) {
    $scanId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM scan_logs WHERE id = ?");
    $stmt->execute([$scanId]);
    $currentScan = $stmt->fetch();
}

// Check flash result from session if redirected without id
if (!$currentScan && isset($_SESSION['flash_result'])) {
    $currentScan = $_SESSION['flash_result'];
    unset($_SESSION['flash_result']);
}

// Check flash error
$uploadError = null;
if (isset($_SESSION['upload_error'])) {
    $uploadError = $_SESSION['upload_error'];
    unset($_SESSION['upload_error']);
}

// Fetch recent 10 scans
$recentScans = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM scan_logs ORDER BY id DESC LIMIT 10");
        $recentScans = $stmt->fetchAll();
    } catch (Exception $e) {
        // Table might not exist yet if container is just booting
    }
}

// Format file size
function format_bytes($bytes, $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Image Detector - LAMP Stack</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg-body: #f8fafc;
            --card-border: #e2e8f0;
            --ai-color: #8b5cf6;
            --human-color: #10b981;
        }
        body {
            background-color: var(--bg-body);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
        }
        .app-navbar {
            background: #ffffff;
            border-bottom: 1px solid var(--card-border);
        }
        .main-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 14px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        }
        .drop-zone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 35px 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }
        .drop-zone:hover, .drop-zone.dragover {
            border-color: #6366f1;
            background: #eef2ff;
        }
        .verdict-badge-ai {
            background: var(--ai-color);
            color: #ffffff;
        }
        .verdict-badge-human {
            background: var(--human-color);
            color: #ffffff;
        }
        .verdict-badge-notice {
            background: #f59e0b;
            color: #ffffff;
        }
        .badge-pill {
            font-size: 1.15rem;
            padding: 0.6rem 1.4rem;
            border-radius: 50rem;
            letter-spacing: 0.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .json-block {
            background: #0f172a;
            color: #38bdf8;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.85rem;
            border-radius: 8px;
            max-height: 250px;
            overflow-y: auto;
        }
        .img-preview {
            max-height: 320px;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            background: #00000005;
        }
        .table-thumb {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 6px;
        }
    </style>
</head>
<body>

    <!-- Header / Navbar -->
    <header class="app-navbar py-3 mb-4">
        <div class="container d-flex flex-wrap justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <div>
                    <h5 class="mb-0 fw-bold">AI Image Detector</h5>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
                <?php if ($dbConnected): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
                        <i class="bi bi-database-check me-1"></i> MySQL (aidb) Connected
                    </span>
                <?php else: ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2" title="<?= htmlspecialchars($dbError ?? '') ?>">
                        <i class="bi bi-database-slash me-1"></i> MySQL Offline
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="container pb-5">
        <!-- Alert Notice if any -->
        <?php if ($uploadError): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Upload Error:</strong> <?= htmlspecialchars($uploadError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!$dbConnected): ?>
            <div class="alert alert-warning shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i>
                <strong>Database Note:</strong> Unable to connect to MySQL database: <code><?= htmlspecialchars($dbError ?? 'Unknown error') ?></code>. Image upload and inference will still function, but scan history logging may be disabled.
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Column: Upload Form -->
            <div class="col-lg-5">
                <div class="main-card p-4 h-100">
                    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-cloud-arrow-up-fill text-primary"></i> Upload Image
                    </h4>
                    <p class="text-muted small mb-4">
                        Submit a JPEG, PNG, or WEBP image to classify whether it was generated by Artificial Intelligence or captured by a Human.
                    </p>

                    <form id="uploadForm" action="upload.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="ajax" value="1">
                        
                        <div class="drop-zone mb-3" id="dropZone">
                            <i class="bi bi-file-earmark-image fs-1 text-secondary"></i>
                            <h6 class="mt-2 fw-semibold text-dark">Drag and drop your image here</h6>
                            <p class="text-muted small mb-3">or click to browse your files</p>
                            <input type="file" id="imageInput" name="image" class="d-none" accept="image/jpeg,image/png,image/webp,image/gif" required>
                            <button type="button" class="btn btn-sm btn-outline-primary px-3" onclick="document.getElementById('imageInput').click()">
                                Select Image
                            </button>
                        </div>

                        <!-- Image Preview in Form -->
                        <div id="formPreviewContainer" class="d-none mb-3 text-center">
                            <img id="formPreviewImg" src="" alt="Selected Preview" class="img-preview w-100 mb-2">
                            <div class="d-flex justify-content-between align-items-center small text-muted px-2">
                                <span id="formFileName" class="text-truncate fw-medium" style="max-width: 200px;"></span>
                                <span id="formFileSize"></span>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="d-grid mt-4">
                            <button type="submit" id="submitBtn" class="btn btn-primary btn-lg fw-semibold d-flex justify-content-center align-items-center gap-2">
                                <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                <i class="bi bi-cpu-fill" id="btnIcon"></i>
                                <span id="btnText">Analyze Image</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column: Verdict Display -->
            <div class="col-lg-7">
                <div class="main-card p-4 h-100" id="resultCard">
                    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-pie-chart-fill text-primary"></i> Detection Verdict
                    </h4>

                    <!-- Dynamic / Initial Result View -->
                    <div id="resultContent">
                        <?php if ($currentScan): ?>
                            <?php
                                $verdict = $currentScan['verdict'] ?? 'Unknown';
                                $score   = isset($currentScan['confidence_score']) ? floatval($currentScan['confidence_score']) : null;
                                $scorePct = $score !== null ? round($score * 100, 2) : null;
                                $rawResp = $currentScan['raw_response'] ?? '';
                                $thumbPath = !empty($currentScan['file_path']) ? htmlspecialchars($currentScan['file_path']) : '';
                            ?>
                            <div class="text-center py-3">
                                <?php if ($verdict === 'AI-Generated'): ?>
                                    <span class="badge-pill verdict-badge-ai mb-3 shadow">
                                        <i class="bi bi-robot fs-5"></i> AI-Generated
                                    </span>
                                    <p class="text-muted">The model determined this image exhibits synthetic patterns generated by an AI model.</p>
                                <?php elseif ($verdict === 'Human-Made'): ?>
                                    <span class="badge-pill verdict-badge-human mb-3 shadow">
                                        <i class="bi bi-person-check-fill fs-5"></i> Human-Made
                                    </span>
                                    <p class="text-muted">The model classified this image as an authentic human photograph or artwork.</p>
                                <?php else: ?>
                                    <span class="badge-pill verdict-badge-notice mb-3 shadow">
                                        <i class="bi bi-info-circle-fill fs-5"></i> <?= htmlspecialchars($verdict) ?>
                                    </span>
                                    <p class="text-muted">Notice: The inference provider returned informational feedback or an error message.</p>
                                <?php endif; ?>

                                <?php if (str_contains($rawResp, 'missing_credentials') || str_contains($rawResp, 'Incorrect API user') || str_contains($rawResp, 'credentials_error')): ?>
                                    <div class="alert alert-warning text-start small mt-3 border-warning shadow-sm">
                                        <h6 class="fw-bold mb-1"><i class="bi bi-key me-1"></i> Sightengine Credentials Setup</h6>
                                        Sightengine requires both an <strong>API User ID</strong> and an <strong>API Secret</strong>.
                                        <ul class="mb-1 mt-2">
                                            <li>Visit your <a href="https://dashboard.sightengine.com/api-credentials" target="_blank" class="alert-link">Sightengine API Credentials Dashboard</a>.</li>
                                            <li>Copy your numeric <strong>API User</strong> and set it in your <code>.env</code> file: <code>SE_API_USER=your_user_id</code>.</li>
                                            <li>Ensure your <strong>API Secret</strong> is set in <code>.env</code>: <code>SE_API_SECRET=your_secret</code>.</li>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <!-- Confidence Bar -->
                                <?php if ($scorePct !== null): ?>
                                    <div class="my-4 px-lg-4 text-start">
                                        <div class="d-flex justify-content-between fw-semibold small mb-1">
                                            <span>Model Confidence Score</span>
                                            <span class="text-primary"><?= $scorePct ?>%</span>
                                        </div>
                                        <div class="progress" style="height: 12px; border-radius: 6px;">
                                            <div class="progress-bar <?= $verdict === 'AI-Generated' ? 'bg-primary' : 'bg-success' ?>" 
                                                 role="progressbar" 
                                                 style="width: <?= min($scorePct, 100) ?>%;" 
                                                 aria-valuenow="<?= $scorePct ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Image Preview & Metadata -->
                            <div class="card bg-light border-0 rounded-3 p-3 mb-3">
                                <div class="row align-items-center g-3">
                                    <?php if ($thumbPath && file_exists(__DIR__ . '/' . $thumbPath)): ?>
                                        <div class="col-sm-4 text-center">
                                            <img src="<?= $thumbPath ?>" alt="Scanned Image" class="img-preview w-100 shadow-sm">
                                        </div>
                                    <?php endif; ?>
                                    <div class="col">
                                        <h6 class="fw-bold mb-2">Image Metadata</h6>
                                        <ul class="list-unstyled mb-0 small text-muted">
                                            <li class="mb-1"><strong>File Name:</strong> <?= htmlspecialchars($currentScan['file_name']) ?></li>
                                            <li class="mb-1"><strong>File Size:</strong> <?= !empty($currentScan['file_size']) ? format_bytes($currentScan['file_size']) : 'N/A' ?></li>
                                            <li class="mb-1"><strong>MIME Type:</strong> <?= htmlspecialchars($currentScan['mime_type'] ?? 'image/*') ?></li>
                                            <li class="mb-1"><strong>Scanned At:</strong> <?= htmlspecialchars($currentScan['created_at'] ?? 'Just now') ?></li>
                                            <li><strong>Database Log ID:</strong> #<?= htmlspecialchars($currentScan['id'] ?? 'N/A') ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Raw JSON Accordion -->
                            <div class="accordion" id="jsonAccordion">
                                <div class="accordion-item border rounded-3 overflow-hidden">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed py-2 small fw-semibold" type="button" data-bs-dismiss="collapse" data-bs-toggle="collapse" data-bs-target="#collapseJson" aria-expanded="false">
                                            <i class="bi bi-code-slash me-2"></i> Raw API Response from Sightengine
                                        </button>
                                    </h2>
                                    <div id="collapseJson" class="accordion-collapse collapse">
                                        <div class="accordion-body p-2">
                                            <pre class="json-block p-3 mb-0"><code><?= htmlspecialchars($rawResp) ?></code></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3 text-end">
                                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-arrow-repeat me-1"></i> Scan Another Image
                                </a>
                            </div>

                        <?php else: ?>
                            <!-- Empty State -->
                            <div class="text-center py-5">
                                <div class="mb-3">
                                    <i class="bi bi-image text-muted" style="font-size: 4rem;"></i>
                                </div>
                                <h5 class="fw-semibold text-secondary">No Scan Selected Yet</h5>
                                <p class="text-muted small mx-auto" style="max-width: 360px;">
                                    Upload an image using the form on the left to analyze it via Sightengine AI detector.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Scans History Section (From MySQL) -->
        <div class="row mt-5">
            <div class="col-12">
                <div class="main-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history text-primary"></i> Recent Scans History (MySQL: <code>aidb.scan_logs</code>)
                        </h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="window.location.reload()">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh Logs
                        </button>
                    </div>

                    <?php if (empty($recentScans)): ?>
                        <div class="text-center py-4 text-muted small">
                            <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                            No scans logged in the database yet. Submit your first image above to record a log.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small">
                                    <tr>
                                        <th># ID</th>
                                        <th>Thumbnail</th>
                                        <th>File Name</th>
                                        <th>Verdict</th>
                                        <th>Confidence</th>
                                        <th>Size</th>
                                        <th>Scanned At</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentScans as $row): ?>
                                        <tr>
                                            <td class="fw-bold">#<?= htmlspecialchars($row['id']) ?></td>
                                            <td>
                                                <?php if (!empty($row['file_path']) && file_exists(__DIR__ . '/' . $row['file_path'])): ?>
                                                    <img src="<?= htmlspecialchars($row['file_path']) ?>" class="table-thumb border" alt="Thumbnail">
                                                <?php else: ?>
                                                    <div class="table-thumb border bg-light d-flex align-items-center justify-content-center text-muted">
                                                        <i class="bi bi-image"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($row['file_name']) ?>">
                                                <?= htmlspecialchars($row['file_name']) ?>
                                            </td>
                                            <td>
                                                <?php if ($row['verdict'] === 'AI-Generated'): ?>
                                                    <span class="badge bg-purple-subtle text-purple border" style="background-color: #ede9fe; color: #6d28d9; border-color: #ddd6fe;">
                                                        <i class="bi bi-robot me-1"></i> AI-Generated
                                                    </span>
                                                <?php elseif ($row['verdict'] === 'Human-Made'): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                        <i class="bi bi-person-check me-1"></i> Human-Made
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                                        <?= htmlspecialchars($row['verdict']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= isset($row['confidence_score']) && $row['confidence_score'] !== null ? round($row['confidence_score'] * 100, 1) . '%' : 'N/A' ?>
                                            </td>
                                            <td class="small text-muted">
                                                <?= !empty($row['file_size']) ? format_bytes($row['file_size']) : 'N/A' ?>
                                            </td>
                                            <td class="small text-muted"><?= htmlspecialchars($row['created_at']) ?></td>
                                            <td class="text-end">
                                                <a href="index.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Dynamic Upload & Preview Script -->
    <script>
        const dropZone = document.getElementById('dropZone');
        const imageInput = document.getElementById('imageInput');
        const formPreviewContainer = document.getElementById('formPreviewContainer');
        const formPreviewImg = document.getElementById('formPreviewImg');
        const formFileName = document.getElementById('formFileName');
        const formFileSize = document.getElementById('formFileSize');
        const uploadForm = document.getElementById('uploadForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnSpinner = document.getElementById('btnSpinner');
        const btnIcon = document.getElementById('btnIcon');
        const btnText = document.getElementById('btnText');
        const resultContent = document.getElementById('resultContent');

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function handleFileSelection(file) {
            if (!file) return;
            if (!file.type.startsWith('image/')) {
                alert('Please select a valid image file (JPEG, PNG, WEBP, GIF).');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                formPreviewImg.src = e.target.result;
                formFileName.textContent = file.name;
                formFileSize.textContent = formatBytes(file.size);
                formPreviewContainer.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
        }

        imageInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files[0]) {
                handleFileSelection(e.target.files[0]);
            }
        });

        // Drag and Drop listeners
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('dragover');
            });
        });

        dropZone.addEventListener('drop', (e) => {
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                imageInput.files = e.dataTransfer.files;
                handleFileSelection(e.dataTransfer.files[0]);
            }
        });

        // AJAX Form Submission
        uploadForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            if (!imageInput.files || !imageInput.files[0]) {
                alert('Please select an image file first.');
                return;
            }

            // UI Loading state
            submitBtn.disabled = true;
            btnSpinner.classList.remove('d-none');
            btnIcon.classList.add('d-none');
            btnText.textContent = 'Analyzing Image...';

            const formData = new FormData(uploadForm);

            try {
                const response = await fetch('upload.php', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Inference analysis failed.');
                }

                // Render Result dynamically
                renderScanResult(data);

            } catch (err) {
                alert('Error processing image: ' + err.message);
            } finally {
                submitBtn.disabled = false;
                btnSpinner.classList.add('d-none');
                btnIcon.classList.remove('d-none');
                btnText.textContent = 'Analyze Image';
            }
        });

        function renderScanResult(data) {
            const isAi = data.verdict === 'AI-Generated';
            const isHuman = data.verdict === 'Human-Made';
            const scorePct = data.confidence_score !== null ? (data.confidence_score * 100).toFixed(2) : null;

            let badgeHtml = '';
            let explanation = '';

            if (isAi) {
                badgeHtml = `<span class="badge-pill verdict-badge-ai mb-3 shadow"><i class="bi bi-robot fs-5"></i> AI-Generated</span>`;
                explanation = `The model determined this image exhibits synthetic patterns generated by an AI model.`;
            } else if (isHuman) {
                badgeHtml = `<span class="badge-pill verdict-badge-human mb-3 shadow"><i class="bi bi-person-check-fill fs-5"></i> Human-Made</span>`;
                explanation = `The model classified this image as an authentic human photograph or artwork.`;
            } else {
                badgeHtml = `<span class="badge-pill verdict-badge-notice mb-3 shadow"><i class="bi bi-info-circle-fill fs-5"></i> ${data.verdict}</span>`;
                explanation = data.api_notice ? data.api_notice : `The inference provider returned informational feedback or an error message.`;
            }

            let progressHtml = '';
            if (scorePct !== null) {
                progressHtml = `
                    <div class="my-4 px-lg-4 text-start">
                        <div class="d-flex justify-content-between fw-semibold small mb-1">
                            <span>Model Confidence Score</span>
                            <span class="text-primary">${scorePct}%</span>
                        </div>
                        <div class="progress" style="height: 12px; border-radius: 6px;">
                            <div class="progress-bar ${isAi ? 'bg-primary' : 'bg-success'}" 
                                 role="progressbar" 
                                 style="width: ${Math.min(scorePct, 100)}%;" 
                                 aria-valuenow="${scorePct}" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100"></div>
                        </div>
                    </div>
                `;
            }

            const rawFormatted = typeof data.raw_response === 'string' ? data.raw_response : JSON.stringify(data.raw_response, null, 2);

            let credentialsWarningHtml = '';
            if (rawFormatted.includes('missing_credentials') || rawFormatted.includes('Incorrect API user') || rawFormatted.includes('credentials_error')) {
                credentialsWarningHtml = `
                    <div class="alert alert-warning text-start small mt-3 border-warning shadow-sm">
                        <h6 class="fw-bold mb-1"><i class="bi bi-key me-1"></i> Sightengine Credentials Setup</h6>
                        Sightengine requires both an <strong>API User ID</strong> and an <strong>API Secret</strong>.
                        <ul class="mb-1 mt-2">
                            <li>Visit your <a href="https://dashboard.sightengine.com/api-credentials" target="_blank" class="alert-link">Sightengine API Credentials Dashboard</a>.</li>
                            <li>Copy your numeric <strong>API User</strong> and set it in your <code>.env</code> file: <code>SE_API_USER=your_user_id</code>.</li>
                            <li>Ensure your <strong>API Secret</strong> is set in <code>.env</code>: <code>SE_API_SECRET=your_secret</code>.</li>
                        </ul>
                    </div>
                `;
            }

            resultContent.innerHTML = `
                <div class="text-center py-3">
                    ${badgeHtml}
                    <p class="text-muted">${explanation}</p>
                    ${progressHtml}
                    ${credentialsWarningHtml}
                </div>

                <div class="card bg-light border-0 rounded-3 p-3 mb-3">
                    <div class="row align-items-center g-3">
                        <div class="col-sm-4 text-center">
                            <img src="${data.file_path}" alt="Scanned Image" class="img-preview w-100 shadow-sm">
                        </div>
                        <div class="col">
                            <h6 class="fw-bold mb-2">Image Metadata</h6>
                            <ul class="list-unstyled mb-0 small text-muted">
                                <li class="mb-1"><strong>File Name:</strong> ${data.file_name}</li>
                                <li class="mb-1"><strong>File Size:</strong> ${formatBytes(data.file_size)}</li>
                                <li class="mb-1"><strong>MIME Type:</strong> ${data.mime_type}</li>
                                <li class="mb-1"><strong>Database Log ID:</strong> #${data.id || 'N/A'}</li>
                                <li><strong>Logged to MySQL:</strong> <span class="badge bg-success-subtle text-success">Saved to scan_logs</span></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="accordion" id="jsonAccordion">
                    <div class="accordion-item border rounded-3 overflow-hidden">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed py-2 small fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseJsonDynamic" aria-expanded="false">
                                <i class="bi bi-code-slash me-2"></i> Raw API Response from Sightengine
                            </button>
                        </h2>
                        <div id="collapseJsonDynamic" class="accordion-collapse collapse">
                            <div class="accordion-body p-2">
                                <pre class="json-block p-3 mb-0"><code>${rawFormatted}</code></pre>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 text-end">
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-repeat me-1"></i> Scan Another Image
                    </a>
                </div>
            `;

            // Scroll to result on mobile
            if (window.innerWidth < 992) {
                document.getElementById('resultCard').scrollIntoView({ behavior: 'smooth' });
            }
        }
    </script>
</body>
</html>