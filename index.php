files["index.php"] = r'''<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config/database.php';

$categories = ['Manager', 'Driver', 'Turn Worker', 'Home Worker', 'Tea Worker'];
$categoryLabels = [
    'Manager' => 'Managers',
    'Driver' => 'Drivers',
    'Turn Worker' => 'Turn Workers',
    'Home Worker' => 'Home Workers',
    'Tea Worker' => 'Tea Workers',
];

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function money($value): string {
    return 'KES ' . number_format((float)$value, 2);
}
function redirectTo(string $query = ''): never {
    header('Location: index.php' . ($query ? '?' . $query : ''));
    exit;
}
function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
function getTeaRate(PDO $pdo): float {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tea_rate_per_kg'");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value !== false ? (float)$value : 10.0;
}
function getTeaKg(PDO $pdo, int $workerId, int $month, int $year): float {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(kilograms),0) FROM tea_daily_records WHERE worker_id=? AND picking_date BETWEEN ? AND ?');
    $stmt->execute([$workerId, $start, $end]);
    return (float)$stmt->fetchColumn();
}
function ensurePayroll(PDO $pdo, int $workerId, int $month, int $year, float $gross): array {
    $stmt = $pdo->prepare('SELECT * FROM payroll_records WHERE worker_id=? AND payroll_month=? AND payroll_year=?');
    $stmt->execute([$workerId, $month, $year]);
    $row = $stmt->fetch();
    if (!$row) {
        $ins = $pdo->prepare("INSERT INTO payroll_records (worker_id,payroll_month,payroll_year,gross_earnings,advance_pay,end_month_pay,total_paid,balance,payment_status) VALUES (?,?,?,?,0,0,0,?,'Not Paid')");
        $ins->execute([$workerId, $month, $year, $gross, $gross]);
        $stmt->execute([$workerId, $month, $year]);
        $row = $stmt->fetch();
    } else {
        $totalPaid = (float)$row['advance_pay'] + (float)$row['end_month_pay'];
        $balance = max(0, $gross - $totalPaid);
        $status = $totalPaid <= 0 ? 'Not Paid' : ($balance <= 0.00001 ? 'Paid' : 'Partially Paid');
        $upd = $pdo->prepare('UPDATE payroll_records SET gross_earnings=?,total_paid=?,balance=?,payment_status=? WHERE id=?');
        $upd->execute([$gross, $totalPaid, $balance, $status, $row['id']]);
        $stmt->execute([$workerId, $month, $year]);
        $row = $stmt->fetch();
    }
    return $row;
}

try {
    $pdo = db();
} catch (Throwable $ex) {
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Database setup required</title><link rel="stylesheet" href="assets/style.css"></head><body class="setup-body"><div class="setup-card"><h1>Database connection failed</h1><p>Check that MySQL is running in XAMPP, import <code>database/home_workers_management.sql</code> in phpMyAdmin, and confirm the settings in <code>config/database.php</code>.</p><details><summary>Technical message</summary><pre>' . e($ex->getMessage()) . '</pre></details></div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_worker') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            $category = (string)($_POST['category'] ?? '');
            $phone = trim((string)($_POST['phone'] ?? ''));
            $employmentDate = trim((string)($_POST['employment_date'] ?? ''));
            $salary = (float)($_POST['monthly_salary'] ?? 0);
            if ($name === '' || !in_array($category, $categories, true)) {
                throw new RuntimeException('Enter a worker name and choose a valid category.');
            }
            if ($salary < 0) {
                throw new RuntimeException('Salary cannot be negative.');
            }
            if ($category === 'Tea Worker') {
                $salary = 0;
            }
            $code = trim((string)($_POST['worker_code'] ?? ''));
            if ($code === '') {
                $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $category), 0, 3)) . '-' . strtoupper(bin2hex(random_bytes(3)));
            }
            $stmt = $pdo->prepare('INSERT INTO workers (worker_code,full_name,category,phone,employment_date,monthly_salary) VALUES (?,?,?,?,NULLIF(?, \'\'),?)');
            $stmt->execute([$code, $name, $category, $phone ?: null, $employmentDate, $salary]);
            flash('success', 'Worker added successfully. Worker ID: ' . $code);
            redirectTo('category=' . urlencode($category));
        }

        if ($action === 'deactivate_worker') {
            $id = (int)($_POST['worker_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE workers SET is_active=0 WHERE id=?');
            $stmt->execute([$id]);
            flash('success', 'Worker deactivated. Historical payroll records have been preserved.');
            redirectTo('category=' . urlencode((string)($_POST['return_category'] ?? 'Manager')));
        }

        if ($action === 'update_worker') {
            $id = (int)($_POST['worker_id'] ?? 0);
            $name = trim((string)($_POST['full_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $salary = max(0, (float)($_POST['monthly_salary'] ?? 0));
            $stmt = $pdo->prepare("UPDATE workers SET full_name=?, phone=?, monthly_salary=CASE WHEN category='Tea Worker' THEN 0 ELSE ? END WHERE id=?");
            $stmt->execute([$name, $phone ?: null, $salary, $id]);
            flash('success', 'Worker details updated.');
            redirectTo('category=' . urlencode((string)($_POST['return_category'] ?? 'Manager')));
        }

        if ($action === 'save_payroll') {
            $workerId = (int)($_POST['worker_id'] ?? 0);
            $month = (int)($_POST['month'] ?? date('n'));
            $year = (int)($_POST['year'] ?? date('Y'));
            $advance = (float)($_POST['advance_pay'] ?? 0);
            $endMonth = (float)($_POST['end_month_pay'] ?? 0);
            if ($advance < 0 || $endMonth < 0 || $month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
                throw new RuntimeException('Enter valid month, year, and non-negative payment amounts.');
            }
            $w = $pdo->prepare('SELECT * FROM workers WHERE id=? AND is_active=1');
            $w->execute([$workerId]);
            $worker = $w->fetch();
            if (!$worker) {
                throw new RuntimeException('Worker not found or inactive.');
            }
            $gross = $worker['category'] === 'Tea Worker'
                ? getTeaKg($pdo, $workerId, $month, $year) * getTeaRate($pdo)
                : (float)$worker['monthly_salary'];
            if ($advance + $endMonth > $gross + 0.00001) {
                throw new RuntimeException('Total payments cannot exceed the amount due. Check the salary or tea records first.');
            }

            $existing = ensurePayroll($pdo, $workerId, $month, $year, $gross);
            $oldAdvance = (float)$existing['advance_pay'];
            $oldEndMonth = (float)$existing['end_month_pay'];

            $total = $advance + $endMonth;
            $balance = max(0, $gross - $total);
            $status = $total <= 0 ? 'Not Paid' : ($balance <= 0.00001 ? 'Paid' : 'Partially Paid');
            $stmt = $pdo->prepare('UPDATE payroll_records SET gross_earnings=?,advance_pay=?,end_month_pay=?,total_paid=?,balance=?,payment_status=? WHERE id=?');
            $stmt->execute([$gross, $advance, $endMonth, $total, $balance, $status, $existing['id']]);

            // Record only changes as transactions to preserve an audit trail.
            if (abs($advance - $oldAdvance) > 0.00001) {
                $delta = $advance - $oldAdvance;
                $type = $delta >= 0 ? 'Advance' : 'Adjustment';
                $t = $pdo->prepare('INSERT INTO payment_transactions (payroll_record_id,payment_type,amount,payment_date,notes) VALUES (?,?,?,?,?)');
                $t->execute([$existing['id'], $type, abs($delta), date('Y-m-d'), 'Advance field updated; change ' . ($delta >= 0 ? 'added' : 'reduced')]);
            }
            if (abs($endMonth - $oldEndMonth) > 0.00001) {
                $delta = $endMonth - $oldEndMonth;
                $type = $delta >= 0 ? 'End-Month' : 'Adjustment';
                $t = $pdo->prepare('INSERT INTO payment_transactions (payroll_record_id,payment_type,amount,payment_date,notes) VALUES (?,?,?,?,?)');
                $t->execute([$existing['id'], $type, abs($delta), date('Y-m-d'), 'End-month field updated; change ' . ($delta >= 0 ? 'added' : 'reduced')]);
            }
            flash('success', 'Payroll saved for ' . $worker['full_name'] . '. Status: ' . $status);
            redirectTo('category=' . urlencode($worker['category']) . '&month=' . $month . '&year=' . $year);
        }

        if ($action === 'save_tea_day') {
            $workerId = (int)($_POST['worker_id'] ?? 0);
            $date = (string)($_POST['picking_date'] ?? '');
            $kg = (float)($_POST['kilograms'] ?? 0);
            $month = (int)date('n', strtotime($date ?: 'now'));
            $year = (int)date('Y', strtotime($date ?: 'now'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $kg < 0) {
                throw new RuntimeException('Enter a valid picking date and a non-negative kilogram value.');
            }
            $w = $pdo->prepare("SELECT id FROM workers WHERE id=? AND category='Tea Worker' AND is_active=1");
            $w->execute([$workerId]);
            if (!$w->fetch()) {
                throw new RuntimeException('Tea worker not found or inactive.');
            }
            $rate = getTeaRate($pdo);
            $stmt = $pdo->prepare('INSERT INTO tea_daily_records (worker_id,picking_date,kilograms,rate_per_kg,daily_earnings) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE kilograms=VALUES(kilograms),rate_per_kg=VALUES(rate_per_kg),daily_earnings=VALUES(daily_earnings)');
            $stmt->execute([$workerId, $date, $kg, $rate, $kg * $rate]);
            flash('success', 'Tea picking record saved.');
            redirectTo('category=Tea+Worker&month=' . $month . '&year=' . $year);
        }

        if ($action === 'save_tea_month') {
            $workerId = (int)($_POST['worker_id'] ?? 0);
            $month = (int)($_POST['month'] ?? date('n'));
            $year = (int)($_POST['year'] ?? date('Y'));
            $days = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $rate = getTeaRate($pdo);
            $check = $pdo->prepare("SELECT id FROM workers WHERE id=? AND category='Tea Worker' AND is_active=1");
            $check->execute([$workerId]);
            if (!$check->fetch()) {
                throw new RuntimeException('Tea worker not found or inactive.');
            }
            $stmt = $pdo->prepare('INSERT INTO tea_daily_records (worker_id,picking_date,kilograms,rate_per_kg,daily_earnings) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE kilograms=VALUES(kilograms),rate_per_kg=VALUES(rate_per_kg),daily_earnings=VALUES(daily_earnings)');
            for ($day = 1; $day <= $days; $day++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $field = 'kg_' . $day;
                $kg = isset($_POST[$field]) ? (float)$_POST[$field] : 0;
                if ($kg < 0) {
                    throw new RuntimeException('Kilograms cannot be negative.');
                }
                // Empty values are saved as zero for the selected day.
                $stmt->execute([$workerId, $date, $kg, $rate, $kg * $rate]);
            }
            // Refresh the payroll gross without altering saved payment amounts.
            $gross = getTeaKg($pdo, $workerId, $month, $year) * $rate;
            ensurePayroll($pdo, $workerId, $month, $year, $gross);
            flash('success', 'Monthly tea-picking records saved.');
            redirectTo('category=Tea+Worker&month=' . $month . '&year=' . $year);
        }

        if ($action === 'save_rate') {
            $rate = (float)($_POST['tea_rate'] ?? 10);
            if (abs($rate - 10.0) > 0.00001) {
                throw new RuntimeException('The tea rate is fixed at KES 10 per kg in this version.');
            }
            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value='10.00' WHERE setting_key='tea_rate_per_kg'");
            $stmt->execute();
            flash('success', 'Tea rate confirmed at KES 10 per kg.');
            redirectTo('category=Tea+Worker');
        }
    } catch (Throwable $ex) {
        flash('error', $ex instanceof PDOException && (string)$ex->getCode() === '23000'
            ? 'That Worker ID already exists. Choose a different ID.'
            : $ex->getMessage());
        redirectTo('category=' . urlencode((string)($_POST['return_category'] ?? $_POST['category'] ?? 'Manager')));
    }
}

$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year = max(2000, min(2100, (int)($_GET['year'] ?? date('Y'))));
$selectedCategory = (string)($_GET['category'] ?? 'Dashboard');
if (!in_array($selectedCategory, array_merge(['Dashboard'], $categories), true)) {
    $selectedCategory = 'Dashboard';
}
$search = trim((string)($_GET['search'] ?? ''));
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$periodStart = sprintf('%04d-%02d-01', $year, $month);
$periodEnd = date('Y-m-t', strtotime($periodStart));
$teaRate = getTeaRate($pdo);

$activeWorkers = (int)$pdo->query('SELECT COUNT(*) FROM workers WHERE is_active=1')->fetchColumn();
$categoryCounts = [];
foreach ($categories as $cat) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM workers WHERE is_active=1 AND category=?');
    $stmt->execute([$cat]);
    $categoryCounts[$cat] = (int)$stmt->fetchColumn();
}
$allTeaKg = (float)$pdo->query("SELECT COALESCE(SUM(t.kilograms),0) FROM tea_daily_records t JOIN workers w ON w.id=t.worker_id WHERE t.picking_date BETWEEN " . $pdo->quote($periodStart) . " AND " . $pdo->quote($periodEnd))->fetchColumn();

$workers = [];
if (in_array($selectedCategory, $categories, true)) {
    $sql = 'SELECT * FROM workers WHERE category=? AND is_active=1';
    $params = [$selectedCategory];
    if ($search !== '') {
        $sql .= ' AND (full_name LIKE ? OR worker_code LIKE ? OR CAST(id AS CHAR) LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= ' ORDER BY full_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $workers = $stmt->fetchAll();
}

$payrollRows = [];
if (in_array($selectedCategory, $categories, true)) {
    foreach ($workers as $worker) {
        $gross = $worker['category'] === 'Tea Worker'
            ? getTeaKg($pdo, (int)$worker['id'], $month, $year) * $teaRate
            : (float)$worker['monthly_salary'];
        $payrollRows[(int)$worker['id']] = ensurePayroll($pdo, (int)$worker['id'], $month, $year, $gross);
    }
}

$reportRows = [];
$reportSql = 'SELECT w.id,w.worker_code,w.full_name,w.category,w.monthly_salary,p.* FROM workers w LEFT JOIN payroll_records p ON p.worker_id=w.id AND p.payroll_month=? AND p.payroll_year=? ORDER BY w.category,w.full_name';
$reportStmt = $pdo->prepare($reportSql);
$reportStmt->execute([$month, $year]);
$allForReport = $reportStmt->fetchAll();
$reportTotals = ['gross'=>0.0,'advance'=>0.0,'end'=>0.0,'paid'=>0.0,'balance'=>0.0,'kg'=>0.0];
foreach ($allForReport as $r) {
    if ($r['category'] === 'Tea Worker') {
        $kg = getTeaKg($pdo, (int)$r['id'], $month, $year);
        $gross = $kg * $teaRate;
        $reportTotals['kg'] += $kg;
    } else {
        $gross = (float)$r['monthly_salary'];
    }
    $advance = (float)($r['advance_pay'] ?? 0);
    $end = (float)($r['end_month_pay'] ?? 0);
    $paid = $advance + $end;
    $balance = max(0, $gross - $paid);
    $status = $paid <= 0 ? 'Not Paid' : ($balance <= 0.00001 ? 'Paid' : 'Partially Paid');
    $reportTotals['gross'] += $gross;
    $reportTotals['advance'] += $advance;
    $reportTotals['end'] += $end;
    $reportTotals['paid'] += $paid;
    $reportTotals['balance'] += $balance;
    $reportRows[] = $r + ['calculated_gross'=>$gross,'calculated_advance'=>$advance,'calculated_end'=>$end,'calculated_paid'=>$paid,'calculated_balance'=>$balance,'calculated_status'=>$status];
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home Workers & Payroll</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">HW</div>
            <div><strong>HomeWorks</strong><span>Workers & Payroll</span></div>
        </div>
        <div class="nav-label">WORKSPACE</div>
        <a class="nav-link <?= $selectedCategory === 'Dashboard' ? 'active' : '' ?>" href="index.php"><span>▦</span> Dashboard</a>
        <div class="nav-label">WORKER CATEGORIES</div>
        <?php foreach ($categories as $cat): ?>
            <a class="nav-link <?= $selectedCategory === $cat ? 'active' : '' ?>" href="index.php?category=<?= urlencode($cat) ?>&month=<?= $month ?>&year=<?= $year ?>">
                <span><?= $cat === 'Tea Worker' ? '♧' : ($cat === 'Driver' ? '↗' : '◉') ?></span><?= e($categoryLabels[$cat]) ?>
                <b class="nav-count"><?= $categoryCounts[$cat] ?></b>
            </a>
        <?php endforeach; ?>
        <div class="sidebar-note"><span class="live-dot"></span> Local database connected</div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <div class="eyebrow">HOME WORKERS MANAGEMENT</div>
                <h1><?= $selectedCategory === 'Dashboard' ? 'Dashboard overview' : e($categoryLabels[$selectedCategory]) ?></h1>
            </div>
            <div class="topbar-right">
                <div class="date-chip"><span>▣</span> <span id="currentDate"><?= date('D, d M Y') ?></span></div>
                <div class="clock-chip"><span class="clock-dot"></span><strong id="digitalClock"><?= date('H:i:s') ?></strong></div>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($selectedCategory === 'Dashboard'): ?>
            <section class="hero">
                <div class="hero-copy">
                    <span class="hero-kicker">PAYROLL CONTROL CENTER</span>
                    <h2>Good work starts with<br><em>organized records.</em></h2>
                    <p>Manage workers, track tea picking, record payments, and print monthly payroll reports from one simple workspace.</p>
                    <a class="button button-light" href="index.php?category=Manager">Manage workers <span>→</span></a>
                </div>
                <div class="hero-art" aria-hidden="true"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="hero-coin">KES</div><div class="hero-mini-card">PAYROLL <b>✓</b></div></div>
            </section>

            <form class="period-toolbar" method="get">
                <div><strong>Monthly overview</strong><span>Choose a period to view summary totals</span></div>
                <div class="period-controls">
                    <select name="month"><?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $month===$m?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select>
                    <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>" aria-label="Year">
                    <button class="button button-dark" type="submit">Update</button>
                </div>
            </form>

            <section class="stats-grid">
                <div class="stat-card stat-primary"><div class="stat-top"><span>Active workers</span><span class="stat-icon">♙</span></div><strong><?= $activeWorkers ?></strong><small>Across all categories</small><div class="stat-bar"><i style="width:76%"></i></div></div>
                <div class="stat-card"><div class="stat-top"><span>Monthly payroll due</span><span class="stat-icon purple">KES</span></div><strong><?= money($reportTotals['gross']) ?></strong><small><?= e($monthName . ' ' . $year) ?></small><div class="stat-bar purple-bar"><i style="width:64%"></i></div></div>
                <div class="stat-card"><div class="stat-top"><span>Payments recorded</span><span class="stat-icon green">✓</span></div><strong><?= money($reportTotals['paid']) ?></strong><small>Advance + end-month</small><div class="stat-bar green-bar"><i style="width:<?= $reportTotals['gross'] > 0 ? min(100, round($reportTotals['paid']/$reportTotals['gross']*100)) : 0 ?>%"></i></div></div>
                <div class="stat-card"><div class="stat-top"><span>Outstanding balance</span><span class="stat-icon orange">↗</span></div><strong><?= money($reportTotals['balance']) ?></strong><small>Still to be paid</small><div class="stat-bar orange-bar"><i style="width:<?= $reportTotals['gross'] > 0 ? min(100, round($reportTotals['balance']/$reportTotals['gross']*100)) : 0 ?>%"></i></div></div>
            </section>

            <section class="section-heading"><div><h2>Worker categories</h2><p>Open a category to manage workers and monthly pay.</p></div></section>
            <section class="category-grid">
                <?php
                $catMeta = [
                    'Manager'=>['01','Leadership & supervision','linear-gradient(135deg,#5966e9,#8b5cf6)'],
                    'Driver'=>['02','Transport & logistics','linear-gradient(135deg,#0f9f9a,#55c5b6)'],
                    'Turn Worker'=>['03','General field operations','linear-gradient(135deg,#f29b43,#f7c66a)'],
                    'Home Worker'=>['04','Household operations','linear-gradient(135deg,#e66b91,#f19ab4)'],
                    'Tea Worker'=>['05','Tea picking & kilograms','linear-gradient(135deg,#2f9b67,#83c878)'],
                ];
                foreach ($categories as $cat):
                    [$number,$desc,$gradient] = $catMeta[$cat];
                ?>
                <a class="category-card" href="index.php?category=<?= urlencode($cat) ?>&month=<?= $month ?>&year=<?= $year ?>">
                    <div class="category-icon" style="background:<?= e($gradient) ?>"><?= $number ?></div>
                    <div class="category-card-text"><h3><?= e($categoryLabels[$cat]) ?></h3><p><?= e($desc) ?></p><span><?= $categoryCounts[$cat] ?> active worker<?= $categoryCounts[$cat]===1?'':'s' ?></span></div>
                    <div class="card-arrow">↗</div>
                </a>
                <?php endforeach; ?>
            </section>

            <section class="bottom-grid">
                <div class="panel">
                    <div class="panel-heading"><div><h2>Tea picking summary</h2><p><?= e($monthName . ' ' . $year) ?> production</p></div><span class="pill pill-green">KES <?= number_format($teaRate,2) ?>/kg</span></div>
                    <div class="tea-summary"><div class="tea-leaf">♧</div><div><strong><?= number_format($allTeaKg, 2) ?> kg</strong><span>Total kilograms recorded</span></div><div class="tea-earnings"><strong><?= money($allTeaKg*$teaRate) ?></strong><span>Calculated earnings</span></div></div>
                    <a class="text-link" href="index.php?category=Tea+Worker&month=<?= $month ?>&year=<?= $year ?>">Open tea register →</a>
                </div>
                <div class="panel report-panel"><div class="report-symbol">▤</div><div><h2>Monthly payroll report</h2><p>Review totals, balances, and worker payment status.</p></div><a class="button button-dark" href="index.php?category=reports&month=<?= $month ?>&year=<?= $year ?>" onclick="event.preventDefault();window.location.href='index.php?category=reports&month=<?= $month ?>&year=<?= $year ?>'">View report</a></div>
            </section>
        <?php elseif ($selectedCategory === 'reports' || isset($_GET['report'])): ?>
            <section class="period-toolbar">
                <div><strong>Monthly payroll report</strong><span>Saved records for <?= e($monthName . ' ' . $year) ?></span></div>
                <form class="period-controls" method="get">
                    <input type="hidden" name="category" value="reports">
                    <select name="month"><?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $month===$m?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select>
                    <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>" aria-label="Year">
                    <button class="button button-dark">Show</button>
                </form>
            </section>
            <div class="report-title print-only"><h1>Home Workers Monthly Payroll Report</h1><p><?= e($monthName . ' ' . $year) ?></p></div>
            <section class="stats-grid report-stats">
                <div class="stat-card"><span>Gross due</span><strong><?= money($reportTotals['gross']) ?></strong></div>
                <div class="stat-card"><span>Advances</span><strong><?= money($reportTotals['advance']) ?></strong></div>
                <div class="stat-card"><span>End-month paid</span><strong><?= money($reportTotals['end']) ?></strong></div>
                <div class="stat-card"><span>Outstanding</span><strong><?= money($reportTotals['balance']) ?></strong></div>
            </section>
            <section class="panel table-panel">
                <div class="panel-heading"><div><h2>Payroll register</h2><p>All categories · <?= e($monthName . ' ' . $year) ?></p></div><button class="button button-dark no-print" onclick="window.print()">▤ Print report</button></div>
                <div class="table-wrap"><table class="data-table report-table"><thead><tr><th>ID</th><th>Worker</th><th>Category</th><th>KG</th><th>Gross due</th><th>Advance</th><th>End-month</th><th>Total paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($reportRows as $r):
                    $kg = $r['category']==='Tea Worker' ? getTeaKg($pdo,(int)$r['id'],$month,$year) : null;
                ?>
                    <tr><td><span class="worker-code"><?= e($r['worker_code']) ?></span></td><td><strong><?= e($r['full_name']) ?></strong></td><td><?= e($categoryLabels[$r['category']] ?? $r['category']) ?></td><td><?= $kg === null ? '—' : number_format($kg,2) ?></td><td><?= money($r['calculated_gross']) ?></td><td><?= money($r['calculated_advance']) ?></td><td><?= money($r['calculated_end']) ?></td><td><?= money($r['calculated_paid']) ?></td><td><?= money($r['calculated_balance']) ?></td><td><span class="status <?= strtolower(str_replace(' ','-',$r['calculated_status'])) ?>"><?= e($r['calculated_status']) ?></span></td></tr>
                <?php endforeach; if (!$reportRows): ?><tr><td colspan="10" class="empty-cell">No workers found.</td></tr><?php endif; ?>
                </tbody><tfoot><tr><th colspan="3">TOTAL</th><th><?= number_format($reportTotals['kg'],2) ?></th><th><?= money($reportTotals['gross']) ?></th><th><?= money($reportTotals['advance']) ?></th><th><?= money($reportTotals['end']) ?></th><th><?= money($reportTotals['paid']) ?></th><th><?= money($reportTotals['balance']) ?></th><th></th></tr></tfoot></table></div>
            </section>
        <?php else: ?>
            <section class="period-toolbar">
                <div><strong><?= e($categoryLabels[$selectedCategory]) ?> register</strong><span>Search workers, manage records, and enter monthly payments.</span></div>
                <div class="period-controls">
                    <form class="search-form" method="get">
                        <input type="hidden" name="category" value="<?= e($selectedCategory) ?>">
                        <input type="hidden" name="month" value="<?= $month ?>"><input type="hidden" name="year" value="<?= $year ?>">
                        <input type="search" name="search" placeholder="Search name or ID..." value="<?= e($search) ?>">
                        <button class="button button-outline">Search</button>
                    </form>
                    <button class="button button-dark" type="button" onclick="openWorkerModal()">＋ Add worker</button>
                </div>
            </section>
            <section class="period-toolbar compact-toolbar">
                <div><strong>Payroll period</strong><span><?= e($monthName . ' ' . $year) ?></span></div>
                <form class="period-controls" method="get">
                    <input type="hidden" name="category" value="<?= e($selectedCategory) ?>">
                    <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= e($search) ?>"><?php endif; ?>
                    <select name="month"><?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $month===$m?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select>
                    <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>" aria-label="Year">
                    <button class="button button-dark">Load period</button>
                </form>
            </section>

            <?php if ($selectedCategory === 'Tea Worker'): ?>
                <section class="tea-rate-banner"><div class="rate-icon">♧</div><div><strong>Tea picking rate is fixed at <?= money($teaRate) ?> per kg</strong><span>Rate is stored in the database and applied on the server.</span></div><div class="rate-total"><span>All workers · <?= e($monthName) ?></span><strong><?= number_format($allTeaKg,2) ?> kg</strong></div></section>
                <?php foreach ($workers as $worker):
                    $wid = (int)$worker['id'];
                    $teaPayroll = $payrollRows[$wid];
                    $workerKg = getTeaKg($pdo, $wid, $month, $year);
                    $gross = $workerKg * $teaRate;
                    $dailyStmt = $pdo->prepare('SELECT DAY(picking_date) AS day_no,kilograms FROM tea_daily_records WHERE worker_id=? AND picking_date BETWEEN ? AND ?');
                    $dailyStmt->execute([$wid,$periodStart,$periodEnd]);
                    $dailyValues = [];
                    foreach ($dailyStmt->fetchAll() as $dr) $dailyValues[(int)$dr['day_no']] = (float)$dr['kilograms'];
                ?>
                <section class="panel tea-worker-panel">
                    <div class="panel-heading"><div class="worker-heading"><div class="avatar avatar-green"><?= e(strtoupper(substr($worker['full_name'],0,1))) ?></div><div><h2><?= e($worker['full_name']) ?></h2><p><span class="worker-code"><?= e($worker['worker_code']) ?></span> · <?= number_format($workerKg,2) ?> kg · <?= money($gross) ?> gross</p></div></div><div class="panel-actions"><span class="status <?= strtolower(str_replace(' ','-',$teaPayroll['payment_status'])) ?>"><?= e($teaPayroll['payment_status']) ?></span><button class="icon-button" title="Edit worker" onclick='editWorker(<?= json_encode(['id'=>$wid,'name'=>$worker['full_name'],'phone'=>$worker['phone'],'salary'=>$worker['monthly_salary'],'code'=>$worker['worker_code'],'category'=>$selectedCategory], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✎</button><form method="post" onsubmit="return confirm('Deactivate this worker? Historical records will remain.')" class="inline-form"><input type="hidden" name="action" value="deactivate_worker"><input type="hidden" name="worker_id" value="<?= $wid ?>"><input type="hidden" name="return_category" value="<?= e($selectedCategory) ?>"><button class="icon-button danger" title="Deactivate worker">×</button></form></div></div>
                    <form method="post" class="tea-month-form">
                        <input type="hidden" name="action" value="save_tea_month"><input type="hidden" name="worker_id" value="<?= $wid ?>"><input type="hidden" name="month" value="<?= $month ?>"><input type="hidden" name="year" value="<?= $year ?>">
                        <div class="table-wrap tea-table-wrap"><table class="data-table tea-table"><thead><tr><th class="sticky-name">Picking date</th><?php for($d=1;$d<=$daysInMonth;$d++): ?><th><span><?= $d ?></span><small><?= date('D',strtotime(sprintf('%04d-%02d-%02d',$year,$month,$d))) ?></small></th><?php endfor; ?><th>Total KG</th></tr></thead><tbody><tr><th class="sticky-name">Kilograms</th><?php for($d=1;$d<=$daysInMonth;$d++): ?><td><input class="kg-input" type="number" min="0" step="0.01" name="kg_<?= $d ?>" value="<?= isset($dailyValues[$d]) && $dailyValues[$d] != 0 ? e(number_format($dailyValues[$d],2,'.','')) : '' ?>" placeholder="—" aria-label="Kilograms on day <?= $d ?>"></td><?php endfor; ?><td class="kg-total"><strong><?= number_format($workerKg,2) ?></strong></td></tr></tbody></table></div>
                        <div class="tea-save-row"><span>Rate: <strong><?= money($teaRate) ?>/kg</strong> · Gross: <strong><?= money($gross) ?></strong></span><button class="button button-outline">Save daily kilograms</button></div>
                    </form>
                    <form method="post" class="payroll-form">
                        <input type="hidden" name="action" value="save_payroll"><input type="hidden" name="worker_id" value="<?= $wid ?>"><input type="hidden" name="month" value="<?= $month ?>"><input type="hidden" name="year" value="<?= $year ?>">
                        <div class="pay-field"><label>Advance pay (KES)</label><input type="number" name="advance_pay" min="0" step="0.01" value="<?= e($teaPayroll['advance_pay']) ?>" required></div>
                        <div class="pay-field"><label>End-month pay (KES)</label><input type="number" name="end_month_pay" min="0" step="0.01" value="<?= e($teaPayroll['end_month_pay']) ?>" required></div>
                        <div class="pay-summary"><span>Total paid</span><strong><?= money($teaPayroll['total_paid']) ?></strong></div>
                        <div class="pay-summary balance-summary"><span>Balance</span><strong><?= money(max(0,$gross-(float)$teaPayroll['total_paid'])) ?></strong></div>
                        <div class="pay-actions"><label class="check-option"><input type="checkbox" <?= $teaPayroll['payment_status']==='Paid'?'checked':'' ?> disabled> Paid</label><label class="check-option"><input type="checkbox" <?= $teaPayroll['payment_status']==='Not Paid'?'checked':'' ?> disabled> Not Paid</label><span class="status <?= strtolower(str_replace(' ','-',$teaPayroll['payment_status'])) ?>"><?= e($teaPayroll['payment_status']) ?></span><button class="button button-dark">Save payroll</button></div>
                    </form>
                </section>
                <?php endforeach; ?>
                <?php if (!$workers): ?><div class="empty-state"><div>♧</div><h3>No tea workers found</h3><p>Add a tea worker to start entering daily kilograms.</p><button class="button button-dark" onclick="openWorkerModal()">＋ Add tea worker</button></div><?php endif; ?>
            <?php else: ?>
                <section class="panel table-panel">
                    <div class="panel-heading"><div><h2>Worker payroll</h2><p><?= count($workers) ?> active workers shown · <?= e($monthName . ' ' . $year) ?></p></div><a class="button button-outline no-print" href="index.php?category=reports&month=<?= $month ?>&year=<?= $year ?>">▤ Monthly report</a></div>
                    <div class="table-wrap"><table class="data-table"><thead><tr><th>Worker ID</th><th>Worker</th><th>Fixed monthly salary</th><th>Advance pay</th><th>End-month pay</th><th>Total paid</th><th>Balance</th><th>Paid / Not paid</th><th>Actions</th></tr></thead><tbody>
                    <?php foreach ($workers as $worker):
                        $wid = (int)$worker['id'];
                        $p = $payrollRows[$wid];
                    ?>
                        <tr>
                            <td><span class="worker-code"><?= e($worker['worker_code']) ?></span></td>
                            <td><div class="worker-cell"><div class="avatar"><?= e(strtoupper(substr($worker['full_name'],0,1))) ?></div><div><strong><?= e($worker['full_name']) ?></strong><small><?= e($worker['phone'] ?: 'No phone added') ?></small></div></div></td>
                            <td class="salary-cell"><?= money($worker['monthly_salary']) ?></td>
                            <td colspan="5">
                                <form method="post" class="row-payroll-form">
                                    <input type="hidden" name="action" value="save_payroll"><input type="hidden" name="worker_id" value="<?= $wid ?>"><input type="hidden" name="month" value="<?= $month ?>"><input type="hidden" name="year" value="<?= $year ?>">
                                    <input class="inline-money" type="number" name="advance_pay" min="0" step="0.01" value="<?= e($p['advance_pay']) ?>" aria-label="Advance pay">
                                    <input class="inline-money" type="number" name="end_month_pay" min="0" step="0.01" value="<?= e($p['end_month_pay']) ?>" aria-label="End month pay">
                                    <span class="money-value"><?= money($p['total_paid']) ?></span>
                                    <span class="money-value balance-value"><?= money($p['balance']) ?></span>
                                    <span class="status <?= strtolower(str_replace(' ','-',$p['payment_status'])) ?>"><?= e($p['payment_status']) ?></span>
                                    <button class="button button-small button-dark">Save</button>
                                </form>
                            </td>
                            <td><div class="row-actions"><button class="icon-button" title="Edit worker" onclick='editWorker(<?= json_encode(['id'=>$wid,'name'=>$worker['full_name'],'phone'=>$worker['phone'],'salary'=>$worker['monthly_salary'],'code'=>$worker['worker_code'],'category'=>$selectedCategory], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✎</button><form method="post" onsubmit="return confirm('Deactivate this worker? Historical records will remain.')" class="inline-form"><input type="hidden" name="action" value="deactivate_worker"><input type="hidden" name="worker_id" value="<?= $wid ?>"><input type="hidden" name="return_category" value="<?= e($selectedCategory) ?>"><button class="icon-button danger" title="Deactivate worker">×</button></form></div></td>
                        </tr>
                    <?php endforeach; if (!$workers): ?><tr><td colspan="9" class="empty-cell">No workers found. Use “Add worker” to create your first record.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
        <footer class="footer"><span>HomeWorks · Local payroll workspace</span><span>Data stored on this computer via XAMPP</span></footer>
    </main>
</div>

<div class="modal-backdrop" id="workerModal" aria-hidden="true">
    <div class="modal-card">
        <div class="modal-heading"><div><span class="eyebrow">WORKER DETAILS</span><h2 id="workerModalTitle">Add new worker</h2></div><button class="modal-close" type="button" onclick="closeWorkerModal()">×</button></div>
        <form method="post" id="workerForm">
            <input type="hidden" name="action" id="workerAction" value="add_worker">
            <input type="hidden" name="worker_id" id="editWorkerId">
            <input type="hidden" name="return_category" value="<?= e(in_array($selectedCategory,$categories,true)?$selectedCategory:'Manager') ?>">
            <div class="form-grid">
                <div class="form-field full"><label for="workerName">Full name *</label><input id="workerName" name="full_name" required maxlength="120" placeholder="Enter full name"></div>
                <div class="form-field"><label for="workerCode">Worker ID (optional)</label><input id="workerCode" name="worker_code" maxlength="30" placeholder="Auto-generated if blank"></div>
                <div class="form-field"><label for="workerCategory">Category *</label><select id="workerCategory" name="category" required><?php foreach($categories as $cat): ?><option value="<?= e($cat) ?>" <?= $selectedCategory===$cat?'selected':'' ?>><?= e($categoryLabels[$cat]) ?></option><?php endforeach; ?></select></div>
                <div class="form-field"><label for="workerPhone">Phone (optional)</label><input id="workerPhone" name="phone" maxlength="30" placeholder="+254..."></div>
                <div class="form-field"><label for="employmentDate">Employment date</label><input id="employmentDate" name="employment_date" type="date"></div>
                <div class="form-field full" id="salaryField"><label for="workerSalary">Fixed monthly salary (KES)</label><input id="workerSalary" name="monthly_salary" type="number" min="0" step="0.01" value="0"><small>Not used for tea workers; their earnings are based on kilograms × KES 10.</small></div>
            </div>
            <div class="modal-actions"><button class="button button-outline" type="button" onclick="closeWorkerModal()">Cancel</button><button class="button button-dark" id="workerSubmit" type="submit">Save worker</button></div>
        </form>
    </div>
</div>
<script src="app.js"></script>
</body>
</html>