<?php
/**
 * ملف الإعدادات الأساسية لنظام بصمة الوجه
 * Face Recognition Attendance System Configuration
 * 
 * @version 3.0
 * @author Your Company
 * @created 2024
 * @updated 2025
 */

// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تحديد المنطقة الزمنية
date_default_timezone_set('Asia/Riyadh');

// إعدادات الأخطاء للتطوير والإنتاج
if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    define('DEBUG', true);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    define('DEBUG', false);
}

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// ضبط الترميز
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=UTF-8');

// إعدادات قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_NAME', 'face_attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// إعدادات التطبيق العامة
define('APP_NAME', 'نظام بصمة الوجه');
define('APP_VERSION', '3.0.0');
define('APP_URL', 'http://localhost/face_attendance');
define('ADMIN_EMAIL', 'admin@company.com');
define('COMPANY_NAME', 'شركتي');

// إعدادات المسارات
define('ROOT_PATH', __DIR__);
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
define('FACES_PATH', UPLOAD_PATH . 'faces/');
define('ATTENDANCE_PATH', UPLOAD_PATH . 'attendance/');
define('LOGS_PATH', ROOT_PATH . '/logs/');
define('ASSETS_PATH', ROOT_PATH . '/assets/');
define('TEMP_PATH', ROOT_PATH . '/temp/');

// إعدادات الرفع
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']);
define('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp']);

// إعدادات الأمان
define('PASSWORD_MIN_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 15); // دقائق
define('SESSION_TIMEOUT', 8 * 60 * 60); // 8 ساعات بالثواني
define('CSRF_TOKEN_LENGTH', 32);
define('REMEMBER_ME_DURATION', 7 * 24 * 60 * 60); // أسبوع للموظفين
define('ADMIN_REMEMBER_DURATION', 30 * 24 * 60 * 60); // شهر للإدمن

// إعدادات بصمة الوجه
define('FACE_CONFIDENCE_THRESHOLD', 0.75);
define('FACE_DETECTION_TIMEOUT', 30); // ثانية
define('MAX_FACE_IMAGES_PER_USER', 5);
define('FACE_IMAGE_MAX_SIZE', 2 * 1024 * 1024); // 2MB
define('FACE_MATCH_THRESHOLD_LOW', 0.6);
define('FACE_MATCH_THRESHOLD_MEDIUM', 0.7);
define('FACE_MATCH_THRESHOLD_HIGH', 0.75);
define('FACE_MATCH_THRESHOLD_VERY_HIGH', 0.8);
define('FACE_MATCH_THRESHOLD_MAX', 0.9);

// إعدادات الحضور والعمل
define('DEFAULT_WORK_START_TIME', '08:00:00');
define('DEFAULT_WORK_END_TIME', '17:00:00');
define('DEFAULT_ATTENDANCE_START_TIME', '07:30:00');
define('DEFAULT_ATTENDANCE_END_TIME', '09:00:00');
define('DEFAULT_CHECKOUT_START_TIME', '16:30:00');
define('DEFAULT_CHECKOUT_END_TIME', '18:00:00');
define('DEFAULT_LATE_TOLERANCE_MINUTES', 15);
define('DEFAULT_MINIMUM_WORK_HOURS', 7);
define('DEFAULT_MAXIMUM_WORK_HOURS', 12);

// إعدادات التشفير
define('ENCRYPTION_KEY', 'your-secure-32-character-key-here!');
define('HASH_ALGORITHM', 'sha256');
define('ENCRYPTION_METHOD', 'AES-256-CBC');

// إعدادات التخزين المؤقت
define('CACHE_ENABLED', true);
define('CACHE_DURATION', 3600); // ساعة واحدة
define('CACHE_PATH', ROOT_PATH . '/cache/');

// إعدادات البريد الإلكتروني
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', '');
define('MAIL_PASSWORD', '');
define('MAIL_FROM_EMAIL', 'no-reply@company.com');
define('MAIL_FROM_NAME', 'نظام بصمة الوجه');

/**
 * فئة الاتصال بقاعدة البيانات - محسّنة
 */
class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $charset = DB_CHARSET;
    public $conn;
    private static $instance = null;
    private $transaction_count = 0;

    private function __construct() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_TIMEOUT => 30
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
            // ضبط المنطقة الزمنية لقاعدة البيانات
            $this->conn->exec("SET time_zone = '+03:00'");
            $this->conn->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            
            if (DEBUG) {
                die("خطأ في الاتصال بقاعدة البيانات: " . $exception->getMessage());
            } else {
                die("خطأ في الاتصال بقاعدة البيانات. يرجى المحاولة لاحقاً.");
            }
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
        if ($this->transaction_count == 0) {
            $result = $this->conn->beginTransaction();
        } else {
            $result = true;
        }
        $this->transaction_count++;
        return $result;
    }

    public function commit() {
        $this->transaction_count--;
        if ($this->transaction_count == 0) {
            return $this->conn->commit();
        }
        return true;
    }

    public function rollback() {
        $this->transaction_count--;
        if ($this->transaction_count == 0) {
            return $this->conn->rollback();
        }
        return true;
    }

    public function inTransaction() {
        return $this->transaction_count > 0;
    }

    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }

    public function query($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query error: " . $e->getMessage() . " SQL: " . $sql);
            throw $e;
        }
    }
}

