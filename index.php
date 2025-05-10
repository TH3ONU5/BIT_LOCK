<?php
// ------------------------------
// BITLOCK - Secure File Manager
// ------------------------------
// Version: 2.0
// GitHub: https://github.com/your-username/bitlock
// License: MIT

// Auto-installer for dependencies and setup
function install_dependencies()
{
  // Check if this is the first run
  $install_file = __DIR__ . '/.bitlock_installed';
  if (file_exists($install_file)) {
    return;
  }

  // Create uploads directory if it doesn't exist
  $uploads_dir = __DIR__ . '/uploads';
  if (!file_exists($uploads_dir)) {
    mkdir($uploads_dir, 0755, true);
    file_put_contents($uploads_dir . '/welcome.txt', "Welcome to BITLOCK!\nThis is your secure file management system.");
  }

  // Create config directory and files
  $config_dir = __DIR__ . '/config';
  if (!file_exists($config_dir)) {
    mkdir($config_dir, 0755, true);

    // Create default config
    $default_config = [
      'max_upload_size' => '50M',
      'allowed_extensions' => '*',
      'theme' => 'cyber-red',
      'session_lifetime' => 3600,
      'version' => '2.0'
    ];

    file_put_contents($config_dir . '/config.json', json_encode($default_config, JSON_PRETTY_PRINT));
  }

  // Mark as installed
  file_put_contents($install_file, date('Y-m-d H:i:s'));
}

// Run installer
install_dependencies();

// Error reporting for development
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Start session for flash messages
session_start();

// Load configuration
function load_config()
{
  $config_file = __DIR__ . '/config/config.json';
  if (file_exists($config_file)) {
    return json_decode(file_get_contents($config_file), true);
  }
  return [];
}

$config = load_config();

// Configuration
$root_directory = realpath('./uploads') . '/'; // Absolute path to prevent traversal

// Ensure uploads directory exists
if (!file_exists($root_directory) && !is_dir($root_directory)) {
  mkdir($root_directory, 0755, true);
}

// Create CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Function to sanitize paths to prevent directory traversal
function sanitize_path($path)
{
  return trim(str_replace(['..', '\\', '//'], '', $path), '/');
}

// Handle current directory path
$current_directory = '';
if (isset($_GET['dir'])) {
  $current_directory = sanitize_path($_GET['dir']);
}
$current_path = $root_directory . $current_directory;

// Validate the current path is within root directory
$real_current_path = realpath($current_path);
if ($real_current_path === false || strpos($real_current_path, $root_directory) !== 0) {
  $current_path = $root_directory;
  $current_directory = '';
}

// Flash messages function
function set_flash_message($type, $message)
{
  $_SESSION['flash'] = [
    'type' => $type,
    'message' => $message
  ];
}

// Get flash message
function get_flash_message()
{
  if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
  }
  return null;
}

// Recursive function to delete directories
function rmdir_recursive($dir)
{
  foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
    $file_path = "$dir/$file";
    is_dir($file_path) ? rmdir_recursive($file_path) : unlink($file_path);
  }
  return rmdir($dir);
}

// Get file extension icon
function get_file_icon($type, $ext = '')
{
  switch ($type) {
    case 'folder':
      return '<i class="bi bi-folder-fill"></i>';
    case 'parent':
      return '<i class="bi bi-arrow-up-circle-fill"></i>';
    case 'image':
      return '<i class="bi bi-image-fill"></i>';
    case 'document':
      return '<i class="bi bi-file-text-fill"></i>';
    case 'media':
      return '<i class="bi bi-film"></i>';
    case 'archive':
      return '<i class="bi bi-archive-fill"></i>';
    case 'code':
      return '<i class="bi bi-code-slash"></i>';
    default:
      return '<i class="bi bi-file-earmark-break-fill"></i>';
  }
}

// Get file type from extension
function get_file_type($ext)
{
  $ext = strtolower($ext);

  if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'ico'])) {
    return 'image';
  } elseif (in_array($ext, ['mp4', 'avi', 'mov', 'flv', 'wmv', 'mkv', 'webm', 'mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac'])) {
    return 'media';
  } elseif (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv'])) {
    return 'document';
  } elseif (in_array($ext, ['zip', 'rar', 'tar', 'gz', '7z'])) {
    return 'archive';
  } elseif (in_array($ext, ['js', 'php', 'html', 'css', 'json', 'xml', 'py', 'java', 'c', 'cpp', 'rb', 'go', 'ts'])) {
    return 'code';
  }

  return 'file';
}

