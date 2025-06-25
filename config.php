<?php
/**
 * ملف الإعدادات الأساسية لنظام بصمة الوجه
 * Face Recognition Attendance System Configuration
 * 
 * @version 2.0
 * @author Your Company
 * @created 2024
 */

// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تحديد المنطقة الزمنية
date_default_timezone_set('Asia/Riyadh');

// إعدادات الأخطاء
error_reporting(E_ALL);
ini_set('display_errors', 0); // إخفاء الأخطاء في الإنتاج
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// ضبط الترميز
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// إعدادات قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_NAME', 'face_attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// إعدادات التطبيق العامة
define('APP_NAME', 'نظام بصمة الوجه');
define('APP_VERSION', '2.0.0');
define('APP_URL', 'http://localhost/att');
define('ADMIN_EMAIL', 'admin@company.com');

// إعدادات المسارات
define('ROOT_PATH', __DIR__);
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
define('FACES_PATH', UPLOAD_PATH . 'faces/');
define('LOGS_PATH', ROOT_PATH . '/logs/');
define('ASSETS_PATH', ROOT_PATH . '/assets/');

// إعدادات الرفع
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/jpg', 'image/png']);

// إعدادات الأمان
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 15); // دقائق
define('SESSION_TIMEOUT', 8 * 60 * 60); // 8 ساعات بالثواني
define('CSRF_TOKEN_LENGTH', 32);

// إعدادات بصمة الوجه
define('FACE_CONFIDENCE_THRESHOLD', 0.75);
define('FACE_DETECTION_TIMEOUT', 30); // ثانية
define('MAX_FACE_IMAGES_PER_USER', 3);

// إعدادات التشفير
define('ENCRYPTION_KEY', 'your-32-character-secret-key-here');
define('HASH_ALGORITHM', 'sha256');

/**
 * فئة الاتصال بقاعدة البيانات
 */
class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $charset = DB_CHARSET;
    public $conn;
    private static $instance = null;

    private function __construct() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
            // ضبط المنطقة الزمنية لقاعدة البيانات
            $this->conn->exec("SET time_zone = '+03:00'");
            
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            die("خطأ في الاتصال بقاعدة البيانات. يرجى المحاولة لاحقاً.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }

    public function commit() {
        return $this->conn->commit();
    }

    public function rollback() {
        return $this->conn->rollback();
    }

    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }
}

/**
 * فئة إدارة الجلسات
 */
class SessionManager {
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_samesite', 'Strict');
            
            session_start();
            
            // فحص انتهاء صلاحية الجلسة
            if (isset($_SESSION['last_activity']) && 
                (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
                self::destroy();
                return false;
            }
            
            $_SESSION['last_activity'] = time();
            
            // تجديد معرف الجلسة دورياً
            if (!isset($_SESSION['created'])) {
                $_SESSION['created'] = time();
            } else if (time() - $_SESSION['created'] > 1800) { // 30 دقيقة
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
        }
        return true;
    }

    public static function destroy() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = array();
            
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            session_destroy();
        }
    }

    public static function isValid() {
        return isset($_SESSION['last_activity']) && 
               (time() - $_SESSION['last_activity'] <= SESSION_TIMEOUT);
    }
}

/**
 * فئة الأمان
 */
class Security {
    
    public static function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeInput'], $data);
        }
        
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }

    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validatePhone($phone) {
        return preg_match('/^[\+]?[0-9\-\(\)\s]+$/', $phone);
    }

    public static function validatePassword($password) {
        return strlen($password) >= PASSWORD_MIN_LENGTH && 
               preg_match('/^(?=.*[a-zA-Z])(?=.*\d)/', $password);
    }

    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT, [
            'cost' => 12
        ]);
    }

    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    public static function generateToken($length = CSRF_TOKEN_LENGTH) {
        return bin2hex(random_bytes($length / 2));
    }

    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = self::generateToken();
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && 
               hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function encryptData($data, $key = ENCRYPTION_KEY) {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decryptData($encryptedData, $key = ENCRYPTION_KEY) {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    public static function checkLoginAttempts($identifier) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) as attempts, MAX(created_at) as last_attempt 
             FROM activity_log 
             WHERE action = 'failed_login' AND description LIKE ? 
             AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute(["%$identifier%", LOGIN_LOCKOUT_TIME]);
        $result = $stmt->fetch();
        
        return $result['attempts'] < MAX_LOGIN_ATTEMPTS;
    }

    public static function logFailedLogin($identifier, $ip = null) {
        $ip = $ip ?: self::getClientIP();
        logActivity('system', 0, 'failed_login', "محاولة دخول فاشلة: $identifier", $ip);
    }

    public static function getClientIP() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, 
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public static function rateLimit($action, $max_attempts = 10, $time_window = 60) {
        $ip = self::getClientIP();
        $cache_key = "rate_limit_{$action}_{$ip}";
        
        // في بيئة الإنتاج، استخدم Redis أو Memcached
        // هنا نستخدم ملفات للتخزين المؤقت
        $cache_file = LOGS_PATH . "cache_{$cache_key}.json";
        
        $attempts = [];
        if (file_exists($cache_file)) {
            $attempts = json_decode(file_get_contents($cache_file), true) ?: [];
        }
        
        $current_time = time();
        $attempts = array_filter($attempts, function($timestamp) use ($current_time, $time_window) {
            return ($current_time - $timestamp) < $time_window;
        });
        
        if (count($attempts) >= $max_attempts) {
            return false;
        }
        
        $attempts[] = $current_time;
        file_put_contents($cache_file, json_encode($attempts));
        
        return true;
    }
}

