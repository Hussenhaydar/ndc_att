-- إنشاء قاعدة البيانات
CREATE DATABASE face_attendance_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE face_attendance_system;

-- جدول الإدمن
CREATE TABLE admins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- جدول الموظفين
CREATE TABLE employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    department VARCHAR(50),
    position VARCHAR(50),
    face_image LONGTEXT, -- Base64 encoded image
    face_encoding LONGTEXT, -- JSON encoded face features
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- جدول إعدادات النظام
CREATE TABLE system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    wifi_network_name VARCHAR(100) NOT NULL,
    work_start_time TIME NOT NULL DEFAULT '08:00:00',
    work_end_time TIME NOT NULL DEFAULT '17:00:00',
    attendance_start_time TIME NOT NULL DEFAULT '07:30:00',
    attendance_end_time TIME NOT NULL DEFAULT '09:00:00',
    checkout_start_time TIME NOT NULL DEFAULT '16:30:00',
    checkout_end_time TIME NOT NULL DEFAULT '18:00:00',
    late_tolerance_minutes INT DEFAULT 15,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- جدول أيام العطل
CREATE TABLE holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- جدول الإجازات
CREATE TABLE leaves (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    leave_type ENUM('sick', 'annual', 'emergency', 'maternity', 'other') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    days_count INT NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES admins(id)
);

-- جدول الحضور والانصراف
CREATE TABLE attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_time TIMESTAMP NULL,
    check_out_time TIMESTAMP NULL,
    check_in_image LONGTEXT, -- Base64 encoded image
    check_out_image LONGTEXT, -- Base64 encoded image
    wifi_network VARCHAR(100),
    is_late BOOLEAN DEFAULT FALSE,
    late_minutes INT DEFAULT 0,
    work_hours DECIMAL(4,2) DEFAULT 0,
    status ENUM('present', 'absent', 'late', 'half_day') DEFAULT 'present',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_employee_date (employee_id, attendance_date)
);

-- جدول سجل النشاطات
CREATE TABLE activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_type ENUM('admin', 'employee') NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- إدراج إعدادات افتراضية
INSERT INTO system_settings (wifi_network_name) VALUES ('CompanyWiFi');

-- إنشاء حساب إدمن افتراضي
INSERT INTO admins (username, password, full_name, email) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مدير النظام', 'admin@company.com');

-- إنشاء فهارس لتحسين الأداء
CREATE INDEX idx_employee_id ON employees(employee_id);
CREATE INDEX idx_attendance_date ON attendance(attendance_date);
CREATE INDEX idx_attendance_employee ON attendance(employee_id, attendance_date);
CREATE INDEX idx_leaves_employee ON leaves(employee_id);
CREATE INDEX idx_leaves_dates ON leaves(start_date, end_date);