// Get file list with parent directory if applicable
function get_file_list($directory, $current_dir)
{
  $files = [];

  // Add parent directory entry if not in root
  if (!empty($current_dir)) {
    $parent_path = dirname($current_dir);
    $parent_path = ($parent_path === '.') ? '' : $parent_path;

    $files[] = [
      'name' => '..',
      'path' => $parent_path,
      'type' => 'parent',
      'size' => '-',
      'modified' => '-',
      'perms' => '-'
    ];
  }

  if (is_dir($directory)) {
    foreach (array_diff(scandir($directory), ['.', '..']) as $item) {
      $path = "$directory/$item";
      $relativePath = basename($directory) === basename($GLOBALS['root_directory']) ? $item : trim($GLOBALS['current_directory'] . '/' . $item, '/');

      // Determine file type
      $type = 'file';
      if (is_dir($path)) {
        $type = 'folder';
      } else {
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        $type = get_file_type($ext);
      }

      // Get file size in human-readable format
      if (is_dir($path)) {
        $size = '-';
      } else {
        $bytes = filesize($path);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        $size = round($bytes, 2) . ' ' . $units[$pow];
      }

      // Get permissions
      $perms = decoct(fileperms($path) & 0777);

      $files[] = [
        'name' => $item,
        'path' => $relativePath,
        'type' => $type,
        'size' => $size,
        'modified' => date('Y-m-d H:i:s', filemtime($path)),
        'perms' => $perms
      ];
    }
  }

  return $files;
}

// Get system stats
function get_system_stats()
{
  $stats = [
    'storage_used' => '0 B',
    'storage_total' => '0 B',
    'uptime' => 'Unknown',
    'server_load' => 'N/A'
  ];

  // Total and free disk space
  if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
    $free = disk_free_space($GLOBALS['root_directory']);
    $total = disk_total_space($GLOBALS['root_directory']);
    $used = $total - $free;

    // Convert to human readable
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $total_pow = floor(($total ? log($total) : 0) / log(1024));
    $total_pow = min($total_pow, count($units) - 1);
    $total_formatted = round($total / pow(1024, $total_pow), 2) . ' ' . $units[$total_pow];

    $used_pow = floor(($used ? log($used) : 0) / log(1024));
    $used_pow = min($used_pow, count($units) - 1);
    $used_formatted = round($used / pow(1024, $used_pow), 2) . ' ' . $units[$used_pow];

    $stats['storage_used'] = $used_formatted;
    $stats['storage_total'] = $total_formatted;
    $stats['storage_percentage'] = round(($used / $total) * 100);
  }

  // Server uptime
  if (function_exists('shell_exec') && stristr(PHP_OS, 'Linux')) {
    $uptime = shell_exec('uptime -p');
    if ($uptime) {
      $stats['uptime'] = trim($uptime);
    }
  }

  // Server load
  if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    $stats['server_load'] = round($load[0], 2);
  }

  return $stats;
}

// Handle file operations
$operation_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Verify CSRF token
  if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    set_flash_message('error', 'Security token verification failed');
    header('Location: ' . $_SERVER['PHP_SELF'] . ($current_directory ? '?dir=' . urlencode($current_directory) : ''));
    exit;
  }

  if (isset($_POST['action'])) {
    $action = $_POST['action'];

    switch ($action) {
      case 'upload':
        if (isset($_FILES['files'])) {
          $uploaded_files = 0;
          $failed_files = 0;
          $error_msgs = [];

          // Handle single file or multiple files
          $file_array = is_array($_FILES['files']['name']) ? $_FILES['files'] : [
            'name' => [$_FILES['files']['name']],
            'type' => [$_FILES['files']['type']],
            'tmp_name' => [$_FILES['files']['tmp_name']],
            'error' => [$_FILES['files']['error']],
            'size' => [$_FILES['files']['size']]
          ];

          $total_files = count($file_array['name']);

          for ($i = 0; $i < $total_files; $i++) {
            if ($file_array['error'][$i] === UPLOAD_ERR_OK) {
              $file_name = basename($file_array['name'][$i]);
              $file_tmp = $file_array['tmp_name'][$i];

              if (move_uploaded_file($file_tmp, "$current_path/$file_name")) {
                $uploaded_files++;
              } else {
                $error_msgs[] = "$file_name (upload failed)";
                $failed_files++;
              }
            } else {
              $error_msgs[] = "File #$i (upload error code: " . $file_array['error'][$i] . ")";
              $failed_files++;
            }
          }

          if ($uploaded_files > 0) {
            set_flash_message('success', "Uploaded: $uploaded_files, Failed: $failed_files");
          } else {
            set_flash_message('error', "Upload failed. Errors: " . implode(', ', $error_msgs));
          }
        }
        break;

      case 'newfolder':
        if (!empty($_POST['foldername']) && preg_match('/^[a-zA-Z0-9_\-\s]+$/', $_POST['foldername'])) {
          $new_folder = $current_path . '/' . sanitize_path($_POST['foldername']);
          if (!file_exists($new_folder)) {
            if (mkdir($new_folder, 0755)) {
              set_flash_message('success', "Folder created successfully");
            } else {
              set_flash_message('error', "Could not create folder");
            }
          } else {
            set_flash_message('error', "Folder already exists");
          }
        } else {
          set_flash_message('error', "Invalid folder name");
        }
        break;

      case 'delete':
        if (!empty($_POST['path'])) {
          $item_path = $current_path . '/' . sanitize_path($_POST['path']);
          if (is_dir($item_path)) {
            if (rmdir_recursive($item_path)) {
              set_flash_message('success', "Folder deleted successfully");
            } else {
              set_flash_message('error', "Failed to delete folder");
            }
          } elseif (file_exists($item_path)) {
            if (unlink($item_path)) {
              set_flash_message('success', "File deleted successfully");
            } else {
              set_flash_message('error', "Failed to delete file");
            }
          } else {
            set_flash_message('error', "Item not found");
          }
        }
        break;

      case 'rename':
        if (
          !empty($_POST['oldname']) && !empty($_POST['newname']) &&
          preg_match('/^[a-zA-Z0-9_\-\s\.]+$/', $_POST['newname'])
        ) {
          $old_path = $current_path . '/' . sanitize_path($_POST['oldname']);
          $new_path = $current_path . '/' . sanitize_path($_POST['newname']);

          if (file_exists($old_path) && !file_exists($new_path)) {
            if (rename($old_path, $new_path)) {
              set_flash_message('success', "Renamed successfully");
            } else {
              set_flash_message('error', "Rename failed");
            }
          } else {
            set_flash_message('error', "Rename failed: File already exists or source not found");
          }
        } else {
          set_flash_message('error', "Invalid file name");
        }
        break;
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF'] . ($current_directory ? '?dir=' . urlencode($current_directory) : ''));
    exit;
  }
}

