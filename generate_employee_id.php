<?php
require_once 'config.php';
checkAdminLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getInstance()->getConnection();
    
    // جلب بادئة رقم الموظف من الإعدادات
    $prefix = getSetting('employee_id_prefix', 'EMP');
    
    // البحث عن أعلى رقم موظف
    $stmt = $db->prepare("
        SELECT employee_id 
        FROM employees 
        WHERE employee_id LIKE ? 
        ORDER BY CAST(SUBSTRING(employee_id, ?) AS UNSIGNED) DESC 
        LIMIT 1
    ");
    
    $prefix_pattern = $prefix . '%';
    $prefix_length = strlen($prefix) + 1;
    
    $stmt->execute([$prefix_pattern, $prefix_length]);
    $last_employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($last_employee) {
        // استخراج الرقم من آخر رقم موظف
        $last_number = (int)substr($last_employee['employee_id'], strlen($prefix));
        $next_number = $last_number + 1;
    } else {
        // البداية من 1 إذا لم يوجد موظفين
        $next_number = 1;
    }
    
    // تنسيق الرقم الجديد
    $new_employee_id = $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
    
    // التحقق من عدم وجود الرقم مسبقاً (احتياطي)
    $check_stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ?");
    $check_stmt->execute([$new_employee_id]);
    
    if ($check_stmt->fetchColumn() > 0) {
        // في حالة وجود الرقم، جرب الرقم التالي
        $next_number++;
        $new_employee_id = $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
    }
    
    jsonResponse(true, 'تم توليد رقم الموظف بنجاح', [
        'employee_id' => $new_employee_id,
        'prefix' => $prefix,
        'number' => $next_number
    ]);
    
} catch (Exception $e) {
    error_log("Employee ID generation error: " . $e->getMessage());
    jsonResponse(false, 'حدث خطأ في توليد رقم الموظف');
}
?>