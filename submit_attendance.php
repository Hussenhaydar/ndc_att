<?php
require_once 'config.php';
checkEmployeeLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'طريقة طلب غير صحيحة', null, 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['type']) || !isset($input['image'])) {
    jsonResponse(false, 'بيانات غير مكتملة');
}

$employee_id = $_SESSION['employee_id'];
$type = $input['type']; // checkin أو checkout
$image_data = $input['image'];
$confidence = $input['confidence'] ?? 0;
$timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');

$today = date('Y-m-d');
$current_time = date('H:i:s');

try {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();
    
    // جلب بيانات الموظف
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_active = 1");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        $db->rollback();
        jsonResponse(false, 'موظف غير موجود أو غير مفعل');
    }
    
    // فحص إذا كان في إجازة
    if (isOnLeave($employee_id, $today)) {
        $db->rollback();
        jsonResponse(false, 'أنت في إجازة اليوم. لا يمكن تسجيل الحضور');
    }
    
    // فحص إذا كان يوم عطلة
    if (!isWorkingDay($today)) {
        $db->rollback();
        jsonResponse(false, 'اليوم عطلة رسمية. لا يمكن تسجيل الحضور');
    }
    
    // جلب سجل الحضور الحالي
    $stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
    $stmt->execute([$employee_id, $today]);
    $existing_attendance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // إعدادات الوقت
    $work_start_time = $employee['work_start_time'] ?? getSetting('work_start_time', '08:00:00');
    $work_end_time = $employee['work_end_time'] ?? getSetting('work_end_time', '17:00:00');
    $attendance_start_time = getSetting('attendance_start_time', '07:30:00');
    $attendance_end_time = getSetting('attendance_end_time', '09:00:00');
    $checkout_start_time = getSetting('checkout_start_time', '16:30:00');
    $checkout_end_time = getSetting('checkout_end_time', '18:00:00');
    
    if ($type === 'checkin') {
        // تسجيل حضور
        
        if ($existing_attendance) {
            $db->rollback();
            jsonResponse(false, 'تم تسجيل حضورك مسبقاً اليوم');
        }
        
        // فحص وقت الحضور المسموح
        if ($current_time < $attendance_start_time || $current_time > $attendance_end_time) {
            $db->rollback();
            jsonResponse(false, 'خارج وقت تسجيل الحضور المسموح (' . 
                        formatArabicTime($attendance_start_time) . ' - ' . 
                        formatArabicTime($attendance_end_time) . ')');
        }
        
        // فحص التأخير
        $is_late = isLate($current_time, $work_start_time);
        $late_minutes = $is_late ? getLateMinutes($current_time, $work_start_time) : 0;
        
        // حفظ صورة الحضور
        $image_filename = saveAttendanceImage($image_data, $employee_id, 'checkin');
        
        // إدراج سجل الحضور
        $stmt = $db->prepare("
            INSERT INTO attendance (
                employee_id, attendance_date, check_in_time, check_in_image, 
                wifi_network, is_late, late_minutes, status
            ) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)
        ");
        
        $wifi_network = getSetting('required_wifi_network', 'Unknown');
        $status = $is_late ? 'late' : 'present';
        
        $stmt->execute([
            $employee_id, $today, $image_filename, 
            $wifi_network, $is_late, $late_minutes, $status
        ]);
        
        // تسجيل النشاط
        $activity_message = $is_late ? 
            "تسجيل حضور متأخر - التأخير: $late_minutes دقيقة" : 
            "تسجيل حضور في الموعد";
        
        logActivity('employee', $employee_id, 'check_in', $activity_message);
        
        // إنشاء إشعار
        $notification_message = $is_late ? 
            "تم تسجيل حضورك مع تأخير $late_minutes دقيقة" : 
            "تم تسجيل حضورك بنجاح";
        
        createNotification('employee', $employee_id, 'تسجيل حضور', $notification_message, 
                          $is_late ? 'warning' : 'success');
        
        // إشعار للإدارة في حالة التأخير الشديد
        $late_threshold = getSetting('late_notification_threshold', 30);
        if ($is_late && $late_minutes >= $late_threshold) {
            // إشعار للإدارة
            $admin_message = "الموظف {$employee['full_name']} ({$employee['employee_id']}) متأخر $late_minutes دقيقة";
            
            // إرسال إشعار لجميع الإدمن
            $admin_stmt = $db->prepare("SELECT id FROM admins WHERE is_active = 1");
            $admin_stmt->execute();
            $admins = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($admins as $admin) {
                createNotification('admin', $admin['id'], 'تأخير موظف', $admin_message, 'warning');
            }
        }
        
        $db->commit();
        
        $response_message = $is_late ? 
            "تم تسجيل حضورك مع تأخير $late_minutes دقيقة" : 
            "تم تسجيل حضورك بنجاح";
        
        jsonResponse(true, $response_message, [
            'type' => 'checkin',
            'time' => formatArabicTime(date('H:i:s')),
            'is_late' => $is_late,
            'late_minutes' => $late_minutes,
            'confidence' => $confidence,
            'status' => $status
        ]);
        
    } elseif ($type === 'checkout') {
        // تسجيل انصراف
        
        if (!$existing_attendance) {
            $db->rollback();
            jsonResponse(false, 'يجب تسجيل الحضور أولاً');
        }
        
        if ($existing_attendance['check_out_time']) {
            $db->rollback();
            jsonResponse(false, 'تم تسجيل انصرافك مسبقاً');
        }
        
        // فحص وقت الانصراف المسموح
        if ($current_time < $checkout_start_time || $current_time > $checkout_end_time) {
            $db->rollback();
            jsonResponse(false, 'خارج وقت تسجيل الانصراف المسموح (' . 
                        formatArabicTime($checkout_start_time) . ' - ' . 
                        formatArabicTime($checkout_end_time) . ')');
        }
        
        // حساب ساعات العمل
        $check_in_time = $existing_attendance['check_in_time'];
        $work_hours = calculateWorkHours($check_in_time, date('Y-m-d H:i:s'));
        
        // فحص الحد الأدنى لساعات العمل
        $min_work_hours = getSetting('minimum_work_hours', 7);
        $is_early_checkout = $work_hours < $min_work_hours;
        
        // حفظ صورة الانصراف
        $image_filename = saveAttendanceImage($image_data, $employee_id, 'checkout');
        
        // تحديث سجل الحضور
        $stmt = $db->prepare("
            UPDATE attendance SET 
                check_out_time = NOW(), 
                check_out_image = ?, 
                work_hours = ?,
                status = ?
            WHERE employee_id = ? AND attendance_date = ?
        ");
        
        $final_status = $is_early_checkout ? 'half_day' : $existing_attendance['status'];
        
        $stmt->execute([
            $image_filename, 
            $work_hours, 
            $final_status,
            $employee_id, 
            $today
        ]);
        
        // تسجيل النشاط
        $activity_desc = "تسجيل انصراف - ساعات العمل: " . round($work_hours, 1);
        if ($is_early_checkout) {
            $activity_desc .= " (انصراف مبكر)";
        }
        
        logActivity('employee', $employee_id, 'check_out', $activity_desc);
        
        // إنشاء إشعار
        $notification_message = "تم تسجيل انصرافك بنجاح. ساعات العمل: " . round($work_hours, 1);
        if ($is_early_checkout) {
            $notification_message .= " (انصراف مبكر)";
        }
        
        createNotification('employee', $employee_id, 'تسجيل انصراف', 
                          $notification_message, 
                          $is_early_checkout ? 'warning' : 'success');
        
        $db->commit();
        
        $response_data = [
            'type' => 'checkout',
            'time' => formatArabicTime(date('H:i:s')),
            'work_hours' => round($work_hours, 1),
            'confidence' => $confidence,
            'is_early_checkout' => $is_early_checkout,
            'status' => $final_status
        ];
        
        $response_message = "تم تسجيل انصرافك بنجاح";
        if ($is_early_checkout) {
            $response_message .= " (انصراف مبكر - ساعات العمل: " . round($work_hours, 1) . ")";
        }
        
        jsonResponse(true, $response_message, $response_data);
        
    } else {
        $db->rollback();
        jsonResponse(false, 'نوع عملية غير صحيح');
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("Attendance submission error: " . $e->getMessage());
    jsonResponse(false, 'حدث خطأ في تسجيل الحضور: ' . $e->getMessage());
}

/**
 * حفظ صورة الحضور/الانصراف
 */
function saveAttendanceImage($image_data, $employee_id, $type) {
    try {
        // إزالة header من base64
        $image_data = preg_replace('/^data:image\/[^;]+;base64,/', '', $image_data);
        $image_binary = base64_decode($image_data);
        
        if (!$image_binary) {
            throw new Exception('صورة غير صحيحة');
        }
        
        // التحقق من حجم الصورة
        if (strlen($image_binary) > MAX_FILE_SIZE) {
            throw new Exception('حجم الصورة كبير جداً');
        }
        
        // إنشاء مجلد للصور إذا لم يكن موجوداً
        $upload_dir = UPLOAD_PATH . 'attendance/' . date('Y-m') . '/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // إنشاء اسم الملف
        $filename = $employee_id . '_' . $type . '_' . date('Y-m-d_H-i-s') . '.jpg';
        $filepath = $upload_dir . $filename;
        
        // حفظ الصورة
        if (file_put_contents($filepath, $image_binary)) {
            return 'attendance/' . date('Y-m') . '/' . $filename;
        } else {
            throw new Exception('فشل في حفظ الصورة');
        }
        
    } catch (Exception $e) {
        error_log("Image save error: " . $e->getMessage());
        return null;
    }
}

/**
 * إنشاء thumbnail للصورة
 */
function createThumbnail($source_path, $thumbnail_path, $max_width = 200, $max_height = 200) {
    try {
        $image_info = getimagesize($source_path);
        if (!$image_info) return false;
        
        $source_width = $image_info[0];
        $source_height = $image_info[1];
        $mime_type = $image_info['mime'];
        
        // حساب الأبعاد الجديدة
        $ratio = min($max_width / $source_width, $max_height / $source_height);
        $new_width = $source_width * $ratio;
        $new_height = $source_height * $ratio;
        
        // إنشاء الصورة حسب النوع
        switch ($mime_type) {
            case 'image/jpeg':
                $source_image = imagecreatefromjpeg($source_path);
                break;
            case 'image/png':
                $source_image = imagecreatefrompng($source_path);
                break;
            case 'image/gif':
                $source_image = imagecreatefromgif($source_path);
                break;
            default:
                return false;
        }
        
        if (!$source_image) return false;
        
        // إنشاء الصورة المصغرة
        $thumbnail = imagecreatetruecolor($new_width, $new_height);
        
        // الحفاظ على الشفافية للـ PNG
        if ($mime_type == 'image/png') {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
            imagefilledrectangle($thumbnail, 0, 0, $new_width, $new_height, $transparent);
        }
        
        // تصغير الصورة
        imagecopyresampled($thumbnail, $source_image, 0, 0, 0, 0, 
                          $new_width, $new_height, $source_width, $source_height);
        
        // حفظ الصورة المصغرة
        $result = imagejpeg($thumbnail, $thumbnail_path, 85);
        
        // تنظيف الذاكرة
        imagedestroy($source_image);
        imagedestroy($thumbnail);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Thumbnail creation error: " . $e->getMessage());
        return false;
    }
}

/**
 * إحصائيات سريعة للموظف
 */
function getEmployeeQuickStats($employee_id) {
    try {
        $db = Database::getInstance()->getConnection();
        $current_month = date('Y-m');
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END) as late_days,
                AVG(work_hours) as avg_work_hours,
                SUM(work_hours) as total_work_hours
            FROM attendance 
            WHERE employee_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
        ");
        
        $stmt->execute([$employee_id, $current_month]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Quick stats error: " . $e->getMessage());
        return [
            'total_days' => 0,
            'present_days' => 0,
            'late_days' => 0,
            'avg_work_hours' => 0,
            'total_work_hours' => 0
        ];
    }
}
?>