/**
 * وظائف الملفات والرفع
 */
class FileManager {
    
    public static function validateImage($file) {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return 'خطأ في رفع الملف';
        }
        
        if ($file['size'] > MAX_FILE_SIZE) {
            return 'حجم الملف كبير جداً. الحد الأقصى ' . (MAX_FILE_SIZE / 1024 / 1024) . ' ميجابايت';
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, ALLOWED_IMAGE_TYPES)) {
            return 'نوع الملف غير مدعوم. يُسمح فقط بـ JPG, JPEG, PNG';
        }
        
        $image_info = getimagesize($file['tmp_name']);
        if ($image_info === false) {
            return 'الملف ليس صورة صحيحة';
        }
        
        return true;
    }

    public static function uploadImage($file, $directory = FACES_PATH, $prefix = '') {
        $validation = self::validateImage($file);
        if ($validation !== true) {
            return ['success' => false, 'message' => $validation];
        }
        
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $prefix . uniqid() . '_' . time() . '.' . $extension;
        $filepath = $directory . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
        }
        
        return ['success' => false, 'message' => 'فشل في حفظ الملف'];
    }

    public static function deleteFile($filepath) {
        if (file_exists($filepath)) {
            return unlink($filepath);
        }
        return false;
    }

    public static function processBase64Image($base64_string, $directory = FACES_PATH, $prefix = '') {
        if (!preg_match('/^data:image\/(\w+);base64,/', $base64_string, $type)) {
            return ['success' => false, 'message' => 'تنسيق الصورة غير صحيح'];
        }
        
        $image_data = substr($base64_string, strpos($base64_string, ',') + 1);
        $image_data = base64_decode($image_data);
        
        if ($image_data === false) {
            return ['success' => false, 'message' => 'فشل في فك تشفير الصورة'];
        }
        
        if (strlen($image_data) > MAX_FILE_SIZE) {
            return ['success' => false, 'message' => 'حجم الصورة كبير جداً'];
        }
        
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $extension = $type[1];
        if (!in_array('image/' . $extension, ALLOWED_IMAGE_TYPES)) {
            return ['success' => false, 'message' => 'نوع الصورة غير مدعوم'];
        }
        
        $filename = $prefix . uniqid() . '_' . time() . '.' . $extension;
        $filepath = $directory . $filename;
        
        if (file_put_contents($filepath, $image_data)) {
            return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
        }
        
        return ['success' => false, 'message' => 'فشل في حفظ الصورة'];
    }
}

/**
 * وظائف عامة للنظام
 */

function redirect($url) {
    if (!headers_sent()) {
        header("Location: " . $url);
        exit();
    } else {
        echo "<script>window.location.href='$url';</script>";
        exit();
    }
}

function checkAdminLogin() {
    if (!SessionManager::isValid() || !isset($_SESSION['admin_id'])) {
        redirect('admin_login.php');
    }
}

function checkEmployeeLogin() {
    if (!SessionManager::isValid() || !isset($_SESSION['employee_id'])) {
        redirect('employee_login.php');
    }
}

function logActivity($user_type, $user_id, $action, $description = '', $ip_address = null) {
    try {
        $db = Database::getInstance()->getConnection();
        $ip_address = $ip_address ?: Security::getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $session_id = session_id();
        
        $stmt = $db->prepare(
            "INSERT INTO activity_log (user_type, user_id, action, description, ip_address, user_agent, session_id) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$user_type, $user_id, $action, $description, $ip_address, $user_agent, $session_id]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

function getSystemSettings() {
    static $settings = null;
    
    if ($settings === null) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT setting_name, setting_value FROM system_settings");
            $stmt->execute();
            
            $settings = [];
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_name']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            error_log("Failed to get system settings: " . $e->getMessage());
            $settings = [];
        }
    }
    
    return $settings;
}

function getSetting($key, $default = null) {
    $settings = getSystemSettings();
    return $settings[$key] ?? $default;
}

function updateSetting($key, $value) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO system_settings (setting_name, setting_value) 
             VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        error_log("Failed to update setting: " . $e->getMessage());
        return false;
    }
}

function formatArabicDate($date, $include_time = false) {
    $months = [
        '01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'أبريل',
        '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس',
        '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر'
    ];
    
    $days = [
        'Sunday' => 'الأحد', 'Monday' => 'الاثنين', 'Tuesday' => 'الثلاثاء',
        'Wednesday' => 'الأربعاء', 'Thursday' => 'الخميس', 'Friday' => 'الجمعة',
        'Saturday' => 'السبت'
    ];
    
    $timestamp = strtotime($date);
    if ($timestamp === false) return $date;
    
    $day_name = $days[date('l', $timestamp)];
    $day = date('d', $timestamp);
    $month = $months[date('m', $timestamp)];
    $year = date('Y', $timestamp);
    
    $formatted = "$day_name $day $month $year";
    
    if ($include_time) {
        $time = formatArabicTime(date('H:i:s', $timestamp));
        $formatted .= " - $time";
    }
    
    return $formatted;
}