// Handle file download
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['file'])) {
  $file = sanitize_path($_GET['file']);
  $full_path = realpath($root_directory . $file);

  if ($full_path !== false && strpos($full_path, $root_directory) === 0 && is_file($full_path)) {
    // Set headers
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($full_path) . '"');
    header('Content-Length: ' . filesize($full_path));
    header('Pragma: public');

    // Clear output buffer
    ob_clean();
    flush();

    // Read file
    readfile($full_path);
    exit;
  } else {
    set_flash_message('error', 'Invalid file requested');
    header('Location: ' . $_SERVER['PHP_SELF'] . ($current_directory ? '?dir=' . urlencode($current_directory) : ''));
    exit;
  }
}

// Get file list for current directory
$files = get_file_list($current_path, $current_directory);

// Get system stats
$system_stats = get_system_stats();

// Get flash message
$flash = get_flash_message();

// Version info
$version = isset($config['version']) ? $config['version'] : '2.0';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BITLOCK | Secure File Interface</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    /* Advanced Cyberpunk Hacker Interface - Enhanced */
    :root {
      /* Updated color palette for more intimidating look */
      --neon-red: rgb(255, 0, 50);
      --neon-red-glow: rgba(255, 0, 50, 0.7);
      --neon-red-dim: rgba(255, 0, 50, 0.3);
      --neon-green: #00ff4c;
      --neon-green-glow: rgba(0, 255, 76, 0.7);
      --matrix-green: #03ff03;
      --matrix-green-glow: rgba(3, 255, 3, 0.7);
      --dark-bg: #050505;
      --darker-bg: #020202;
      --panel-bg: rgba(10, 10, 12, 0.95);
      --text-color: #e0e0e0;
      --highlight: #ff004c;
      --terminal-font: 'Courier New', monospace;
      --grid-line: rgba(255, 0, 50, 0.15);
      --glitch-color-1: rgba(255, 0, 76, 0.75);
      --glitch-color-2: rgba(0, 255, 76, 0.75);
      --glitch-color-3: rgba(0, 60, 255, 0.75);
      --animation-speed: 0.25s;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: var(--terminal-font);
    }

    body {
      background-color: var(--dark-bg);
      color: var(--text-color);
      overflow-x: hidden;
      min-height: 100vh;
      position: relative;
      line-height: 1.5;
    }

    /* Enhanced matrix digital rain effect */
    body::before {
      content: "";
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background-image:
        radial-gradient(circle at 20% 30%, rgba(255, 0, 50, 0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(255, 0, 50, 0.1) 0%, transparent 40%);
      background-color: var(--dark-bg);
      opacity: 0.9;
      z-index: -2;
      will-change: transform;
    }

    canvas {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      z-index: -1;
      opacity: 0.4;
      /* More subtle and professional */
    }

    a {
      color: var(--neon-red);
      text-decoration: none;
    }

    /* Enhanced multi-layered background with animated glow */
    .background-glow {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(135deg,
          rgba(255, 0, 50, 0.05) 0%,
          transparent 50%,
          rgba(255, 0, 50, 0.05) 100%);
      mix-blend-mode: screen;
      pointer-events: none;
      z-index: -1;
      opacity: 0.5;
      animation: backgroundPulse 8s infinite alternate ease-in-out;
    }

    @keyframes backgroundPulse {
      0% {
        opacity: 0.4;
        background-position: 0% 0%;
      }

      50% {
        opacity: 0.6;
        background-position: 100% 100%;
      }

      100% {
        opacity: 0.4;
        background-position: 0% 0%;
      }
    }

    /* Digital noise overlay */
    .digital-noise {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.05'/%3E%3C/svg%3E");
      pointer-events: none;
      opacity: 0.12;
      z-index: 9999;
      mix-blend-mode: overlay;
    }

    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 20px;
      position: relative;
      min-height: 100vh;
    }

    header {
      margin-bottom: 30px;
      position: relative;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 15px;
      border-bottom: 1px solid var(--neon-red);
    }

    /* Enhanced logo with scanline effect */
    .logo {
      font-size: 28px;
      color: var(--neon-red);
      font-weight: bold;
      text-shadow:
        0 0 5px var(--neon-red-glow),
        0 0 10px var(--neon-red-glow);
      letter-spacing: 2px;
      position: relative;
      padding: 5px 0;
      animation: logoPulse 4s infinite alternate;
    }

    @keyframes logoPulse {

      0%,
      100% {
        text-shadow:
          0 0 5px var(--neon-red-glow),
          0 0 10px var(--neon-red-glow);
      }

      50% {
        text-shadow:
          0 0 5px var(--neon-red-glow),
          0 0 10px var(--neon-red-glow),
          0 0 20px var(--neon-red-glow);
      }
    }

    /* Enhanced panel with better glass effect and hover animation */
    .panel {
      background: var(--panel-bg);
      border-radius: 6px;
      border: 1px solid rgba(255, 0, 50, 0.4);
      box-shadow:
        0 0 15px rgba(255, 0, 50, 0.15),
        inset 0 0 20px rgba(0, 0, 0, 0.4);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      overflow: hidden;
      transition: all var(--animation-speed) ease;
      margin-bottom: 25px;
      position: relative;
    }

    .panel::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-image:
        linear-gradient(90deg, rgba(255, 0, 50, 0.03) 1px, transparent 1px),
        linear-gradient(rgba(255, 0, 50, 0.03) 1px, transparent 1px);
      background-size: 20px 20px;
      pointer-events: none;
      opacity: 0.4;
      z-index: 0;
    }

    .panel:hover {
      border-color: var(--neon-red);
      box-shadow:
        0 0 20px rgba(255, 0, 50, 0.25),
        inset 0 0 30px rgba(255, 0, 50, 0.05);
    }

    .panel-header {
      background: rgba(5, 5, 8, 0.95);
      padding: 15px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid rgba(255, 0, 50, 0.3);
      position: relative;
      z-index: 1;
    }

    .panel-title {
      color: var(--neon-red);
      font-size: 16px;
      font-weight: bold;
      letter-spacing: 1px;
      display: flex;
      align-items: center;
      text-transform: uppercase;
    }

    .panel-title::before {
      content: ">";
      margin-right: 10px;
      color: var(--neon-red);
      font-weight: bold;
      animation: cursorBlink 1.2s infinite;
    }

    @keyframes cursorBlink {

      0%,
      49% {
        opacity: 1;
      }

      50%,
      100% {
        opacity: 0;
      }
    }

    .panel-content {
      padding: 25px;
      position: relative;
      z-index: 1;
    }

    .actions {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    /* Enhanced system status with animated indicator */
    .system-status {
      font-size: 12px;
      color: var(--neon-red);
      display: flex;
      align-items: center;
      gap: 8px;
      text-transform: uppercase;
      letter-spacing: 1px;
      position: relative;
    }

    .status-indicator {
      width: 8px;
      height: 8px;
      background-color: var(--neon-red);
      border-radius: 50%;
      display: inline-block;
      animation: pulse 2s infinite;
      position: relative;
    }

    .status-indicator::after {
      content: "";
      position: absolute;
      top: -4px;
      left: -4px;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      border: 1px solid var(--neon-red);
      opacity: 0;
      animation: ripple 2s infinite;
    }

    @keyframes ripple {
      0% {
        transform: scale(0.5);
        opacity: 0;
      }

      40% {
        opacity: 0.5;
      }

      100% {
        transform: scale(1.2);
        opacity: 0;
      }
    }

    @keyframes pulse {
      0% {
        opacity: 1;
        box-shadow: 0 0 0 0 var(--neon-red-glow);
      }

      50% {
        opacity: 0.7;
        box-shadow: 0 0 0 5px rgba(255, 0, 50, 0.2);
      }

      100% {
        opacity: 1;
        box-shadow: 0 0 0 0 rgba(255, 0, 50, 0);
      }
    }

    /* Improved responsive grid */
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 25px;
    }

    /* Enhanced file list styling */
    .file-list {
      height: 500px;
      overflow-y: auto;
      scrollbar-width: thin;
      scrollbar-color: var(--neon-red) var(--darker-bg);
    }

    /* Custom scrollbar */
    .file-list::-webkit-scrollbar {
      width: 6px;
    }

    .file-list::-webkit-scrollbar-track {
      background: var(--darker-bg);
      border-radius: 3px;
    }

    .file-list::-webkit-scrollbar-thumb {
      background: var(--neon-red);
      border-radius: 3px;
    }

    .file-list-header {
      display: grid;
      grid-template-columns: minmax(150px, 1fr) 100px 120px 80px;
      padding: 0 15px 12px;
      border-bottom: 1px solid rgba(255, 0, 50, 0.3);
      font-size: 14px;
      color: var(--neon-red);
      position: sticky;
      top: 0;
      background: rgba(0, 0, 0, 0.95);
      z-index: 10;
      backdrop-filter: blur(5px);
      -webkit-backdrop-filter: blur(5px);
      padding: 1em;
    }

    .file-items {
      list-style-type: none;
    }

    .file-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      border-radius: 4px;
      overflow: hidden;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .file-table thead th {
      background-color: rgba(10, 10, 15, 0.9);
      padding: 12px 15px;
      text-align: left;
      font-weight: 500;
      font-size: 14px;
      color: var(--neon-red);
      text-transform: uppercase;
      letter-spacing: 1px;
      border-bottom: 1px solid rgba(255, 0, 50, 0.3);
    }

    .file-table tbody tr {
      transition: background-color var(--animation-speed) ease;
      position: relative;
    }

    .file-table tbody tr:nth-child(odd) {
      background-color: rgba(10, 10, 15, 0.4);
    }

    .file-table tbody tr:hover {
      background-color: rgba(255, 0, 50, 0.1);
    }

    .file-table tbody td {
      padding: 12px 15px;
      border-bottom: 1px solid rgba(255, 0, 50, 0.08);
      font-size: 14px;
    }

    .file-name {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .file-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      border-radius: 4px;
      background-color: rgba(255, 0, 50, 0.1);
      border: 1px solid rgba(255, 0, 50, 0.2);
    }

    .file-icon i {
      color: var(--neon-red);
      font-size: 16px;
    }

    .file-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      opacity: 0.7;
      transition: opacity var(--animation-speed) ease;
    }

    tr:hover .file-actions {
      opacity: 1;
    }

    .action-btn {
      background: none;
      border: none;
      color: var(--neon-red);
      cursor: pointer;
      font-size: 14px;
      padding: 3px 6px;
      border-radius: 3px;
      transition: all var(--animation-speed) ease;
    }

    .action-btn:hover {
      background-color: rgba(255, 0, 50, 0.15);
      color: #fff;
      text-shadow: 0 0 5px var(--neon-red-glow);
    }

    /* Enhanced buttons with satisfying animations */
    .btn {
      background-color: rgba(10, 10, 15, 0.9);
      color: var(--neon-red);
      border: 1px solid var(--neon-red);
      padding: 8px 16px;
      font-size: 14px;
      border-radius: 4px;
      cursor: pointer;
      transition: all var(--animation-speed) ease;
      position: relative;
      overflow: hidden;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-family: var(--terminal-font);
      text-transform: uppercase;
      letter-spacing: 1px;
      font-weight: bold;
      min-width: 120px;
    }

    .btn::before {
      content: "";
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg,
          transparent,
          rgba(255, 0, 50, 0.2),
          transparent);
      transition: left 0.5s ease;
      z-index: 1;
    }

    .btn:hover {
      background-color: rgba(255, 0, 50, 0.15);
      box-shadow: 0 0 10px rgba(255, 0, 50, 0.3);
      transform: translateY(-2px);
    }

    .btn:hover::before {
      left: 100%;
    }

    .btn:active {
      transform: translateY(1px);
    }

    .btn-icon {
      margin-right: 5px;
    }

    /* Forms styling */
    .form-group {
      margin-bottom: 20px;
    }

    .form-label {
      display: block;
      margin-bottom: 8px;
      color: var(--neon-red);
      font-size: 14px;
      letter-spacing: 1px;
    }

    .form-control {
      width: 100%;
      padding: 10px 12px;
      background-color: rgba(10, 10, 15, 0.8);
      border: 1px solid rgba(255, 0, 50, 0.3);
      border-radius: 4px;
      color: var(--text-color);
      font-family: var(--terminal-font);
      font-size: 14px;
      transition: all var(--animation-speed) ease;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--neon-red);
      box-shadow: 0 0 0 2px rgba(255, 0, 50, 0.2);
    }

    .form-control::placeholder {
      color: rgba(224, 224, 224, 0.5);
    }

    /* Alert messages with animation */
    .alert {
      padding: 15px;
      border-radius: 4px;
      margin-bottom: 20px;
      position: relative;
      animation: alertFadeIn 0.3s ease forwards;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    @keyframes alertFadeIn {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .alert-success {
      background-color: rgba(0, 255, 76, 0.1);
      border: 1px solid rgba(0, 255, 76, 0.3);
      color: var(--neon-green);
    }

    .alert-error {
      background-color: rgba(255, 0, 50, 0.1);
      border: 1px solid rgba(255, 0, 50, 0.3);
      color: var(--neon-red);
    }

    /* System stats cards */
    .stats-card {
      border: 1px solid rgba(255, 0, 50, 0.3);
      border-radius: 4px;
      padding: 15px;
      background-color: rgba(10, 10, 15, 0.8);
      margin-bottom: 15px;
      transition: all var(--animation-speed) ease;
    }

    .stats-card:hover {
      border-color: var(--neon-red);
      box-shadow: 0 0 15px rgba(255, 0, 50, 0.15);
    }

    .stats-title {
      font-size: 12px;
      color: var(--neon-red);
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 8px;
    }

    .stats-value {
      font-size: 18px;
      color: var(--text-color);
      font-weight: bold;
    }

    .progress-bar {
      height: 5px;
      background-color: rgba(255, 255, 255, 0.1);
      border-radius: 2px;
      overflow: hidden;
      margin-top: 10px;
    }

    .progress-fill {
      height: 100%;
      background-color: var(--neon-red);
      border-radius: 2px;
      transition: width 0.5s ease;
    }

    /* Modal styling */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0, 0, 0, 0.8);
      backdrop-filter: blur(5px);
      animation: modalFadeIn 0.3s ease forwards;
    }

    @keyframes modalFadeIn {
      from {
        opacity: 0;
      }

      to {
        opacity: 1;
      }
    }

    .modal-content {
      background-color: var(--panel-bg);
      margin: 10% auto;
      padding: 0;
      width: 400px;
      max-width: 90%;
      border-radius: 6px;
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
      position: relative;
      border: 1px solid var(--neon-red);
      animation: modalContentSlideIn 0.3s ease forwards;
    }

    @keyframes modalContentSlideIn {
      from {
        transform: translateY(-50px);
        opacity: 0;
      }

      to {
        transform: translateY(0);
        opacity: 1;
      }
    }

    .modal-header {
      padding: 15px;
      background-color: rgba(5, 5, 8, 0.95);
      border-bottom: 1px solid rgba(255, 0, 50, 0.3);
      border-top-left-radius: 6px;
      border-top-right-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .modal-title {
      color: var(--neon-red);
      font-size: 16px;
      font-weight: bold;
      letter-spacing: 1px;
      text-transform: uppercase;
    }

    .modal-body {
      padding: 20px;
    }

    .modal-footer {
      padding: 15px;
      border-top: 1px solid rgba(255, 0, 50, 0.3);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .close {
      color: var(--neon-red);
      float: right;
      font-size: 20px;
      font-weight: bold;
      cursor: pointer;
      transition: all var(--animation-speed) ease;
    }

    .close:hover {
      color: var(--text-color);
      text-shadow: 0 0 5px var(--neon-red-glow);
    }

    /* Breadcrumb navigation */
    .breadcrumb {
      display: flex;
      flex-wrap: wrap;
      padding: 10px 0;
      margin-bottom: 20px;
      list-style: none;
      align-items: center;
    }

    .breadcrumb-item {
      display: flex;
      align-items: center;
    }

    .breadcrumb-item+.breadcrumb-item::before {
      content: ">";
      padding: 0 10px;
      color: var(--neon-red);
      font-weight: bold;
    }

    .breadcrumb-item a {
      color: var(--text-color);
      text-decoration: none;
      transition: all var(--animation-speed) ease;
    }

    .breadcrumb-item a:hover {
      color: var(--neon-red);
      text-shadow: 0 0 5px var(--neon-red-glow);
    }

    .breadcrumb-item.active {
      color: var(--neon-red);
    }

    /* Footer styling */
    footer {
      margin-top: 30px;
      padding: 20px 0;
      text-align: center;
      border-top: 1px solid rgba(255, 0, 50, 0.3);
      font-size: 12px;
      color: rgba(224, 224, 224, 0.7);
    }

    .version {
      color: var(--neon-red);
      font-weight: bold;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {

      .file-table thead th:nth-child(3),
      .file-table tbody td:nth-child(3) {
        display: none;
      }

      .grid {
        grid-template-columns: 1fr;
      }

      .panel-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }

      .actions {
        width: 100%;
        justify-content: space-between;
      }

      .breadcrumb {
        padding: 5px 0;
      }
    }

    @media (max-width: 576px) {

      .file-table thead th:nth-child(2),
      .file-table tbody td:nth-child(2) {
        display: none;
      }

      .breadcrumb-item {
        font-size: 12px;
      }

      .btn {
        padding: 6px 12px;
        font-size: 12px;
        min-width: 100px;
      }
    }
  </style>
</head>

<body>
  <!-- Digital noise overlay -->
  <div class="digital-noise"></div>

  <!-- Background glow effect -->
  <div class="background-glow"></div>

  <div class="container">
    <header>
      <div class="logo">BITLOCK</div>
      <div class="system-status">
        <span class="status-indicator"></span>
        SYSTEM OPERATIONAL
      </div>
    </header>

    <?php if ($flash): ?>
      <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="bi bi-<?php echo $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?>"></i>
        <?php echo $flash['message']; ?>
      </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">SYSTEM NAVIGATION</div>
      </div>

      <div class="panel-content">
        <!-- Breadcrumb Navigation -->
        <nav>
          <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo $_SERVER['PHP_SELF']; ?>">Root</a></li>
            <?php
            $path_parts = $current_directory ? explode('/', $current_directory) : [];
            $bread_path = '';

            foreach ($path_parts as $idx => $part) {
              $bread_path .= $part;
              echo '<li class="breadcrumb-item ' . ($idx === count($path_parts) - 1 ? 'active' : '') . '">';

              if ($idx === count($path_parts) - 1) {
                echo htmlspecialchars($part);
              } else {
                echo '<a href="' . $_SERVER['PHP_SELF'] . '?dir=' . urlencode($bread_path) . '">' . htmlspecialchars($part) . '</a>';
              }

              echo '</li>';
              $bread_path .= '/';
            }
            ?>
          </ol>
        </nav>

        <!-- Action Buttons -->
        <div class="actions" style="margin-bottom: 20px;">
          <button class="btn" onclick="openModal('uploadModal')">
            <i class="bi bi-upload"></i> Upload
          </button>

          <button class="btn" onclick="openModal('folderModal')">
            <i class="bi bi-folder-plus"></i> New Folder
          </button>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">FILE SYSTEM</div>
      </div>

      <div class="panel-content">
        <div class="file-list">
          <table class="file-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Size</th>
                <th>Modified</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($files as $file): ?>
                <tr>
                  <td>
                    <div class="file-name">
                      <div class="file-icon">
                        <?php echo get_file_icon($file['type']); ?>
                      </div>

                      <?php if ($file['type'] === 'folder' || $file['type'] === 'parent'): ?>
                        <a href="<?php echo $_SERVER['PHP_SELF'] . '?dir=' . urlencode($file['path']); ?>">
                          <?php echo htmlspecialchars($file['name']); ?>
                        </a>
                      <?php else: ?>
                        <span><?php echo htmlspecialchars($file['name']); ?></span>
                      <?php endif; ?>
                    </div>
                  </td>

                  <td><?php echo $file['size']; ?></td>
                  <td><?php echo $file['modified']; ?></td>

                  <td>
                    <div class="file-actions">
                      <?php if ($file['type'] !== 'parent'): ?>

                        <?php if ($file['type'] !== 'folder'): ?>
                          <a href="<?php echo $_SERVER['PHP_SELF'] . '?action=download&file=' . urlencode($current_directory ? $current_directory . '/' . $file['name'] : $file['name']); ?>" class="action-btn" title="Download">
                            <i class="bi bi-download"></i>
                          </a>
                        <?php endif; ?>

                        <button class="action-btn" onclick="openRenameModal('<?php echo addslashes($file['name']); ?>')" title="Rename">
                          <i class="bi bi-pencil"></i>
                        </button>

                        <button class="action-btn" onclick="confirmDelete('<?php echo addslashes($file['name']); ?>')" title="Delete">
                          <i class="bi bi-trash"></i>
                        </button>

                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (empty($files)): ?>
                <tr>
                  <td colspan="4" style="text-align: center; padding: 30px;">
                    <i class="bi bi-folder2-open" style="font-size: 24px; color: var(--neon-red);"></i>
                    <p style="margin-top: 10px;">This folder is empty</p>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="grid">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">SYSTEM STATS</div>
        </div>

        <div class="panel-content">
          <div class="stats-card">
            <div class="stats-title">Storage Usage</div>
            <div class="stats-value"><?php echo $system_stats['storage_used']; ?> / <?php echo $system_stats['storage_total']; ?></div>
            <div class="progress-bar">
              <div class="progress-fill" style="width: <?php echo $system_stats['storage_percentage']; ?>%;"></div>
            </div>
          </div>

          <div class="stats-card">
            <div class="stats-title">Server Uptime</div>
            <div class="stats-value"><?php echo $system_stats['uptime']; ?></div>
          </div>

          <div class="stats-card">
            <div class="stats-title">System Load</div>
            <div class="stats-value"><?php echo $system_stats['server_load']; ?></div>
          </div>
        </div>
      </div>
    </div>

    <footer>
      <p>BITLOCK Secure File Interface <span class="version">v<?php echo $version; ?></span> | &copy; <?php echo date('Y'); ?></p>
    </footer>
  </div>

  <!-- Upload Modal -->
  <div id="uploadModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title">Upload Files</div>
        <span class="close" onclick="closeModal('uploadModal')">&times;</span>
      </div>

      <div class="modal-body">
        <form action="<?php echo $_SERVER['PHP_SELF'] . ($current_directory ? '?dir=' . urlencode($current_directory) : ''); ?>" method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="action" value="upload">

          <div class="form-group">
            <label for="files" class="form-label">Select Files</label>
            <input type="file" name="files[]" id="files" class="form-control" multiple>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn" onclick="closeModal('uploadModal')">Cancel</button>
            <button type="submit" class="btn">Upload</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- New Folder Modal -->
  <div id="folderModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title">Create New Folder</div>
        <span class="close" onclick="closeModal('folderModal')">&times;</span>
      </div>

      <div class="modal-body">
        <form action="<?php echo $_SERVER['PHP_SELF'] . ($current_directory ? '?dir=' . urlencode($current_directory) : ''); ?>" method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="action" value="newfolder">

          <div class="form-group">
            <label for="foldername" class="form-label">Folder Name</label>
            <input type="text" name="foldername" id="foldername" class="form-control" required placeholder="Enter folder name">
          </div>

          <div class="modal-footer">
            <button type="button" class="btn" onclick="closeModal('folderModal')">Cancel</button>
            <button type="submit" class="btn">Create</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Rename Modal -->
  <div id="renameModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title">Rename Item</div>
        <span class="close" onclick="closeModal('renameModal')">&times;</span>
      </div>

      <div class="modal-body">
        <form action="<?php echo $_SERVER['PHP_SELF'] . ($current_directory ? '?dir=' . urlencode($current_directory) : ''); ?>" method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="action" value="rename">
          <input type="hidden" name="oldname" id="oldname" value="">

          <div class="form-group">
            <label for="newname" class="form-label">New Name</label>
            <input type="text" name="newname" id="newname" class="form-control" required>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn" onclick="closeModal('renameModal')">Cancel</button>
            <button type="submit" class="btn">Rename</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <div id="deleteModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title">Confirm Delete</div>
        <span class="close" onclick="closeModal('deleteModal')">&times;</span>
      </div>

      <div class="modal-body">
        <p>Are you sure you want to delete <strong id="deleteItemName"></strong>?</p>
        <p style="color: var(--neon-red);">This action cannot be undone.</p>

        <form action="<?php echo $_SERVER['PHP_SELF'] . ($current_directory ? '?dir=' . urlencode($current_directory) : ''); ?>" method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="path" id="deletePath" value="">

          <div class="modal-footer">
            <button type="button" class="btn" onclick="closeModal('deleteModal')">Cancel</button>
            <button type="submit" class="btn">Delete</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    // Matrix digital rain effect
    const canvas = document.createElement('canvas');
    document.body.appendChild(canvas);
    const ctx = canvas.getContext('2d');

    // Set canvas size
    function resizeCanvas() {
      canvas.width = window.innerWidth;
      canvas.height = window.innerHeight;
    }

    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    // Characters for matrix effect
    const chars = '01ABCDEFGHIJKLMNOPQRSTUVWXYZ#$%^&*(){}[]<>~`|\\';
    const fontSize = 14;
    const columns = Math.floor(canvas.width / fontSize);

    // Array to track the y position of each column
    const drops = Array(columns).fill(0);

    // Function to draw the matrix effect
    function drawMatrix() {
      // Semi-transparent black to create trail effect
      ctx.fillStyle = 'rgba(0, 0, 0, 0.05)';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      // Set the color and font
      ctx.fillStyle = 'rgba(255, 0, 50, 0.8)';
      ctx.font = `${fontSize}px monospace`;

      // Draw characters
      for (let i = 0; i < columns; i++) {
        // Get a random character
        const char = chars[Math.floor(Math.random() * chars.length)];

        // Draw the character
        ctx.fillText(char, i * fontSize, drops[i] * fontSize);

        // Reset drop position if it's at the bottom or randomly
        if (drops[i] * fontSize > canvas.height && Math.random() > 0.975) {
          drops[i] = 0;
        }

        // Move the drop down
        drops[i]++;
      }
    }

    // Run the matrix effect
    setInterval(drawMatrix, 50);

    // Modal functions
    function openModal(modalId) {
      document.getElementById(modalId).style.display = 'block';
    }

    function closeModal(modalId) {
      document.getElementById(modalId).style.display = 'none';
    }

    function openRenameModal(filename) {
      document.getElementById('oldname').value = filename;
      document.getElementById('newname').value = filename;
      openModal('renameModal');
    }

    function confirmDelete(filename) {
      document.getElementById('deleteItemName').textContent = filename;
      document.getElementById('deletePath').value = filename;
      openModal('deleteModal');
    }

    // Close modal if user clicks outside it
    window.onclick = function(event) {
      if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
      }
    };

    // Auto-close alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(function(alert) {
        setTimeout(function() {
          alert.style.opacity = '0';
          alert.style.transform = 'translateY(-10px)';
          setTimeout(function() {
            alert.style.display = 'none';
          }, 300);
        }, 5000);
      });
    });
  </script>
</body>

</html>