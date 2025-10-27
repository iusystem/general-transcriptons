<?php
/**
 * Upload General Transcription File
 * Handles file upload and starts transcription process
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../wp-auth.php';

// Only allow logged-in users
if (!is_user_logged_in()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Not logged in']));
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    
    $file = $_FILES['file'];
    $originalFilename = $file['name'];
    $tmpPath = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
    
    // Validate file size (100MB max)
    $maxSize = 100 * 1024 * 1024;
    if ($fileSize > $maxSize) {
        throw new Exception('File too large. Maximum size is 100MB.');
    }
    
    // Validate file type
    $allowedTypes = [
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/m4a', 
        'audio/x-m4a', 'audio/mp4', 'video/mp4', 'video/webm', 'audio/webm',
        'audio/ogg', 'video/quicktime', 'audio/aac'
    ];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    
    // Also check extension as a fallback
    $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $allowedExtensions = ['mp3', 'wav', 'm4a', 'mp4', 'webm', 'ogg', 'aac', 'mov'];
    
    if (!in_array($mimeType, $allowedTypes) && !in_array($extension, $allowedExtensions)) {
        throw new Exception('Invalid file type. Supported: MP3, WAV, M4A, MP4, WebM');
    }
    
    // Determine file type for storage
    $fileType = $extension ?: 'unknown';
    
    error_log("=== GENERAL TRANSCRIPTION UPLOAD ===");
    error_log("Original filename: $originalFilename");
    error_log("File size: {$fileSizeMB}MB");
    error_log("MIME type: $mimeType");
    error_log("Extension: $fileType");
    
    // Connect to database
    $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db_name = defined('DB_NAME') ? DB_NAME : '';
    $db_user = defined('DB_USER') ? DB_USER : '';
    $db_pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
    
    if (empty($db_name) || empty($db_user)) {
        throw new Exception('Database configuration not found');
    }
    
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Generate unique filename
    $timestamp = date('YmdHis');
    $randomStr = substr(md5(uniqid()), 0, 8);
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalFilename, PATHINFO_FILENAME));
    $filename = "{$timestamp}_{$randomStr}_{$safeFilename}.{$fileType}";
    
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../uploads/general-transcriptions/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $uploadPath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($tmpPath, $uploadPath)) {
        throw new Exception('Failed to save uploaded file');
    }
    
    error_log("File saved to: $uploadPath");
    
    // Get current user email
    $user_email = wp_get_current_user()->user_email;
    
    // Insert record into database
    $stmt = $pdo->prepare("
        INSERT INTO general_transcripts (
            filename, 
            original_filename, 
            file_type, 
            file_size_mb,
            user_email,
            status,
            progress_text
        ) VALUES (?, ?, ?, ?, ?, 'pending', 'File uploaded, queued for transcription...')
    ");
    
    $stmt->execute([
        $filename,
        $originalFilename,
        $fileType,
        $fileSizeMB,
        $user_email
    ]);
    
    $transcriptId = $pdo->lastInsertId();
    error_log("Created transcript record ID: $transcriptId");
    
    // Immediately start transcription process
    error_log("Starting transcription process for ID: $transcriptId");
    
    // Call the transcription script in the background
    $transcriptionUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/start-general-transcription.php';
    
    // Use a non-blocking approach to start transcription
    $ch = curl_init($transcriptionUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['transcript_id' => $transcriptId]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 1, // Short timeout to make it non-blocking
        CURLOPT_NOSIGNAL => 1
    ]);
    
    // Execute in background (we don't wait for response)
    curl_exec($ch);
    curl_close($ch);
    
    error_log("Transcription process triggered in background");
    
    // Return success
    echo json_encode([
        'success' => true,
        'transcript_id' => $transcriptId,
        'filename' => $originalFilename,
        'message' => 'File uploaded successfully. Transcription started!'
    ]);
    
} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