function formatArabicTime($time) {
    $timestamp = strtotime($time);
    if ($timestamp === false) return $time;
    
    $hour = date('h', $timestamp);
    $minute = date('i', $timestamp);
    $ampm = date('A', $timestamp) == 'AM' ? 'صباحاً' : 'مساءً';
    
    return "$hour:$minute $ampm";
}

function calculateWorkHours($check_in, $check_out) {
    if (!$check_in || !$check_out) return 0;
    
    $in_time = strtotime($check_in);
    $out_time = strtotime($check_out);
    
    if ($out_time <= $in_time) return 0;
    
    $diff = $out_time - $in_time;
    return round($diff / 3600, 2);
}

function isLate($check_in_time, $work_start_time, $tolerance_minutes = null) {
    $tolerance_minutes = $tolerance_minutes ?: getSetting('late_tolerance_minutes', 15);
    
    $check_in = strtotime($check_in_time);
    $start_time = strtotime($work_start_time);
    $tolerance = $tolerance_minutes * 60;
    
    return $check_in > ($start_time + $tolerance);
}

function getLateMinutes($check_in_time, $work_start_time) {
    $check_in = strtotime($check_in_time);
    $start_time = strtotime($work_start_time);
    
    if ($check_in <= $start_time) return 0;
    
    $diff = $check_in - $start_time;
    return round($diff / 60);
}

function isWorkingDay($date) {
    $timestamp = strtotime($date);
    $day_of_week = date('N', $timestamp); // 1 = Monday, 7 = Sunday
    
    // السبت والأحد عطلة (6 = Saturday, 7 = Sunday)
    if ($day_of_week >= 6) return false;
    
    // فحص العطل الرسمية
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM holidays WHERE holiday_date = ? AND is_active = 1");
        $stmt->execute([$date]);
        $result = $stmt->fetch();
        
        return $result['count'] == 0;
    } catch (Exception $e) {
        error_log("Failed to check holiday: " . $e->getMessage());
        return true; // افتراض يوم عمل في حالة الخطأ
    }
}

function isOnLeave($employee_id, $date) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) as count FROM leaves 
             WHERE employee_id = ? AND status = 'approved' 
             AND ? BETWEEN start_date AND end_date"
        );
        $stmt->execute([$employee_id, $date]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Failed to check leave: " . $e->getMessage());
        return false;
    }
}

function jsonResponse($success, $message, $data = null, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

function createNotification($user_type, $user_id, $title, $message, $type = 'info', $action_url = null) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO notifications (user_type, user_id, title, message, type, action_url) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        return $stmt->execute([$user_type, $user_id, $title, $message, $type, $action_url]);
    } catch (Exception $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}

function getUnreadNotifications($user_type, $user_id, $limit = 10) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM notifications 
             WHERE user_type = ? AND user_id = ? AND is_read = 0 
             ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$user_type, $user_id, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Failed to get notifications: " . $e->getMessage());
        return [];
    }
}

function markNotificationAsRead($notification_id) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?"
        );
        return $stmt->execute([$notification_id]);
    } catch (Exception $e) {
        error_log("Failed to mark notification as read: " . $e->getMessage());
        return false;
    }
}

// بدء الجلسة
SessionManager::start();

// إنشاء المجلدات المطلوبة
$required_dirs = [UPLOAD_PATH, FACES_PATH, LOGS_PATH];
foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// إعداد معالج الأخطاء المخصص
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $error_message = "Error [$errno]: $errstr in $errfile on line $errline";
    error_log($error_message);
    
    // في بيئة التطوير، يمكن عرض الأخطاء
    if (defined('DEBUG') && DEBUG === true) {
        echo "<div style='background:#ffebee;color:#c62828;padding:10px;margin:10px;border-radius:5px;'>";
        echo "<strong>خطأ في النظام:</strong> $errstr في الملف $errfile على السطر $errline";
        echo "</div>";
    }
    
    return true;
}

set_error_handler('customErrorHandler');

// معالج الاستثناءات غير المعالجة
function customExceptionHandler($exception) {
    $error_message = "Uncaught exception: " . $exception->getMessage() . 
                    " in " . $exception->getFile() . " on line " . $exception->getLine();
    error_log($error_message);
    
    if (defined('DEBUG') && DEBUG === true) {
        echo "<div style='background:#ffebee;color:#c62828;padding:10px;margin:10px;border-radius:5px;'>";
        echo "<strong>استثناء غير معالج:</strong> " . $exception->getMessage();
        echo "</div>";
    } else {
        echo "<h1>خطأ في الخادم</h1><p>حدث خطأ غير متوقع. يرجى المحاولة لاحقاً.</p>";
    }
}

set_exception_handler('customExceptionHandler');

?>