/**
 * فئة إدارة الجلسات - محسّنة
 */
class SessionManager {
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            // إعدادات أمان الجلسة
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
            
            // تغيير مسار حفظ الجلسات
            $session_path = ROOT_PATH . '/sessions';
            if (!is_dir($session_path)) {
                mkdir($session_path, 0755, true);
            }
            session_save_path($session_path);
            
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
            
            // إضافة معلومات الأمان
            if (!isset($_SESSION['user_ip'])) {
                $_SESSION['user_ip'] = Security::getClientIP();
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            }
            
            // فحص IP ومتصفح المستخدم
            if ($_SESSION['user_ip'] !== Security::getClientIP() || 
                $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
                self::destroy();
                return false;
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

    public static function regenerate() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
}

/**
 * فئة الأمان - محسّنة ومطورة
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

    public static function sanitizeFilename($filename) {
        // إزالة المسارات والأحرف الخطيرة
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        return $filename;
    }

    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validatePhone($phone) {
        return preg_match('/^[\+]?[0-9\-\(\)\s]+$/', $phone);
    }

    public static function validatePassword($password) {
        if (strlen($password) < PASSWORD_MIN_LENGTH) return false;
        
        // يجب أن تحتوي على حرف ورقم على الأقل
        if (!preg_match('/[a-zA-Z]/', $password)) return false;
        if (!preg_match('/[0-9]/', $password)) return false;
        
        return true;
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

    public static function generateSecureToken($length = 64) {
        return base64_encode(random_bytes($length));
    }

    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = self::generateToken();
            $_SESSION['csrf_token_time'] = time();
        }
        
        // تجديد التوكن كل ساعة
        if (time() - $_SESSION['csrf_token_time'] > 3600) {
            $_SESSION['csrf_token'] = self::generateToken();
            $_SESSION['csrf_token_time'] = time();
        }
        
        return $_SESSION['csrf_token'];
    }

    public static function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && 
               hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function encryptData($data, $key = ENCRYPTION_KEY) {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decryptData($encryptedData, $key = ENCRYPTION_KEY) {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, ENCRYPTION_METHOD, $key, 0, $iv);
    }

    public static function checkLoginAttempts($identifier) {
        try {
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
        } catch (Exception $e) {
            error_log("Check login attempts error: " . $e->getMessage());
            return true; // السماح في حالة الخطأ
        }
    }

    public static function logFailedLogin($identifier, $ip = null) {
        $ip = $ip ?: self::getClientIP();
        logActivity('system', 0, 'failed_login', "محاولة دخول فاشلة: $identifier", $ip);
    }

    public static function getClientIP() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
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
        
        // استخدام cache إذا كان متاحاً
        if (CACHE_ENABLED) {
            $cache_file = CACHE_PATH . md5($cache_key) . '.cache';
            
            $attempts = [];
            if (file_exists($cache_file)) {
                $cache_data = file_get_contents($cache_file);
                $attempts = json_decode($cache_data, true) ?: [];
            }
            
            $current_time = time();
            $attempts = array_filter($attempts, function($timestamp) use ($current_time, $time_window) {
                return ($current_time - $timestamp) < $time_window;
            });
            
            if (count($attempts) >= $max_attempts) {
                return false;
            }
            
            $attempts[] = $current_time;
            
            if (!is_dir(CACHE_PATH)) {
                mkdir(CACHE_PATH, 0755, true);
            }
            
            file_put_contents($cache_file, json_encode($attempts));
        }
        
        return true;
    }

    public static function validateFileUpload($file) {
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
            return 'نوع الملف غير مدعوم. يُسمح فقط بـ JPG, JPEG, PNG, WEBP';
        }
        
        $image_info = getimagesize($file['tmp_name']);
        if ($image_info === false) {
            return 'الملف ليس صورة صحيحة';
        }
        
        return true;
    }
}

/**
 * وظائف إدارة الملفات - محسّنة
 */
class FileManager {
    
