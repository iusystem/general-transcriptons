<?php
/**
 * General Transcription Tool
 * Upload audio/video files and get transcripts
 */

require_once(__DIR__ . '/wp-auth.php');
require_once(__DIR__ . '/functions.php');

// Only allow logged-in users (you can change to admin-only if needed)
if (!is_user_logged_in()) {
    wp_die('You must be logged in to access this page.');
}

$user_email = $current_user->user_email;
$is_admin = current_user_can('manage_options');

// Connect to database
$db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
$db_name = defined('DB_NAME') ? DB_NAME : '';
$db_user = defined('DB_USER') ? DB_USER : '';
$db_pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';

$pdo = new PDO(
    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
    $db_user,
    $db_pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Get user's transcripts (admins see all, users see only their own)
$sql = "
    SELECT * FROM general_transcripts
    WHERE 1=1
";

if (!$is_admin) {
    $sql .= " AND user_email = :email";
}

$sql .= " ORDER BY created_at DESC LIMIT 50";

$stmt = $pdo->prepare($sql);
if (!$is_admin) {
    $stmt->bindParam(':email', $user_email);
}
$stmt->execute();
$transcripts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Transcription Tool</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .upload-section {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            border: 2px dashed #dee2e6;
        }
        .upload-section.dragging {
            background: #e7f3ff;
            border-color: #007bff;
        }
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: -9999px;
        }
        .file-input-label {
            display: block;
            padding: 20px;
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
        }
        .file-input-label:hover {
            background: #f8f9fa;
            border-color: #007bff;
        }
        .file-input-label.has-file {
            background: #d4edda;
            border-color: #28a745;
        }
        .upload-btn {
            width: 100%;
            padding: 15px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 15px;
        }
        .upload-btn:hover {
            background: #0056b3;
        }
        .upload-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #e9ecef;
            border-radius: 6px;
            overflow: hidden;
            margin-top: 15px;
            display: none;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #007bff, #0056b3);
            width: 0%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .transcript-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            transition: box-shadow 0.3s;
        }
        .transcript-card:hover {
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .transcript-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .transcript-filename {
            font-weight: bold;
            font-size: 16px;
            color: #212529;
            margin-bottom: 5px;
        }
        .transcript-meta {
            font-size: 14px;
            color: #6c757d;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .transcript-actions {
            display: flex;
            gap: 10px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-pending { background: #ffc107; color: #000; }
        .status-processing { background: #17a2b8; color: white; }
        .status-completed { background: #28a745; color: white; }
        .status-failed { background: #dc3545; color: white; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            overflow: auto;
        }
        .modal-content {
            background: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 8px;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #dee2e6;
        }
        .close {
            font-size: 28px;
            font-weight: bold;
            color: #6c757d;
            cursor: pointer;
            line-height: 1;
        }
        .close:hover {
            color: #000;
        }
        .transcript-text {
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            max-height: 60vh;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🎙️ General Transcription Tool</h1>
            <div class="user-info">
                Logged in as: <strong><?php echo esc_html($current_user->display_name); ?></strong> 
                | <a href="admin.php">Back to Admin</a>
                | <a href="<?php echo wp_logout_url(); ?>">Logout</a>
            </div>
        </header>

        <!-- Upload Section -->
        <section class="upload-section" id="uploadSection">
            <h2>📤 Upload Audio or Video File</h2>
            <p style="color: #6c757d; margin-bottom: 20px;">
                Supported formats: MP3, MP4, WAV, M4A, WebM (max 100MB)
            </p>
            
            <form id="uploadForm" enctype="multipart/form-data">
                <div class="file-input-wrapper">
                    <input type="file" 
                           id="fileInput" 
                           name="file" 
                           accept="audio/*,video/*"
                           required>
                    <label for="fileInput" class="file-input-label" id="fileLabel">
                        <div style="font-size: 48px; margin-bottom: 10px;">📁</div>
                        <div style="font-size: 16px; font-weight: bold;">Click to select file or drag & drop</div>
                        <div style="font-size: 14px; color: #6c757d; margin-top: 5px;">Audio or video files up to 100MB</div>
                    </label>
                </div>
                
                <button type="submit" class="upload-btn" id="uploadBtn">
                    Start Transcription
                </button>
                
                <div class="progress-bar" id="progressBar">
                    <div class="progress-fill" id="progressFill">0%</div>
                </div>
            </form>
        </section>

        <!-- Completed Transcriptions -->
        <section class="appointments-section">
            <h2>📋 Your Transcriptions</h2>
            
            <?php if (empty($transcripts)): ?>
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h3>No transcriptions yet</h3>
                <p>Upload an audio or video file above to get started</p>
            </div>
            <?php else: ?>
                <?php foreach ($transcripts as $t): ?>
                <div class="transcript-card">
                    <div class="transcript-header">
                        <div style="flex: 1;">
                            <div class="transcript-filename">
                                <?php echo esc_html($t['original_filename']); ?>
                            </div>
                            <div class="transcript-meta">
                                <span>📅 <?php echo date('M j, Y g:i A', strtotime($t['created_at'])); ?></span>
                                <?php if ($t['duration_seconds']): ?>
                                <span>⏱️ <?php echo gmdate('H:i:s', (int)$t['duration_seconds']); ?></span>
                                <?php endif; ?>
                                <?php if ($t['file_size_mb']): ?>
                                <span>💾 <?php echo number_format($t['file_size_mb'], 1); ?> MB</span>
                                <?php endif; ?>
                                <?php if ($t['detected_language']): ?>
                                <span>🌐 <?php echo strtoupper($t['detected_language']); ?></span>
                                <?php endif; ?>
                                <?php if ($t['speaker_count']): ?>
                                <span>👥 <?php echo $t['speaker_count']; ?> speakers</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($t['progress_text'] && $t['status'] === 'processing'): ?>
                            <div style="margin-top: 10px; padding: 10px; background: #e7f3ff; border-radius: 4px; font-size: 14px;">
                                ⏳ <?php echo esc_html($t['progress_text']); ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($t['error_message'] && $t['status'] === 'failed'): ?>
                            <div style="margin-top: 10px; padding: 10px; background: #f8d7da; border-radius: 4px; font-size: 14px; color: #721c24;">
                                ⚠️ <?php echo esc_html($t['error_message']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 10px;">
                            <span class="status-badge status-<?php echo $t['status']; ?>">
                                <?php echo $t['status']; ?>
                            </span>
                            <div class="transcript-actions">
                                <?php if ($t['status'] === 'completed'): ?>
                                <button class="btn btn-small btn-primary" onclick="viewTranscript(<?php echo $t['id']; ?>)">
                                    👁️ View
                                </button>
                                <button class="btn btn-small" onclick="downloadTranscript(<?php echo $t['id']; ?>)">
                                    💾 Download
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($t['status'] === 'processing'): ?>
                                <button class="btn btn-small" onclick="refreshStatus(<?php echo $t['id']; ?>)">
                                    🔄 Refresh
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- Transcript Modal -->
    <div id="transcriptModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Transcript</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div id="transcriptContainer" class="transcript-text">
                Loading...
            </div>
        </div>
    </div>

    <script>
        // File input handling
        const fileInput = document.getElementById('fileInput');
        const fileLabel = document.getElementById('fileLabel');
        const uploadForm = document.getElementById('uploadForm');
        const uploadSection = document.getElementById('uploadSection');
        
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                const sizeMB = (file.size / 1024 / 1024).toFixed(1);
                fileLabel.classList.add('has-file');
                fileLabel.innerHTML = `
                    <div style="font-size: 48px; margin-bottom: 10px;">✅</div>
                    <div style="font-size: 16px; font-weight: bold;">${file.name}</div>
                    <div style="font-size: 14px; color: #6c757d; margin-top: 5px;">${sizeMB} MB</div>
                `;
            }
        });
        
        // Drag and drop
        uploadSection.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragging');
        });
        
        uploadSection.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragging');
        });
        
        uploadSection.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragging');
            
            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                fileInput.dispatchEvent(new Event('change'));
            }
        });
        
        // Form submission
        uploadForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (!fileInput.files.length) {
                alert('Please select a file first');
                return;
            }
            
            const file = fileInput.files[0];
            const maxSize = 100 * 1024 * 1024; // 100MB
            
            if (file.size > maxSize) {
                alert('File is too large. Maximum size is 100MB.');
                return;
            }
            
            const uploadBtn = document.getElementById('uploadBtn');
            const progressBar = document.getElementById('progressBar');
            const progressFill = document.getElementById('progressFill');
            
            uploadBtn.disabled = true;
            uploadBtn.textContent = 'Uploading...';
            progressBar.style.display = 'block';
            
            const formData = new FormData();
            formData.append('file', file);
            
            try {
                const xhr = new XMLHttpRequest();
                
                // Progress tracking
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        progressFill.style.width = percent + '%';
                        progressFill.textContent = percent + '%';
                    }
                });
                
                xhr.addEventListener('load', function() {
                    if (xhr.status === 200) {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('Upload successful! Transcription started.');
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            alert('Error: ' + response.error);
                            resetUploadForm();
                        }
                    } else {
                        alert('Upload failed: HTTP ' + xhr.status);
                        resetUploadForm();
                    }
                });
                
                xhr.addEventListener('error', function() {
                    alert('Upload failed. Please try again.');
                    resetUploadForm();
                });
                
                xhr.open('POST', 'api/upload-general-transcription.php');
                xhr.send(formData);
                
            } catch (error) {
                alert('Error: ' + error.message);
                resetUploadForm();
            }
        });
        
        function resetUploadForm() {
            const uploadBtn = document.getElementById('uploadBtn');
            const progressBar = document.getElementById('progressBar');
            const progressFill = document.getElementById('progressFill');
            
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Start Transcription';
            progressBar.style.display = 'none';
            progressFill.style.width = '0%';
            progressFill.textContent = '0%';
        }
        
        // View transcript
        async function viewTranscript(id) {
            document.getElementById('transcriptModal').style.display = 'block';
            document.getElementById('transcriptContainer').textContent = 'Loading...';
            
            try {
                const response = await fetch(`api/get-general-transcript.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('modalTitle').textContent = data.filename;
                    document.getElementById('transcriptContainer').textContent = data.transcript;
                } else {
                    document.getElementById('transcriptContainer').textContent = 'Error: ' + data.error;
                }
            } catch (error) {
                document.getElementById('transcriptContainer').textContent = 'Error loading transcript';
            }
        }
        
        // Download transcript
        async function downloadTranscript(id) {
            try {
                const response = await fetch(`api/get-general-transcript.php?id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    const blob = new Blob([data.transcript], { type: 'text/plain' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = data.filename.replace(/\.[^/.]+$/, '') + '_transcript.txt';
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Error downloading transcript');
            }
        }
        
        // Refresh status
        function refreshStatus(id) {
            location.reload();
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('transcriptModal').style.display = 'none';
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('transcriptModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
        
        // Auto-refresh for processing transcripts
        <?php if (!empty($transcripts)): ?>
        const hasProcessing = <?php echo json_encode(array_filter($transcripts, fn($t) => $t['status'] === 'processing')); ?>.length > 0;
        if (hasProcessing) {
            setTimeout(() => location.reload(), 30000); // Refresh every 30 seconds
        }
        <?php endif; ?>
    </script>
</body>
</html>
