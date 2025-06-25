<?php
require_once 'config.php';
checkEmployeeLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'طريقة طلب غير صحيحة', null, 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['image']) || !isset($input['employee_id'])) {
    jsonResponse(false, 'بيانات غير مكتملة');
}

$employee_id = (int)$input['employee_id'];
$image_data = $input['image'];
$type = $input['type'] ?? 'checkin';

// التحقق من أن الموظف يطابق الجلسة
if ($employee_id !== $_SESSION['employee_id']) {
    jsonResponse(false, 'غير مصرح لك بهذه العملية', null, 403);
}

try {
    $db = Database::getInstance()->getConnection();
    
    // جلب بيانات الموظف وbencodings المحفوظة
    $stmt = $db->prepare("SELECT face_encodings, full_name FROM employees WHERE id = ? AND is_active = 1");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        jsonResponse(false, 'موظف غير موجود أو غير مفعل');
    }
    
    if (empty($employee['face_encodings'])) {
        jsonResponse(false, 'لم يتم تسجيل بصمة وجه. يرجى التوجه إلى إعداد بصمة الوجه أولاً');
    }
    
    // تحويل الصورة من base64
    $image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
    $image_binary = base64_decode($image_data);
    
    if (!$image_binary) {
        jsonResponse(false, 'صورة غير صحيحة');
    }
    
    // حفظ الصورة مؤقتاً للمعالجة
    $temp_image_path = sys_get_temp_dir() . '/face_' . uniqid() . '.jpg';
    file_put_contents($temp_image_path, $image_binary);
    
    // استخراج features من الصورة الحالية
    $current_encoding = extractFaceEncoding($temp_image_path);
    
    // حذف الملف المؤقت
    unlink($temp_image_path);
    
    if (!$current_encoding) {
        jsonResponse(false, 'لم يتم العثور على وجه في الصورة', ['faceDetected' => false]);
    }
    
    // مقارنة مع encodings المحفوظة
    $saved_encodings = json_decode($employee['face_encodings'], true);
    $best_match = 0;
    
    if (is_array($saved_encodings)) {
        foreach ($saved_encodings as $saved_encoding) {
            $similarity = compareFaceEncodings($current_encoding, $saved_encoding);
            if ($similarity > $best_match) {
                $best_match = $similarity;
            }
        }
    }
    
    // عتبة التطابق (يمكن تعديلها حسب الحاجة)
    $threshold = floatval(getSetting('face_match_threshold', 0.75));
    
    if ($best_match >= $threshold) {
        // تم التعرف على الوجه بنجاح
        logActivity('employee', $employee_id, 'face_recognition_success', 
                   "تم التعرف على الوجه بنجاح - الثقة: " . round($best_match * 100, 2) . "%");
        
        jsonResponse(true, 'تم التعرف على الوجه بنجاح', [
            'confidence' => round($best_match * 100, 2),
            'faceDetected' => true
        ]);
    } else {
        // الوجه غير معروف
        logActivity('employee', $employee_id, 'face_recognition_failed', 
                   "فشل التعرف على الوجه - الثقة: " . round($best_match * 100, 2) . "%");
        
        jsonResponse(false, 'وجه غير معروف', [
            'confidence' => round($best_match * 100, 2),
            'faceDetected' => true
        ]);
    }
    
} catch (Exception $e) {
    error_log("Face processing error: " . $e->getMessage());
    jsonResponse(false, 'حدث خطأ في معالجة الصورة');
}

/**
 * استخراج face encoding من صورة
 */
function extractFaceEncoding($image_path) {
    // هنا يجب استخدام مكتبة لتحليل الوجوه مثل:
    // - Python face_recognition library عبر shell_exec
    // - OpenCV مع PHP
    // - خدمة API خارجية
    
    // مثال باستخدام Python script (يتطلب تثبيت face_recognition)
    $python_script = __DIR__ . '/python/extract_face.py';
    
    if (file_exists($python_script)) {
        $command = "python3 " . escapeshellarg($python_script) . " " . escapeshellarg($image_path);
        $output = shell_exec($command);
        
        if ($output) {
            $result = json_decode(trim($output), true);
            if ($result && isset($result['encoding'])) {
                return $result['encoding'];
            }
        }
    }
    
    // Fallback: استخدام كشف الوجه البسيط
    return detectFaceSimple($image_path);
}

/**
 * كشف الوجه البسيط باستخدام GD
 */
function detectFaceSimple($image_path) {
    // هذه طريقة بسيطة جداً ولا تقدم دقة عالية
    // في البيئة الإنتاجية، يُنصح باستخدام مكتبات متقدمة
    
    $image = imagecreatefromjpeg($image_path);
    if (!$image) return false;
    
    $width = imagesx($image);
    $height = imagesy($image);
    
    // إنشاء encoding بسيط بناءً على خصائص الصورة
    $encoding = [];
    
    // تقسيم الصورة إلى شبكة وحساب متوسط اللون لكل منطقة
    $grid_size = 8;
    $cell_width = $width / $grid_size;
    $cell_height = $height / $grid_size;
    
    for ($x = 0; $x < $grid_size; $x++) {
        for ($y = 0; $y < $grid_size; $y++) {
            $start_x = (int)($x * $cell_width);
            $start_y = (int)($y * $cell_height);
            $end_x = (int)(($x + 1) * $cell_width);
            $end_y = (int)(($y + 1) * $cell_height);
            
            $total_r = $total_g = $total_b = 0;
            $pixel_count = 0;
            
            for ($px = $start_x; $px < $end_x; $px++) {
                for ($py = $start_y; $py < $end_y; $py++) {
                    if ($px < $width && $py < $height) {
                        $color = imagecolorat($image, $px, $py);
                        $total_r += ($color >> 16) & 0xFF;
                        $total_g += ($color >> 8) & 0xFF;
                        $total_b += $color & 0xFF;
                        $pixel_count++;
                    }
                }
            }
            
            if ($pixel_count > 0) {
                $encoding[] = $total_r / $pixel_count;
                $encoding[] = $total_g / $pixel_count;
                $encoding[] = $total_b / $pixel_count;
            }
        }
    }
    
    imagedestroy($image);
    
    return $encoding;
}

/**
 * مقارنة face encodings
 */
function compareFaceEncodings($encoding1, $encoding2) {
    if (!is_array($encoding1) || !is_array($encoding2)) {
        return 0;
    }
    
    if (count($encoding1) !== count($encoding2)) {
        return 0;
    }
    
    // حساب المسافة الإقليدية
    $distance = 0;
    for ($i = 0; $i < count($encoding1); $i++) {
        $diff = $encoding1[$i] - $encoding2[$i];
        $distance += $diff * $diff;
    }
    
    $distance = sqrt($distance);
    
    // تحويل المسافة إلى درجة تشابه (كلما قلت المسافة، زاد التشابه)
    $max_distance = 255 * sqrt(count($encoding1)); // أقصى مسافة ممكنة
    $similarity = 1 - ($distance / $max_distance);
    
    return max(0, min(1, $similarity));
}
?>