    public static function validateImage($file) {
        return Security::validateFileUpload($file);
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
        if (file_exists($filepath) && is_file($filepath)) {
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

    public static function optimizeImage($source_path, $quality = 85, $max_width = 1920, $max_height = 1080) {
        try {
            $image_info = getimagesize($source_path);
            if (!$image_info) return false;
            
            $original_width = $image_info[0];
            $original_height = $image_info[1];
            $mime_type = $image_info['mime'];
            
            // حساب الأبعاد الجديدة
            if ($original_width <= $max_width && $original_height <= $max_height) {
                return true; // لا حاجة للتحسين
            }
            
            $ratio = min($max_width / $original_width, $max_height / $original_height);
            $new_width = $original_width * $ratio;
            $new_height = $original_height * $ratio;
            
            // إنشاء الصورة حسب النوع
            switch ($mime_type) {
                case 'image/jpeg':
                    $source_image = imagecreatefromjpeg($source_path);
                    break;
                case 'image/png':
                    $source_image = imagecreatefrompng($source_path);
                    break;
                case 'image/webp':
                    $source_image = imagecreatefromwebp($source_path);
                    break;
                default:
                    return false;
            }
            
            if (!$source_image) return false;
            
            $optimized_image = imagecreatetruecolor($new_width, $new_height);
            
            // الحفاظ على الشفافية
            if ($mime_type == 'image/png') {
                imagealphablending($optimized_image, false);
                imagesavealpha($optimized_image, true);
                $transparent = imagecolorallocatealpha($optimized_image, 255, 255, 255, 127);
                imagefilledrectangle($optimized_image, 0, 0, $new_width, $new_height, $transparent);
            }
            
            imagecopyresampled($optimized_image, $source_image, 0, 0, 0, 0, 
                              $new_width, $new_height, $original_width, $original_height);
            
            // حفظ الصورة المحسنة
            $result = imagejpeg($optimized_image, $source_path, $quality);
            
            imagedestroy($source_image);
            imagedestroy($optimized_image);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Image optimization error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * وظائف عامة للنظام - محسّنة ومطورة
 */

function redirect($url, $force = false) {
    // منع redirect loop
    $current_url = $_SERVER['REQUEST_URI'] ?? '';
    $target_url = parse_url($url, PHP_URL_PATH);
    
    if (!$force && $current_url === $target_url) {
        return; // منع redirect للصفحة نفسها
    }
    
    // تنظيف output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // تعيين header
    if (!headers_sent()) {
        header("Location: " . $url, true, 302);
        exit();
    } else {
        // استخدام JavaScript كـ fallback
        echo "<script>window.location.replace('" . addslashes($url) . "');</script>";
        echo "<noscript><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($url) . "'></noscript>";
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
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
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
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        
        $result = $stmt->execute([$key, $value]);
        
        // مسح cache الإعدادات
        clearSettingsCache();
        
        return $result;
    } catch (Exception $e) {
        error_log("Failed to update setting: " . $e->getMessage());
        return false;
    }
}

function clearSettingsCache() {
    // إعادة تعيين cache الإعدادات
    $GLOBALS['settings_cache'] = null;
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
    
    // طرح وقت الاستراحة إذا كان موجوداً
    $break_minutes = getSetting('lunch_break_minutes', 60);
    $work_seconds = $diff - ($break_minutes * 60);
    
    return round($work_seconds / 3600, 2);
}

function isLate($check_in_time, $work_start_time, $tolerance_minutes = null) {
    $tolerance_minutes = $tolerance_minutes ?: getSetting('late_tolerance_minutes', DEFAULT_LATE_TOLERANCE_MINUTES);
    
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
    
    // فحص أيام الأسبوع المعطلة
    $weekend_days = explode(',', getSetting('weekend_days', '5,6')); // الجمعة والسبت افتراضياً
    if (in_array($day_of_week, $weekend_days)) return false;
    
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
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time()
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

// دوال مساعدة إضافية
function format_arabic_date($date, $include_time = false) {
    return formatArabicDate($date, $include_time);
}

function log_activity($user_type, $user_id, $action, $description = '', $ip_address = null) {
    return logActivity($user_type, $user_id, $action, $description, $ip_address);
}

function generateEmployeeId() {
    $prefix = getSetting('employee_id_prefix', 'EMP');
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING(employee_id, 4) AS UNSIGNED)) as max_num FROM employees WHERE employee_id LIKE ?");
    $stmt->execute([$prefix . '%']);
    $result = $stmt->fetch();
    
    $next_num = ($result['max_num'] ?? 0) + 1;
    return $prefix . str_pad($next_num, 3, '0', STR_PAD_LEFT);
}

function sendEmail($to, $subject, $message, $is_html = true) {
    // يمكن تطوير هذه الدالة لاستخدام PHPMailer أو مكتبة بريد أخرى
    try {
        $headers = [
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>',
            'Reply-To: ' . MAIL_FROM_EMAIL,
            'Content-Type: ' . ($is_html ? 'text/html' : 'text/plain') . '; charset=UTF-8'
        ];
        
        return mail($to, $subject, $message, implode("\r\n", $headers));
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

// بدء الجلسة
SessionManager::start();

// إنشاء المجلدات المطلوبة
$required_dirs = [UPLOAD_PATH, FACES_PATH, ATTENDANCE_PATH, LOGS_PATH, TEMP_PATH, CACHE_PATH];
foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// إعداد معالج الأخطاء المخصص
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $error_types = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict Standards',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated'
    ];
    
    $error_type = $error_types[$errno] ?? 'Unknown Error';
    $error_message = "[$error_type]: $errstr in $errfile on line $errline";
    error_log($error_message);
    
    // في بيئة التطوير، يمكن عرض الأخطاء
    if (DEBUG === true) {
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
    
    if (DEBUG === true) {
        echo "<div style='background:#ffebee;color:#c62828;padding:10px;margin:10px;border-radius:5px;'>";
        echo "<strong>استثناء غير معالج:</strong> " . $exception->getMessage();
        echo "<br><strong>الملف:</strong> " . $exception->getFile();
        echo "<br><strong>السطر:</strong> " . $exception->getLine();
        echo "</div>";
    } else {
        echo "<h1>خطأ في الخادم</h1><p>حدث خطأ غير متوقع. يرجى المحاولة لاحقاً.</p>";
    }
}

set_exception_handler('customExceptionHandler');

// تنظيف البيانات القديمة عند الحاجة
register_shutdown_function(function() {
    // تنظيف ملفات cache القديمة
    if (CACHE_ENABLED && rand(1, 100) == 1) { // 1% احتمال
        $cache_files = glob(CACHE_PATH . '*.cache');
        foreach ($cache_files as $file) {
            if (filemtime($file) < time() - CACHE_DURATION) {
                unlink($file);
            }
        }
    }
});

?>