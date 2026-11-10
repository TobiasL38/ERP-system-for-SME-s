<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'hr']);
$pageTitle = 'Attendance';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'record') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $date = post_string('attendance_date', 10);
            $timeIn = post_string('time_in', 8);
            $timeOut = post_string('time_out', 8);
            $status = post_string('status', 20);
            $notes = post_string('notes', 500);

            if ($empId < 1) throw new RuntimeException('Select an employee.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Enter a valid date.');
            if (!in_array($status, ['present', 'absent', 'late', 'leave'], true)) throw new RuntimeException('Select a valid status.');

            db()->prepare('INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, status, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE time_in=VALUES(time_in), time_out=VALUES(time_out), status=VALUES(status), notes=VALUES(notes), recorded_by=VALUES(recorded_by)')
                ->execute([$empId, $date, $timeIn ?: null, $timeOut ?: null, $status, $notes ?: null, current_user()['id']]);
            
            flash('success', 'Attendance recorded/updated.');
            redirect('attendance.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'The attendance record could not be saved.';
    }
}

$employees = db()->query('SELECT id, first_name, last_name, employee_number FROM employees WHERE status IN ("active", "on_leave") ORDER BY first_name')->fetchAll();
$records = db()->query('SELECT a.*, e.first_name, e.last_name, u.full_name AS recorder FROM attendance a JOIN employees e ON e.id = a.employee_id LEFT JOIN users u ON u.id = a.recorded_by ORDER BY a.attendance_date DESC, a.id DESC LIMIT 50')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>Log Attendance</h2><p>Record daily clock-in and clock-out.</p></div></div>
        <?php if (!$employees): ?>
            <div class="empty-state compact"><strong>No active employees</strong><p>Add an employee first.</p></div>
        <?php else: ?>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="record">
            <div><label for="employee_id">Employee *</label>
                <select id="employee_id" name="employee_id" required>
                    <option value="">Choose employee</option>
                    <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"><?= e($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_number'] . ')') ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field-grid">
                <div><label for="attendance_date">Date *</label><input id="attendance_date" name="attendance_date" type="date" required value="<?= date('Y-m-d') ?>"></div>
                <div><label for="status">Status *</label>
                    <select id="status" name="status" required>
                        <option value="present">Present</option>
                        <option value="late">Late</option>
                        <option value="absent">Absent</option>
                        <option value="leave">On Leave</option>
                    </select>
                </div>
            </div>
            <div class="field-grid">
                <div><label for="time_in">Time In</label><input id="time_in" name="time_in" type="time"></div>
                <div><label for="time_out">Time Out</label><input id="time_out" name="time_out" type="time"></div>
            </div>
            <div><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="500" placeholder="Optional reason for late/absent"></textarea></div>
            <button class="button button-primary" type="submit">Save Record</button>
        </form>
        <?php endif; ?>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Recent Attendance</h2><p>Latest 50 records</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Date</th><th>Employee</th><th>Status</th><th>Time</th></tr></thead><tbody>
        <?php foreach ($records as $rec): ?>
            <tr>
                <td><?= e(format_day($rec['attendance_date'])) ?></td>
                <td><strong><?= e($rec['first_name'] . ' ' . $rec['last_name']) ?></strong><small class="table-subtext">by <?= e($rec['recorder'] ?? 'System') ?></small></td>
                <td><span class="pill pill-<?= $rec['status'] === 'present' ? 'success' : ($rec['status'] === 'late' ? 'warning' : ($rec['status'] === 'leave' ? 'info' : 'neutral')) ?>"><?= e(ucfirst($rec['status'])) ?></span></td>
                <td><?= e($rec['time_in'] ? date('H:i', strtotime($rec['time_in'])) : '—') ?> - <?= e($rec['time_out'] ? date('H:i', strtotime($rec['time_out'])) : '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?><tr><td colspan="4" class="empty-cell">No attendance recorded yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>