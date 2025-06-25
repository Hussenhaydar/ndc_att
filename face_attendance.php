<?php
require_once 'config.php';
checkEmployeeLogin();

$type = $_GET['type'] ?? 'checkin';
$employee_id = $_SESSION['employee_id'];
$today = date('Y-m-d');

// فحص إذا كان الموظف في إجازة
if (isOnLeave($employee_id, $today)) {
    redirect('employee_dashboard.php');
}

// جلب بيانات الموظف
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// فحص حضور اليوم
$stmt = $db->prepare("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?");
$stmt->execute([$employee_id, $today]);
$today_attendance = $stmt->fetch(PDO::FETCH_ASSOC);

// التحقق من صحة نوع العملية
if ($type === 'checkin' && $today_attendance) {
    redirect('employee_dashboard.php');
}
if ($type === 'checkout' && (!$today_attendance || $today_attendance['check_out_time'])) {
    redirect('employee_dashboard.php');
}

$title = $type === 'checkin' ? 'تسجيل حضور' : 'تسجيل انصراف';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo $title; ?> - بصمة الوجه</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 30px;
            padding: 40px 30px;
            width: 100%;
            max-width: 500px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        
        .header {
            margin-bottom: 30px;
        }
        
        .title {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #7f8c8d;
            font-size: 16px;
        }
        
        .camera-container {
            position: relative;
            width: 300px;
            height: 300px;
            margin: 30px auto;
            border-radius: 50%;
            overflow: hidden;
            border: 5px solid #667eea;
            background: #f8f9fa;
        }
        
        #video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }
        
        .overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 3px solid transparent;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .overlay.scanning {
            border-color: #f39c12;
            animation: pulse 1.5s infinite;
        }
        
        .overlay.success {
            border-color: #27ae60;
        }
        
        .overlay.error {
            border-color: #e74c3c;
        }
        
        @keyframes pulse {
            0%, 100% { 
                border-color: #f39c12; 
                transform: scale(1);
            }
            50% { 
                border-color: #e67e22; 
                transform: scale(1.05);
            }
        }
        
        .status {
            margin: 20px 0;
            padding: 15px;
            border-radius: 15px;
            font-weight: 600;
        }
        
        .status.waiting {
            background: #fff3cd;
            color: #856404;
        }
        
        .status.scanning {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status.success {
            background: #d4edda;
            color: #155724;
        }
        
        .status.error {
            background: #f8d7da;
            color: #721c24;
        }
        
        .controls {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 15px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        
        .btn:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
        }
        
        .instructions {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: right;
        }
        
        .instructions h4 {
            color: #1976d2;
            margin-bottom: 10px;
        }
        
        .instructions ul {
            list-style: none;
            padding: 0;
        }
        
        .instructions li {
            margin-bottom: 5px;
            color: #424242;
            padding-right: 20px;
            position: relative;
        }
        
        .instructions li::before {
            content: '•';
            color: #2196f3;
            position: absolute;
            right: 0;
        }
        
        .loading {
            display: none;
            margin: 20px 0;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .employee-info {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .employee-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .employee-id {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 30px 20px;
            }
            
            .camera-container {
                width: 250px;
                height: 250px;
            }
            
            .title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title"><?php echo $title; ?></h1>
            <p class="subtitle">استخدم بصمة الوجه للتسجيل</p>
        </div>
        
        <div class="employee-info">
            <div class="employee-name"><?php echo htmlspecialchars($employee['full_name']); ?></div>
            <div class="employee-id"><?php echo htmlspecialchars($employee['employee_id']); ?></div>
        </div>
        
        <div class="instructions">
            <h4>📝 تعليمات مهمة:</h4>
            <ul>
                <li>تأكد من وجود إضاءة جيدة</li>
                <li>انظر مباشرة إلى الكاميرا</li>
                <li>أبق وجهك في المنتصف</li>
                <li>تأكد من الاتصال بشبكة الشركة</li>
                <li>لا تحرك هاتفك أثناء المسح</li>
            </ul>
        </div>
        
        <div class="camera-container">
            <video id="video" autoplay muted playsinline></video>
            <div class="overlay" id="overlay"></div>
            <canvas id="canvas" style="display: none;"></canvas>
        </div>
        
        <div class="status waiting" id="status">
            📷 اضغط "بدء المسح" لتشغيل الكاميرا
        </div>
        
        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p>جاري معالجة بصمة الوجه...</p>
        </div>
        
        <div class="controls">
            <button class="btn btn-primary" id="scanBtn" onclick="startScanning()">
                📷 بدء المسح
            </button>
            <button class="btn btn-secondary" onclick="goBack()">
                ← العودة
            </button>
        </div>
    </div>

    <script>
        let video = document.getElementById('video');
        let canvas = document.getElementById('canvas');
        let status = document.getElementById('status');
        let overlay = document.getElementById('overlay');
        let scanBtn = document.getElementById('scanBtn');
        let loading = document.getElementById('loading');
        
        let stream = null;
        let scanning = false;
        let faceDetectionInterval = null;
        
        const attendanceType = '<?php echo $type; ?>';
        
        async function startScanning() {
            try {
                // طلب الوصول للكاميرا
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        facingMode: 'user',
                        width: { ideal: 640 },
                        height: { ideal: 640 }
                    } 
                });
                
                video.srcObject = stream;
                
                // تحديث الحالة
                updateStatus('scanning', '🔍 جاري البحث عن الوجه...');
                overlay.className = 'overlay scanning';
                scanBtn.textContent = '⏹️ إيقاف المسح';
                scanBtn.onclick = stopScanning;
                scanning = true;
                
                // فحص الشبكة أولاً
                const networkCheck = await checkNetwork();
                if (!networkCheck.success) {
                    updateStatus('error', '❌ يجب الاتصال بشبكة الشركة');
                    stopScanning();
                    return;
                }
                
                // بدء كشف الوجه
                startFaceDetection();
                
            } catch (error) {
                console.error('Error accessing camera:', error);
                updateStatus('error', '❌ لا يمكن الوصول للكاميرا');
            }
        }
        
        function stopScanning() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            
            if (faceDetectionInterval) {
                clearInterval(faceDetectionInterval);
                faceDetectionInterval = null;
            }
            
            scanning = false;
            overlay.className = 'overlay';
            updateStatus('waiting', '📷 اضغط "بدء المسح" لتشغيل الكاميرا');
            scanBtn.textContent = '📷 بدء المسح';
            scanBtn.onclick = startScanning;
        }
        
        function startFaceDetection() {
            faceDetectionInterval = setInterval(async () => {
                if (!scanning) return;
                
                try {
                    // التقاط صورة من الفيديو
                    const imageData = captureFrame();
                    
                    // إرسال للتحليل
                    const result = await analyzeFace(imageData);
                    
                    if (result.success) {
                        // وجه تم التعرف عليه
                        updateStatus('success', '✅ تم التعرف على الوجه بنجاح!');
                        overlay.className = 'overlay success';
                        
                        // إيقاف المسح
                        stopScanning();
                        
                        // إرسال بيانات الحضور
                        await submitAttendance(imageData, result.confidence);
                        
                    } else if (result.faceDetected) {
                        // وجه مكتشف لكن غير معروف
                        updateStatus('error', '❌ وجه غير معروف');
                        overlay.className = 'overlay error';
                        
                        setTimeout(() => {
                            if (scanning) {
                                overlay.className = 'overlay scanning';
                                updateStatus('scanning', '🔍 جاري البحث عن الوجه...');
                            }
                        }, 2000);
                    }
                    
                } catch (error) {
                    console.error('Face detection error:', error);
                }
            }, 1000); // فحص كل ثانية
        }
        
        function captureFrame() {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            
            return canvas.toDataURL('image/jpeg', 0.8);
        }
        
        async function checkNetwork() {
            try {
                const response = await fetch('check_network.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                return await response.json();
            } catch (error) {
                return { success: false, message: 'خطأ في فحص الشبكة' };
            }
        }
        
        async function analyzeFace(imageData) {
            try {
                const response = await fetch('process_face.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        image: imageData,
                        employee_id: <?php echo $employee_id; ?>,
                        type: attendanceType
                    })
                });
                
                return await response.json();
            } catch (error) {
                console.error('Face analysis error:', error);
                return { success: false, faceDetected: false };
            }
        }
        
        async function submitAttendance(imageData, confidence) {
            loading.style.display = 'block';
            
            try {
                const response = await fetch('submit_attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        type: attendanceType,
                        image: imageData,
                        confidence: confidence,
                        timestamp: new Date().toISOString()
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    updateStatus('success', '✅ ' + result.message);
                    
                    // توجيه إلى لوحة التحكم بعد 3 ثوان
                    setTimeout(() => {
                        window.location.href = 'employee_dashboard.php';
                    }, 3000);
                } else {
                    updateStatus('error', '❌ ' + result.message);
                }
                
            } catch (error) {
                console.error('Attendance submission error:', error);
                updateStatus('error', '❌ حدث خطأ في إرسال البيانات');
            } finally {
                loading.style.display = 'none';
            }
        }
        
        function updateStatus(type, message) {
            status.className = `status ${type}`;
            status.textContent = message;
        }
        
        function goBack() {
            if (scanning) {
                stopScanning();
            }
            window.location.href = 'employee_dashboard.php';
        }
        
        // تنظيف الموارد عند إغلاق الصفحة
        window.addEventListener('beforeunload', () => {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
        
        // منع النوم أثناء المسح
        let wakeLock = null;
        
        async function requestWakeLock() {
            try {
                if ('wakeLock' in navigator) {
                    wakeLock = await navigator.wakeLock.request('screen');
                }
            } catch (err) {
                console.error('Wake lock error:', err);
            }
        }
        
        // طلب منع النوم عند بدء المسح
        document.addEventListener('DOMContentLoaded', () => {
            requestWakeLock();
        });
    </script>
</body>
</html>