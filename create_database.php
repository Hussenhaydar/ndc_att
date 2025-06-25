<?php
// ملف لإنشاء قاعدة البيانات والجداول تلقائياً
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'localhost');
define('DB_NAME', 'face_attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

try {
    // الاتصال بدون تحديد قاعدة بيانات لإنشائها
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // إنشاء قاعدة البيانات
    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE " . DB_NAME);
    
    // إنشاء الجداول الأساسية
    $tables = [
        // جدول الإدمن
        "CREATE TABLE IF NOT EXISTS admins (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            is_active BOOLEAN DEFAULT TRUE,
            last_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        
        // جدول الموظفين
        "CREATE TABLE IF NOT EXISTS employees (
            id INT PRIMARY KEY AUTO_INCREMENT,
            employee_id VARCHAR(20) UNIQUE NOT NULL,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            phone VARCHAR(20),
            department VARCHAR(50),
            position VARCHAR(50),
            hire_date DATE,
            face_encodings JSON,
            face_images JSON,
            work_start_time TIME DEFAULT '08:00:00',
            work_end_time TIME DEFAULT '17:00:00',
            is_active BOOLEAN DEFAULT TRUE,
            last_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        
        // جدول إعدادات النظام
        "CREATE TABLE IF NOT EXISTS system_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_name VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        
        // جدول الحضور
        "CREATE TABLE IF NOT EXISTS attendance (
            id INT PRIMARY KEY AUTO_INCREMENT,
            employee_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            check_in_time TIMESTAMP NULL,
            check_out_time TIMESTAMP NULL,
            check_in_image VARCHAR(255),
            check_out_image VARCHAR(255),
            wifi_network VARCHAR(100),
            is_late BOOLEAN DEFAULT FALSE,
            late_minutes INT DEFAULT 0,
            work_hours DECIMAL(4,2) DEFAULT 0,
            status ENUM('present', 'absent', 'late', 'half_day') DEFAULT 'present',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            UNIQUE KEY unique_employee_date (employee_id, attendance_date)
        )",
        
        // جدول الإجازات
        "CREATE TABLE IF NOT EXISTS leaves (
            id INT PRIMARY KEY AUTO_INCREMENT,
            employee_id INT NOT NULL,
            leave_type_id INT DEFAULT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            days_count INT NOT NULL,
            reason TEXT,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            approved_by INT,
            approved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )",
        
        // جدول أنواع الإجازات
        "CREATE TABLE IF NOT EXISTS leave_types (
            id INT PRIMARY KEY AUTO_INCREMENT,
            type_name VARCHAR(50) UNIQUE NOT NULL,
            type_name_ar VARCHAR(50) NOT NULL,
            max_days_per_year INT DEFAULT 0,
            requires_approval BOOLEAN DEFAULT TRUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        // جدول النشاطات
        "CREATE TABLE IF NOT EXISTS activity_log (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_type ENUM('admin', 'employee', 'system') NOT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            session_id VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        // جدول الجلسات
        "CREATE TABLE IF NOT EXISTS user_sessions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_type ENUM('admin', 'employee') NOT NULL,
            user_id INT NOT NULL,
            session_token VARCHAR(255) UNIQUE NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            expires_at TIMESTAMP NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        // جدول الإشعارات
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_type ENUM('admin', 'employee') NOT NULL,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
            action_url VARCHAR(500),
            is_read BOOLEAN DEFAULT FALSE,
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        // جدول العطل
        "CREATE TABLE IF NOT EXISTS holidays (
            id INT PRIMARY KEY AUTO_INCREMENT,
            holiday_date DATE NOT NULL,
            holiday_name VARCHAR(100) NOT NULL,
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    ];
    
    // إنشاء الجداول
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    
    // إدراج البيانات الأساسية
    $settings = [
        ['company_name', 'شركتي'],
        ['work_start_time', '08:00'],
        ['work_end_time', '17:00'],
        ['attendance_start_time', '07:30'],
        ['attendance_end_time', '09:00'],
        ['checkout_start_time', '16:30'],
        ['checkout_end_time', '18:00'],
        ['late_tolerance_minutes', '15'],
        ['face_match_threshold', '0.75'],
        ['enable_notifications', '1']
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_name, setting_value) VALUES (?, ?)");
    foreach ($settings as $setting) {
        $stmt->execute($setting);
    }
    
    // إنشاء حساب إدمن افتراضي
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT IGNORE INTO admins (username, password, full_name, email) VALUES (?, ?, ?, ?)")
        ->execute(['admin', $admin_password, 'مدير النظام', 'admin@company.com']);
    
    // إنشاء أنواع الإجازات
    $leave_types = [
        ['sick', 'مرضية', 30],
        ['annual', 'سنوية', 21],
        ['emergency', 'طارئة', 7],
        ['maternity', 'أمومة', 60]
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO leave_types (type_name, type_name_ar, max_days_per_year) VALUES (?, ?, ?)");
    foreach ($leave_types as $type) {
        $stmt->execute($type);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'تم إنشاء قاعدة البيانات والجداول بنجاح!',
        'admin_username' => 'admin',
        'admin_password' => 'admin123'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في إنشاء قاعدة البيانات: ' . $e->getMessage()
    ]);
}
?>