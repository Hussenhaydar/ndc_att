<?php
require_once 'config.php';
checkAdminLogin();

header('Content-Type: application/json; charset=utf-8');

$database = Database::getInstance();
$db = $database->getConnection();

// معايير التقرير
$report_type = $_POST['type'] ?? 'attendance';
$date_from = $_POST['date_from'] ?? date('Y-m-01');
$date_to = $_POST['date_to'] ?? date('Y-m-d');
$employee_filter = (int)($_POST['employee'] ?? 0);
$department_filter = $_POST['department'] ?? '';

try {
    $html = '';
    $summary = [];
    
    switch ($report_type) {
        case 'attendance':
            $result = generateAttendanceReport($db, $date_from, $date_to, $employee_filter, $department_filter);
            break;
            
        case 'employee_summary':
            $result = generateEmployeeSummaryReport($db, $date_from, $date_to, $employee_filter, $department_filter);
            break;
            
        case 'department':
            $result = generateDepartmentReport($db, $date_from, $date_to, $department_filter);
            break;
            
        case 'leaves':
            $result = generateLeavesReport($db, $date_from, $date_to, $employee_filter, $department_filter);
            break;
            
        case 'late_arrivals':
            $result = generateLateArrivalsReport($db, $date_from, $date_to, $employee_filter, $department_filter);
            break;
            
        default:
            throw new Exception('نوع تقرير غير صحيح');
    }
    
    echo json_encode([
        'success' => true,
        'html' => $result['html'],
        'summary' => $result['summary'] ?? []
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function generateAttendanceReport($db, $date_from, $date_to, $employee_filter, $department_filter) {
    // بناء الاستعلام
    $where_conditions = ["a.attendance_date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if ($employee_filter > 0) {
        $where_conditions[] = "a.employee_id = ?";
        $params[] = $employee_filter;
    }
    
    if (!empty($department_filter)) {
        $where_conditions[] = "e.department = ?";
        $params[] = $department_filter;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // استعلام التقرير
    $query = "
        SELECT a.*, e.full_name, e.employee_id, e.department, e.position
        FROM attendance a 
        JOIN employees e ON a.employee_id = e.id 
        $where_clause 
        ORDER BY a.attendance_date DESC, e.full_name
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات التقرير
    $summary = [
        'total_records' => count($records),
        'present_days' => count(array_filter($records, fn($r) => $r['check_in_time'])),
        'late_days' => count(array_filter($records, fn($r) => $r['is_late'])),
        'absent_days' => count(array_filter($records, fn($r) => !$r['check_in_time'])),
        'total_work_hours' => array_sum(array_column($records, 'work_hours')),
        'avg_work_hours' => count($records) > 0 ? array_sum(array_column($records, 'work_hours')) / count($records) : 0
    ];
    
    // إنشاء HTML للتقرير
    $html = generateSummaryCards($summary, 'attendance');
    
    $html .= '<h3 style="margin: 20px 0;">تفاصيل الحضور</h3>';
    $html .= '<table class="table">';
    $html .= '<thead><tr>';
    $html .= '<th>التاريخ</th><th>الموظف</th><th>القسم</th><th>الحضور</th><th>الانصراف</th><th>ساعات العمل</th><th>الحالة</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($records as $record) {
        $html .= '<tr>';
        $html .= '<td>' . formatArabicDate($record['attendance_date']) . '</td>';
        $html .= '<td>' . htmlspecialchars($record['full_name']) . '<br><small>' . htmlspecialchars($record['employee_id']) . '</small></td>';
        $html .= '<td>' . htmlspecialchars($record['department'] ?: '-') . '</td>';
        $html .= '<td>' . ($record['check_in_time'] ? formatArabicTime($record['check_in_time']) : '-') . '</td>';
        $html .= '<td>' . ($record['check_out_time'] ? formatArabicTime($record['check_out_time']) : '-') . '</td>';
        $html .= '<td>' . ($record['work_hours'] ? round($record['work_hours'], 1) . ' ساعة' : '-') . '</td>';
        
        $status = 'حاضر';
        if (!$record['check_in_time']) {
            $status = '<span style="color: #dc3545;">غائب</span>';
        } elseif ($record['is_late']) {
            $status = '<span style="color: #ffc107;">متأخر</span>';
        } elseif (!$record['check_out_time']) {
            $status = '<span style="color: #17a2b8;">لم يسجل خروج</span>';
        }
        
        $html .= '<td>' . $status . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    
    return ['html' => $html, 'summary' => $summary];
}

function generateEmployeeSummaryReport($db, $date_from, $date_to, $employee_filter, $department_filter) {
    // بناء الاستعلام
    $where_conditions = ["a.attendance_date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if ($employee_filter > 0) {
        $where_conditions[] = "e.id = ?";
        $params[] = $employee_filter;
    }
    
    if (!empty($department_filter)) {
        $where_conditions[] = "e.department = ?";
        $params[] = $department_filter;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $query = "
        SELECT 
            e.id, e.full_name, e.employee_id, e.department,
            COUNT(a.id) as total_days,
            SUM(CASE WHEN a.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN a.check_in_time IS NULL THEN 1 ELSE 0 END) as absent_days,
            AVG(a.work_hours) as avg_work_hours,
            SUM(a.work_hours) as total_work_hours
        FROM employees e
        LEFT JOIN attendance a ON e.id = a.employee_id $where_clause
        WHERE e.is_active = 1
        GROUP BY e.id
        ORDER BY e.full_name
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $html = '<h3 style="margin: 20px 0;">ملخص أداء الموظفين</h3>';
    $html .= '<table class="table">';
    $html .= '<thead><tr>';
    $html .= '<th>الموظف</th><th>القسم</th><th>أيام الحضور</th><th>أيام التأخير</th><th>أيام الغياب</th><th>متوسط ساعات العمل</th><th>إجمالي الساعات</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($employees as $emp) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($emp['full_name']) . '<br><small>' . htmlspecialchars($emp['employee_id']) . '</small></td>';
        $html .= '<td>' . htmlspecialchars($emp['department'] ?: '-') . '</td>';
        $html .= '<td>' . ($emp['present_days'] ?? 0) . '</td>';
        $html .= '<td>' . ($emp['late_days'] ?? 0) . '</td>';
        $html .= '<td>' . ($emp['absent_days'] ?? 0) . '</td>';
        $html .= '<td>' . round($emp['avg_work_hours'] ?? 0, 1) . '</td>';
        $html .= '<td>' . round($emp['total_work_hours'] ?? 0, 1) . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    
    return ['html' => $html];
}

function generateDepartmentReport($db, $date_from, $date_to, $department_filter) {
    $where_condition = "";
    $params = [$date_from, $date_to];
    
    if (!empty($department_filter)) {
        $where_condition = "AND e.department = ?";
        $params[] = $department_filter;
    }
    
    $query = "
        SELECT 
            e.department,
            COUNT(DISTINCT e.id) as total_employees,
            COUNT(a.id) as total_attendance_records,
            SUM(CASE WHEN a.check_in_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_days,
            AVG(a.work_hours) as avg_work_hours
        FROM employees e
        LEFT JOIN attendance a ON e.id = a.employee_id 
            AND a.attendance_date BETWEEN ? AND ?
        WHERE e.is_active = 1 AND e.department IS NOT NULL AND e.department != ''
        $where_condition
        GROUP BY e.department
        ORDER BY e.department
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $html = '<h3 style="margin: 20px 0;">تقرير الأقسام</h3>';
    $html .= '<table class="table">';
    $html .= '<thead><tr>';
    $html .= '<th>القسم</th><th>عدد الموظفين</th><th>سجلات الحضور</th><th>أيام الحضور</th><th>أيام التأخير</th><th>متوسط ساعات العمل</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($departments as $dept) {
        $html .= '<tr>';
        $html .= '<td style="font-weight: 600;">' . htmlspecialchars($dept['department']) . '</td>';
        $html .= '<td>' . $dept['total_employees'] . '</td>';
        $html .= '<td>' . $dept['total_attendance_records'] . '</td>';
        $html .= '<td>' . $dept['present_days'] . '</td>';
        $html .= '<td>' . $dept['late_days'] . '</td>';
        $html .= '<td>' . round($dept['avg_work_hours'] ?? 0, 1) . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    
    return ['html' => $html];
}

function generateLeavesReport($db, $date_from, $date_to, $employee_filter, $department_filter) {
    $where_conditions = ["l.start_date >= ? AND l.end_date <= ?"];
    $params = [$date_from, $date_to];
    
    if ($employee_filter > 0) {
        $where_conditions[] = "l.employee_id = ?";
        $params[] = $employee_filter;
    }
    
    if (!empty($department_filter)) {
        $where_conditions[] = "e.department = ?";
        $params[] = $department_filter;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    $query = "
        SELECT l.*, e.full_name, e.employee_id, e.department, 
               lt.type_name_ar, a.full_name as approved_by_name
        FROM leaves l 
        JOIN employees e ON l.employee_id = e.id 
        LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
        LEFT JOIN admins a ON l.approved_by = a.id
        $where_clause 
        ORDER BY l.start_date DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // إحصائيات الإجازات
    $summary = [
        'total_requests' => count($leaves),
        'approved_requests' => count(array_filter($leaves, fn($l) => $l['status'] === 'approved')),
        'pending_requests' => count(array_filter($leaves, fn($l) => $l['status'] === 'pending')),
        'rejected_requests' => count(array_filter($leaves, fn($l) => $l['status'] === 'rejected')),
        'total_days' => array_sum(array_column($leaves, 'days_count'))
    ];
    
    $html = generateSummaryCards($summary, 'leaves');
    
    $html .= '<h3 style="margin: 20px 0;">تفاصيل الإجازات</h3>';
    $html .= '<table class="table">';
    $html .= '<thead><tr>';
    $html .= '<th>الموظف</th><th>نوع الإجازة</th><th>من</th><th>إلى</th><th>الأيام</th><th>الحالة</th><th>المعتمد من</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($leaves as $leave) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($leave['full_name']) . '<br><small>' . htmlspecialchars($leave['employee_id']) . '</small></td>';
        $html .= '<td>' . htmlspecialchars($leave['type_name_ar'] ?: 'غير محدد') . '</td>';
        $html .= '<td>' . formatArabicDate($leave['start_date']) . '</td>';
        $html .= '<td>' . formatArabicDate($leave['end_date']) . '</td>';
        $html .= '<td>' . $leave['days_count'] . '</td>';
        
        $status_colors = [
            'pending' => '#ffc107',
            'approved' => '#28a745',
            'rejected' => '#dc3545'
        ];
        
        $status_texts = [
            'pending' => 'معلقة',
            'approved' => 'موافق عليها',
            'rejected' => 'مرفوضة'
        ];
        
        $status_color = $status_colors[$leave['status']] ?? '#6c757d';
        $status_text = $status_texts[$leave['status']] ?? $leave['status'];
        
        $html .= '<td><span style="color: ' . $status_color . '; font-weight: 600;">' . $status_text . '</span></td>';
        $html .= '<td>' . htmlspecialchars($leave['approved_by_name'] ?: '-') . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    
    return ['html' => $html, 'summary' => $summary];
}

function generateLateArrivalsReport($db, $date_from, $date_to, $employee_filter, $department_filter) {
    $where_conditions = ["a.attendance_date BETWEEN ? AND ?", "a.is_late = 1"];
    $params = [$date_from, $date_to];
    
    if ($employee_filter > 0) {
        $where_conditions[] = "a.employee_id = ?";
        $params[] = $employee_filter;
    }
    
    if (!empty($department_filter)) {
        $where_conditions[] = "e.department = ?";
        $params[] = $department_filter;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    $query = "
        SELECT a.*, e.full_name, e.employee_id, e.department
        FROM attendance a 
        JOIN employees e ON a.employee_id = e.id 
        $where_clause 
        ORDER BY a.attendance_date DESC, a.late_minutes DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $late_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $html = '<h3 style="margin: 20px 0;">تقرير حالات التأخير</h3>';
    $html .= '<table class="table">';
    $html .= '<thead><tr>';
    $html .= '<th>التاريخ</th><th>الموظف</th><th>القسم</th><th>وقت الحضور</th><th>دقائق التأخير</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($late_records as $record) {
        $html .= '<tr>';
        $html .= '<td>' . formatArabicDate($record['attendance_date']) . '</td>';
        $html .= '<td>' . htmlspecialchars($record['full_name']) . '<br><small>' . htmlspecialchars($record['employee_id']) . '</small></td>';
        $html .= '<td>' . htmlspecialchars($record['department'] ?: '-') . '</td>';
        $html .= '<td>' . formatArabicTime($record['check_in_time']) . '</td>';
        $html .= '<td><span style="color: #dc3545; font-weight: 600;">' . $record['late_minutes'] . ' دقيقة</span></td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    
    return ['html' => $html];
}

function generateSummaryCards($summary, $type) {
    $html = '<div class="summary-cards">';
    
    switch ($type) {
        case 'attendance':
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['total_records'] . '</div><div class="summary-label">إجمالي السجلات</div></div>';
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['present_days'] . '</div><div class="summary-label">أيام الحضور</div></div>';
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['late_days'] . '</div><div class="summary-label">أيام التأخير</div></div>';
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['absent_days'] . '</div><div class="summary-label">أيام الغياب</div></div>';
            $html .= '<div class="summary-card"><div class="summary-number">' . round($summary['avg_work_hours'], 1) . '</div><div class="summary-label">متوسط ساعات العمل</div></div>';
            break;
            
        case 'leaves':
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['total_requests'] . '</div><div class="summary-label">إجمالي الطلبات</div></div>';
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['approved_requests'] . '</div><div class="summary-label">موافق عليها</div></div>';
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['pending_requests'] . '</div><div class="summary-label">معلقة</div></div>';
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['rejected_requests'] . '</div><div class="summary-label">مرفوضة</div></div>';
            $html .= '<div class="summary-card"><div class="summary-number">' . $summary['total_days'] . '</div><div class="summary-label">إجمالي أيام الإجازة</div></div>';
            break;
    }
    
    $html .= '</div>';
    return $html;
}
?>