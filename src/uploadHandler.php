<?php
//Database configuration from environment variables
$host = getenv('DB_HOST') ?:'db';
$db = getenv('DB_NAME') ?:'ai_db';
$user = getenv('DB_USER') ?:'root';
$pass   = getenv('DB_PASS') ?: 'secret_password';

//API configuration from environment variables
$apiKey = getenv('SYNTHID_API_KEY') ?: '';
$hfUrl = getenv('HF_API_URL') ?:'https://router.huggingface.co/hf-inference/models/Organika/sdxl-detector';

// Connect to MySQL using PDO
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
} catch (PDOException $e) {
    die("database connection failed " . $e->getMessage());
}


if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["image"])) {
    $targetDir = "uploads/";
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $file_name = basename($_FILES["image"]["name"]);
    $target_file = $targetDir . time() . "_" . $file_name;
    
    if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
        
        //  ----  SYNTHID LOGIC WILL BE PLACED HERE  ----
        // Call the detection service here
        $imageBinary = file_get_contents($target_file);

        $ch = curl_init($hfUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageBinary);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/octet-stream"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        //curl_close($ch);  //depracated method since php 8.5

        $result = json_decode($response, true);

        // Default fallback values if API returns error or is still loading model
        $verdictLabel = "Unknown";
        $confidenceScore = 0.0;

        // Hugging Face classifiers typically return an array of predictions
        if (is_array($result) && isset($result[0]['label'])) {
            $verdictLabel = $result[0]['label'];
            $confidenceScore = $result[0]['score'];
        } elseif (isset($result['error'])) {
            $verdictLabel = "API Notice";
            $details = $result['error']; // e.g. Model is loading
        }

        // Determine if it's AI-generated based on label
        $isAi = (stripos($verdictLabel, 'ai') !== false || stripos($verdictLabel, 'fake') !== false) ? 1 : 0;

        // Save result into local MySQL database
        $stmt = $pdo->prepare("INSERT INTO scan_history (file_name, verdict, details, scanned_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([
            $file_name, 
            $verdictLabel, 
            "Confidence: " . ($confidenceScore * 100) . "%"
        ]);


        // Output results
        echo "<h2>Analysis Result</h2>";
        echo "<p class='alert alert-success'><strong>Verdict:</strong> {$verdictLabel} (Confidence: " . round($confidenceScore * 100, 2) . "%</p>";
        echo "<p>{$details}</p>";
        echo "<p class='text-muted'><small>Logged to local MySQL container database successfully.</small></p>";
        echo "<a href='processForm.php' class='btn btn-primary'>Analyze Another Image</a>";
        echo "</div></div>";
        
    } else {
        echo "Sorry, there was an error uploading your file.";

    }
}
?>