<?php
/**
 * Get General Transcript
 * Returns transcript data for viewing/downloading
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
    $transcript_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if (!$transcript_id) {
        throw new Exception('Missing transcript ID');
    }
    
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
    
    // Get transcript
    $stmt = $pdo->prepare("SELECT * FROM general_transcripts WHERE id = ?");
    $stmt->execute([$transcript_id]);
    $transcript = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transcript) {
        throw new Exception('Transcript not found');
    }
    
    // Check permissions (users can only view their own transcripts, admins can view all)
    $is_admin = current_user_can('manage_options');
    $user_email = wp_get_current_user()->user_email;
    
    if (!$is_admin && $transcript['user_email'] !== $user_email) {
        http_response_code(403);
        throw new Exception('You do not have permission to view this transcript');
    }
    
    if ($transcript['status'] !== 'completed') {
        throw new Exception('Transcript is not yet completed (status: ' . $transcript['status'] . ')');
    }
    
    if (empty($transcript['full_transcript'])) {
        throw new Exception('Transcript text is empty');
    }
    
    // Return transcript data
    echo json_encode([
        'success' => true,
        'id' => $transcript['id'],
        'filename' => $transcript['original_filename'],
        'transcript' => $transcript['full_transcript'],
        'transcript_json' => $transcript['transcript_json'] ? json_decode($transcript['transcript_json'], true) : null,
        'detected_language' => $transcript['detected_language'],
        'speaker_count' => $transcript['speaker_count'],
        'duration_seconds' => $transcript['duration_seconds'],
        'created_at' => $transcript['created_at'],
        'completed_at' => $transcript['completed_at']
    ]);
    
} catch (Exception $e) {
    error_log("Get transcript error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
