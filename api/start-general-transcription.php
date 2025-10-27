<?php
/**
 * Start General Transcription Job
 * Uses OpenAI Whisper API + AssemblyAI for speaker diarization
 * Railway only used for lightweight audio extraction from videos
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(600); // 10 minutes max

// Load WordPress
require_once __DIR__ . '/../wp-auth.php';

// Load config for API keys
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

try {
    // Get transcript ID from POST body or command line (for background execution)
    $input = json_decode(file_get_contents('php://input'), true);
    $transcript_id = isset($input['transcript_id']) ? (int)$input['transcript_id'] : 0;
    
    if (!$transcript_id) {
        throw new Exception('Missing transcript_id');
    }
    
    error_log("=== STARTING GENERAL TRANSCRIPTION ===");
    error_log("Transcript ID: $transcript_id");
    
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
    
    // Helper function to update progress
    function updateProgress($pdo, $transcript_id, $message, $status = 'processing') {
        try {
            $stmt = $pdo->prepare("
                UPDATE general_transcripts 
                SET progress_text = ?, 
                    status = ?,
                    started_at = COALESCE(started_at, CURRENT_TIMESTAMP)
                WHERE id = ?
            ");
            $stmt->execute([$message, $status, $transcript_id]);
            error_log("Progress: $message");
        } catch (PDOException $e) {
            error_log("WARNING: Progress update failed: " . $e->getMessage());
        }
    }
    
    // ============================================
    // STEP 1: Get transcript record
    // ============================================
    updateProgress($pdo, $transcript_id, "Step 1/6: Loading file information...");
    
    $stmt = $pdo->prepare("SELECT * FROM general_transcripts WHERE id = ?");
    $stmt->execute([$transcript_id]);
    $transcript = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transcript) {
        throw new Exception('Transcript not found');
    }
    
    error_log("Found transcript: " . $transcript['original_filename']);
    
    // ============================================
    // STEP 2: Locate uploaded file
    // ============================================
    updateProgress($pdo, $transcript_id, "Step 2/6: Locating uploaded file...");
    
    $uploadDir = __DIR__ . '/../uploads/general-transcriptions/';
    $filePath = $uploadDir . $transcript['filename'];
    
    if (!file_exists($filePath)) {
        throw new Exception('Uploaded file not found: ' . $transcript['filename']);
    }
    
    error_log("File located at: $filePath");
    $actualSizeMB = round(filesize($filePath) / 1024 / 1024, 2);
    error_log("File size: {$actualSizeMB}MB");
    
    // ============================================
    // STEP 3: Determine if we need audio extraction
    // ============================================
    updateProgress($pdo, $transcript_id, "Step 3/6: Preparing audio...");
    
    $fileType = strtolower($transcript['file_type']);
    $isVideo = in_array($fileType, ['mp4', 'webm', 'mov', 'avi']);
    $audioPath = $filePath;
    $needsExtraction = $isVideo;
    
    error_log("File type: $fileType, Is video: " . ($isVideo ? 'yes' : 'no'));
    
    // If it's a video, extract audio via Railway
    if ($needsExtraction) {
        error_log("STEP 3: Extracting audio via Railway...");
        updateProgress($pdo, $transcript_id, "Step 3/6: Extracting audio from video (30s-2min)...");
        
        // Get Railway API URL
        $railway_url = defined('RAILWAY_API_URL') ? RAILWAY_API_URL : '';
        if (empty($railway_url)) {
            throw new Exception('RAILWAY_API_URL not configured in config.php');
        }
        
        // Read file and convert to base64
        $fileContent = file_get_contents($filePath);
        $base64Content = base64_encode($fileContent);
        
        $ch = curl_init($railway_url . '/extract-audio-base64');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'video_base64' => $base64Content,
                'filename' => $transcript['filename']
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 300
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception('Railway connection error: ' . $curl_error);
        }
        
        if ($http_code !== 200) {
            error_log("Railway error: HTTP $http_code - $response");
            throw new Exception('Audio extraction failed (HTTP ' . $http_code . ')');
        }
        
        $extract_result = json_decode($response, true);
        if (!$extract_result || !$extract_result['success']) {
            throw new Exception('Audio extraction failed: ' . ($extract_result['error'] ?? 'Unknown error'));
        }
        
        $audio_url = $extract_result['audio_url'] ?? null;
        $audio_size_mb = $extract_result['audio_size_mb'] ?? 0;
        
        if (!$audio_url) {
            throw new Exception('No audio URL returned from Railway');
        }
        
        error_log("Audio extracted: {$audio_size_mb}MB");
        
        // Download the extracted audio
        $audioContent = file_get_contents($audio_url);
        if ($audioContent === false) {
            throw new Exception('Failed to download extracted audio');
        }
        
        // Save to temp file
        $audioPath = $uploadDir . 'temp_audio_' . $transcript_id . '.mp3';
        file_put_contents($audioPath, $audioContent);
        error_log("Audio saved to: $audioPath");
        
        $actualSizeMB = $audio_size_mb;
    }
    
    // Check Whisper API file size limit (25MB)
    if ($actualSizeMB > 24) {
        throw new Exception('Audio file too large (' . round($actualSizeMB, 1) . 'MB > 25MB Whisper API limit).');
    }
    
    // ============================================
    // STEP 4: Transcribe with OpenAI Whisper API
    // ============================================
    updateProgress($pdo, $transcript_id, "Step 4/6: Calling OpenAI Whisper API (1-3 min)...");
    
    error_log("STEP 4: Calling OpenAI Whisper API...");
    
    // Get OpenAI API key
    $openai_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    if (empty($openai_key)) {
        throw new Exception('OPENAI_API_KEY not configured in config.php');
    }
    
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($audioPath, 'audio/mpeg', basename($audioPath)),
            'model' => 'whisper-1',
            'response_format' => 'verbose_json',
            'timestamp_granularities' => ['segment']
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $openai_key
        ],
        CURLOPT_TIMEOUT => 600
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Clean up temp audio file if it was extracted
    if ($needsExtraction && file_exists($audioPath)) {
        @unlink($audioPath);
    }
    
    if ($curl_error) {
        throw new Exception('OpenAI API error: ' . $curl_error);
    }
    
    if ($http_code !== 200) {
        error_log("OpenAI API error: HTTP $http_code - " . substr($response, 0, 500));
        throw new Exception('Whisper API failed (HTTP ' . $http_code . '). Check OPENAI_API_KEY in config.php');
    }
    
    $whisper_result = json_decode($response, true);
    if (!$whisper_result || !isset($whisper_result['segments'])) {
        throw new Exception('Invalid Whisper API response');
    }
    
    $whisper_segments = $whisper_result['segments'];
    $detected_language = $whisper_result['language'] ?? 'en';
    error_log("Whisper transcription complete: " . count($whisper_segments) . " segments");
    error_log("Detected language: $detected_language");
    
    // Check database connection after long Whisper operation
    try {
        $pdo->query("SELECT 1");
    } catch (PDOException $e) {
        error_log("Database connection lost after Whisper, reconnecting...");
        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user,
            $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        error_log("Database reconnected successfully");
    }
    
    // ============================================
    // STEP 5: Speaker diarization with AssemblyAI (optional)
    // ============================================
    updateProgress($pdo, $transcript_id, "Step 5/6: Getting speaker labels from AssemblyAI (2-4 min)...");
    
    error_log("STEP 5: Getting speakers from AssemblyAI...");
    
    $speaker_timeline = [];
    
    // Get AssemblyAI API key
    $assemblyai_key = defined('ASSEMBLYAI_API_KEY') ? ASSEMBLYAI_API_KEY : '';
    
    if (!empty($assemblyai_key)) {
        // Re-read audio file for AssemblyAI
        $audioContent = file_exists($filePath) ? file_get_contents($filePath) : null;
        
        // If we extracted audio earlier, use the original file
        if ($audioContent !== false && $audioContent !== null) {
            // Upload to AssemblyAI
            $ch = curl_init('https://api.assemblyai.com/v2/upload');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $audioContent,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $assemblyai_key,
                    'Content-Type: application/octet-stream'
                ],
                CURLOPT_TIMEOUT => 300
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code === 200) {
                $upload_result = json_decode($response, true);
                $assembly_audio_url = $upload_result['upload_url'] ?? null;
                
                if ($assembly_audio_url) {
                    error_log("Uploaded to AssemblyAI");
                    
                    // Request transcription with speaker labels
                    $ch = curl_init('https://api.assemblyai.com/v2/transcript');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode([
                            'audio_url' => $assembly_audio_url,
                            'speaker_labels' => true
                        ]),
                        CURLOPT_HTTPHEADER => [
                            'Authorization: ' . $assemblyai_key,
                            'Content-Type: application/json'
                        ],
                        CURLOPT_TIMEOUT => 30
                    ]);
                    
                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($http_code === 200) {
                        $transcript_request = json_decode($response, true);
                        $assembly_id = $transcript_request['id'] ?? null;
                        
                        if ($assembly_id) {
                            error_log("AssemblyAI processing: $assembly_id");
                            
                            // Poll for completion (max 6 minutes)
                            $max_attempts = 120;
                            for ($i = 0; $i < $max_attempts; $i++) {
                                sleep(3);
                                
                                $ch = curl_init('https://api.assemblyai.com/v2/transcript/' . $assembly_id);
                                curl_setopt_array($ch, [
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_HTTPHEADER => [
                                        'Authorization: ' . $assemblyai_key
                                    ],
                                    CURLOPT_TIMEOUT => 10
                                ]);
                                
                                $response = curl_exec($ch);
                                curl_close($ch);
                                
                                $result = json_decode($response, true);
                                $status = $result['status'] ?? 'unknown';
                                
                                if ($status === 'completed') {
                                    if (isset($result['utterances'])) {
                                        foreach ($result['utterances'] as $utterance) {
                                            $speaker_timeline[] = [
                                                'speaker' => $utterance['speaker'],
                                                'start' => $utterance['start'] / 1000,
                                                'end' => $utterance['end'] / 1000
                                            ];
                                        }
                                    }
                                    error_log("AssemblyAI complete: " . count($speaker_timeline) . " speaker segments");
                                    break;
                                } elseif ($status === 'error') {
                                    error_log("AssemblyAI error: " . ($result['error'] ?? 'Unknown'));
                                    break;
                                }
                                
                                // Update progress every 30 seconds
                                if ($i % 10 === 0) {
                                    updateProgress($pdo, $transcript_id, "Step 5/6: Still waiting for AssemblyAI speakers... (" . ($i * 3) . "s)");
                                }
                            }
                        }
                    }
                }
            } else {
                error_log("AssemblyAI upload failed: HTTP $http_code");
            }
        }
    } else {
        error_log("Warning: ASSEMBLYAI_API_KEY not configured, skipping speaker detection");
    }
    
    // Continue even if speaker detection failed
    if (empty($speaker_timeline)) {
        error_log("Warning: No speaker labels available, continuing without speakers");
    }
    
    error_log("Moving to Step 6 (merge and save)...");
    
    // ============================================
    // STEP 6: Merge results and save
    // ============================================
    updateProgress($pdo, $transcript_id, "Step 6/6: Merging transcript with speakers and saving...");
    
    error_log("STEP 6: Merging transcript with speakers...");
    
    $final_segments = [];
    
    foreach ($whisper_segments as $segment) {
        $start_time = $segment['start'];
        $end_time = $segment['end'];
        $text = trim($segment['text']);
        
        // Find matching speaker
        $speaker = null;
        foreach ($speaker_timeline as $sp) {
            if ($start_time >= $sp['start'] && $start_time < $sp['end']) {
                $speaker = $sp['speaker'];
                break;
            }
        }
        
        $final_segments[] = [
            'start' => $start_time,
            'end' => $end_time,
            'text' => $text,
            'speaker' => $speaker ?? 'Speaker'
        ];
    }
    
    // Format full transcript
    $full_transcript = '';
    foreach ($final_segments as $segment) {
        $timestamp = gmdate('H:i:s', (int)$segment['start']);
        $speaker = $segment['speaker'];
        $text = $segment['text'];
        $full_transcript .= "[$timestamp] [$speaker] $text\n";
    }
    
    // Calculate duration
    $duration = end($final_segments)['end'] ?? 0;
    $speaker_count = count(array_unique(array_column($speaker_timeline, 'speaker')));
    
    // Test JSON encoding before saving
    $transcript_json = json_encode($final_segments);
    if ($transcript_json === false) {
        throw new Exception('JSON encoding failed: ' . json_last_error_msg());
    }
    error_log("JSON encoding successful");
    
    // Save to database
    $stmt = $pdo->prepare("
        UPDATE general_transcripts 
        SET status = 'completed',
            full_transcript = ?,
            transcript_json = ?,
            detected_language = ?,
            speaker_count = ?,
            duration_seconds = ?,
            completed_at = CURRENT_TIMESTAMP,
            progress_text = 'Transcription completed successfully!',
            error_message = NULL
        WHERE id = ?
    ");
    
    $stmt->execute([
        $full_transcript,
        $transcript_json,
        $detected_language,
        $speaker_count > 0 ? $speaker_count : null,
        $duration,
        $transcript_id
    ]);
    
    error_log("=== TRANSCRIPTION COMPLETE ===");
    error_log("Transcript ID: $transcript_id");
    error_log("Segments: " . count($final_segments));
    error_log("Duration: " . round($duration, 1) . "s");
    error_log("Speakers: " . ($speaker_count > 0 ? $speaker_count : 'None detected'));
    
    // Return success
    echo json_encode([
        'success' => true,
        'transcript_id' => $transcript_id,
        'filename' => $transcript['original_filename'],
        'segments' => count($final_segments),
        'duration' => round($duration, 1),
        'has_speakers' => count($speaker_timeline) > 0,
        'speaker_count' => $speaker_count,
        'message' => 'Transcription completed successfully!'
    ]);
    
} catch (Exception $e) {
    $error_msg = $e->getMessage();
    $error_line = $e->getLine();
    $error_file = basename($e->getFile());
    
    error_log("=== TRANSCRIPTION ERROR ===");
    error_log("Error: $error_msg");
    error_log("Location: $error_file line $error_line");
    
    if (isset($transcript_id) && $transcript_id && isset($pdo)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE general_transcripts 
                SET status = 'failed', 
                    error_message = ?,
                    progress_text = ?,
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                "$error_msg (line $error_line)",
                "ERROR: " . $error_msg,
                $transcript_id
            ]);
        } catch (Exception $e2) {
            error_log("Failed to update error status: " . $e2->getMessage());
        }
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $error_msg
    ]);
}
