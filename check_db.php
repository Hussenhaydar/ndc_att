<?php
header('Content-Type: application/json; charset=utf-8');

// إعدادات قاعدة البيانات (نفس الإعدادات من config.php)
define('DB_HOST', 'localhost');
define('DB_NAME', 'face_attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

try {
    // محاولة الاتصال بقاعدة البيانات
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5 // 5 ثوان timeout
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // فحص وجود الجداول الأساسية
    $required_tables = ['admins', 'employees', 'attendance', 'system_settings'];
    $existing_tables = [];
    $missing_tables = [];
    
    foreach ($required_tables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        
        if ($stmt->rowCount() > 0) {
            $existing_tables[] = $table;
        } else {
            $missing_tables[] = $table;
        }
    }
    
    // فحص وجود المدير الافتراضي
    $admin_exists = false;
    if (in_array('admins', $existing_tables)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM admins");
        $stmt->execute();
        $result = $stmt->fetch();
        $admin_exists = $result['count'] > 0;
    }
    
    if (count($missing_tables) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'الجداول التالية مفقودة: ' . implode(', ', $missing_tables),
            'existing_tables' => $existing_tables,
            'missing_tables' => $missing_tables,
            'admin_exists' => $admin_exists
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'قاعدة البيانات متصلة وجميع الجداول موجودة',
            'existing_tables' => $existing_tables,
            'missing_tables' => [],
            'admin_exists' => $admin_exists
        ]);
    }
    
} catch (PDOException $e) {
    $error_code = $e->getCode();
    $error_message = $e->getMessage();
    
    // تحديد نوع الخطأ
    if (strpos($error_message, 'Unknown database') !== false) {
        $user_message = 'قاعدة البيانات غير موجودة. يرجى إنشاؤها أولاً.';
    } elseif (strpos($error_message, 'Access denied') !== false) {
        $user_message = 'خطأ في بيانات الاتصال (اسم المستخدم أو كلمة المرور).';
    } elseif (strpos($error_message, 'Connection refused') !== false) {
        $user_message = 'خادم قاعدة البيانات غير متاح.';
    } else {
        $user_message = 'خطأ في الاتصال بقاعدة البيانات.';
    }
    
    echo json_encode([
        'success' => false,
        'message' => $user_message,
        'error_code' => $error_code,
        'detailed_error' => $error_message
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطأ عام في النظام: ' . $e->getMessage()
    ]);
}
?>