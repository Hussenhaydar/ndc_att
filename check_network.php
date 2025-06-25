<?php
require_once 'config.php';
checkEmployeeLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    // جلب اسم الشبكة المطلوبة من الإعدادات
    $required_network = getSetting('required_wifi_network', '');
    
    if (empty($required_network)) {
        jsonResponse(true, 'لا توجد قيود على الشبكة', ['network_required' => false]);
    }
    
    // فحص الـ IP والشبكة
    $client_ip = Security::getClientIP();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // تسجيل محاولة الوصول
    logActivity('employee', $_SESSION['employee_id'], 'network_check', 
               "فحص الشبكة من IP: $client_ip");
    
    // فحص نطاق IP الخاص بالشركة (يمكن تخصيصه)
    $allowed_ip_ranges = getSetting('allowed_ip_ranges', '');
    
    if (!empty($allowed_ip_ranges)) {
        $ranges = explode(',', $allowed_ip_ranges);
        $ip_allowed = false;
        
        foreach ($ranges as $range) {
            $range = trim($range);
            if (isIPInRange($client_ip, $range)) {
                $ip_allowed = true;
                break;
            }
        }
        
        if (!$ip_allowed) {
            jsonResponse(false, 'يجب الاتصال من شبكة الشركة المعتمدة', [
                'network_required' => true,
                'required_network' => $required_network,
                'current_ip' => $client_ip
            ]);
        }
    }
    
    // فحص إضافي: التحقق من MAC address إذا كان متاحاً
    // هذا يتطلب JavaScript إضافي في العميل
    
    jsonResponse(true, 'الشبكة صحيحة', [
        'network_required' => true,
        'network_name' => $required_network,
        'verified' => true
    ]);
    
} catch (Exception $e) {
    error_log("Network check error: " . $e->getMessage());
    jsonResponse(false, 'حدث خطأ في فحص الشبكة');
}

/**
 * فحص ما إذا كان IP ضمن نطاق معين
 */
function isIPInRange($ip, $range) {
    if (strpos($range, '/') !== false) {
        // CIDR notation
        list($subnet, $mask) = explode('/', $range);
        
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - $mask);
        
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    } else {
        // نطاق بسيط مثل 192.168.1.*
        $range = str_replace('*', '', $range);
        return strpos($ip, $range) === 0;
    }
}
?>