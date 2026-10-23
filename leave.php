<<<<<<< HEAD
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'hr']);
$pageTitle = 'Leave Requests';
$error = '';

$isManager = in_array(current_user()['role'], ['admin', 'manager', 'hr'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'request') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $start = post_string('start_date', 10);
            $end = post_string('end_date', 10);
            $type = post_string('leave_type', 60);
            $reason = post_string('reason', 1000);

            if ($empId < 1) throw new RuntimeException('Select an employee.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) throw new RuntimeException('Enter valid dates.');
            if ($end < $start) throw new RuntimeException('End date cannot be before start date.');
            if ($type === '') throw new RuntimeException('Enter the leave type.');

            db()->prepare('INSERT INTO leave_requests (employee_id, start_date, end_date, leave_type, reason) VALUES (?, ?, ?, ?, ?)')
                ->execute([$empId, $start, $end, $type, $reason ?: null]);
            
            flash('success', 'Leave request submitted successfully.');
            redirect('leave.php');
        }
        
        if ($action === 'review') {
            if (!$isManager) throw new RuntimeException('Only managers and HR can review leave.');
            $id = (int) ($_POST['id'] ?? 0);
            $status = post_string('status', 20);
            
            if (!in_array($status, ['approved', 'rejected'], true)) throw new RuntimeException('Invalid status.');

            db()->prepare('UPDATE leave_requests SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status="pending"')
                ->execute([$status, current_user()['id'], $id]);
            flash('success', 'Leave request ' . $status . '.');
            redirect('leave.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'The operation failed.';
    }
}

$employees = db()->query('SELECT id, first_name, last_name FROM employees WHERE status="active" ORDER BY first_name')->fetchAll();
$requests = db()->query('SELECT lr.*, e.first_name, e.last_name, u.full_name AS reviewer FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id LEFT JOIN users u ON u.id = lr.reviewed_by ORDER BY lr.status = "pending" DESC, lr.id DESC LIMIT 100')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>Request Leave</h2><p>Submit a new leave application.</p></div></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="request">
            <div><label for="employee_id">Employee *</label>
                <select id="employee_id" name="employee_id" required>
                    <option value="">Choose employee</option>
                    <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field-grid">
                <div><label for="start_date">Start Date *</label><input id="start_date" name="start_date" type="date" required value="<?= date('Y-m-d') ?>"></div>
                <div><label for="end_date">End Date *</label><input id="end_date" name="end_date" type="date" required value="<?= date('Y-m-d') ?>"></div>
            </div>
            <div><label for="leave_type">Leave Type *</label>
                <select id="leave_type" name="leave_type" required>
                    <option value="Annual Leave">Annual Leave</option>
                    <option value="Sick Leave">Sick Leave</option>
                    <option value="Maternity/Paternity">Maternity / Paternity</option>
                    <option value="Compassionate">Compassionate</option>
                    <option value="Unpaid">Unpaid Leave</option>
                </select>
            </div>
            <div><label for="reason">Reason</label><textarea id="reason" name="reason" rows="2" maxlength="1000" placeholder="Optional details"></textarea></div>
            <button class="button button-primary" type="submit">Submit Request</button>
        </form>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Leave Requests</h2><p>Pending and recent applications</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Employee</th><th>Dates</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($requests as $req): ?>
            <tr>
                <td><strong><?= e($req['first_name'] . ' ' . $req['last_name']) ?></strong><small class="table-subtext"><?= e($req['leave_type']) ?></small></td>
                <td><?= e(format_day($req['start_date'])) ?> to <?= e(format_day($req['end_date'])) ?></td>
                <td>
                    <span class="pill pill-<?= $req['status'] === 'approved' ? 'success' : ($req['status'] === 'rejected' ? 'neutral' : 'warning') ?>"><?= e(ucfirst($req['status'])) ?></span>
                    <?php if ($req['reviewer']): ?><small class="table-subtext">by <?= e($req['reviewer']) ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if ($req['status'] === 'pending' && $isManager): ?>
                        <form method="post" class="inline-form" style="gap:6px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="review">
                            <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                            <button type="submit" name="status" value="approved" class="button button-small button-primary" style="padding:4px 8px">Approve</button>
                            <button type="submit" name="status" value="rejected" class="button button-small button-quiet" style="padding:4px 8px">Reject</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="4" class="empty-cell">No leave requests found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </article>
</section>
=======
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'hr']);
$pageTitle = 'Leave Requests';
$error = '';

$isManager = in_array(current_user()['role'], ['admin', 'manager', 'hr'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'request') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $start = post_string('start_date', 10);
            $end = post_string('end_date', 10);
            $type = post_string('leave_type', 60);
            $reason = post_string('reason', 1000);

            if ($empId < 1) throw new RuntimeException('Select an employee.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) throw new RuntimeException('Enter valid dates.');
            if ($end < $start) throw new RuntimeException('End date cannot be before start date.');
            if ($type === '') throw new RuntimeException('Enter the leave type.');

            db()->prepare('INSERT INTO leave_requests (employee_id, start_date, end_date, leave_type, reason) VALUES (?, ?, ?, ?, ?)')
                ->execute([$empId, $start, $end, $type, $reason ?: null]);
            
            flash('success', 'Leave request submitted successfully.');
            redirect('leave.php');
        }
        
        if ($action === 'review') {
            if (!$isManager) throw new RuntimeException('Only managers and HR can review leave.');
            $id = (int) ($_POST['id'] ?? 0);
            $status = post_string('status', 20);
            
            if (!in_array($status, ['approved', 'rejected'], true)) throw new RuntimeException('Invalid status.');

            db()->prepare('UPDATE leave_requests SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status="pending"')
                ->execute([$status, current_user()['id'], $id]);
            flash('success', 'Leave request ' . $status . '.');
            redirect('leave.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'The operation failed.';
    }
}

$employees = db()->query('SELECT id, first_name, last_name FROM employees WHERE status="active" ORDER BY first_name')->fetchAll();
$requests = db()->query('SELECT lr.*, e.first_name, e.last_name, u.full_name AS reviewer FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id LEFT JOIN users u ON u.id = lr.reviewed_by ORDER BY lr.status = "pending" DESC, lr.id DESC LIMIT 100')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>Request Leave</h2><p>Submit a new leave application.</p></div></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="request">
            <div><label for="employee_id">Employee *</label>
                <select id="employee_id" name="employee_id" required>
                    <option value="">Choose employee</option>
                    <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field-grid">
                <div><label for="start_date">Start Date *</label><input id="start_date" name="start_date" type="date" required value="<?= date('Y-m-d') ?>"></div>
                <div><label for="end_date">End Date *</label><input id="end_date" name="end_date" type="date" required value="<?= date('Y-m-d') ?>"></div>
            </div>
            <div><label for="leave_type">Leave Type *</label>
                <select id="leave_type" name="leave_type" required>
                    <option value="Annual Leave">Annual Leave</option>
                    <option value="Sick Leave">Sick Leave</option>
                    <option value="Maternity/Paternity">Maternity / Paternity</option>
                    <option value="Compassionate">Compassionate</option>
                    <option value="Unpaid">Unpaid Leave</option>
                </select>
            </div>
            <div><label for="reason">Reason</label><textarea id="reason" name="reason" rows="2" maxlength="1000" placeholder="Optional details"></textarea></div>
            <button class="button button-primary" type="submit">Submit Request</button>
        </form>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Leave Requests</h2><p>Pending and recent applications</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Employee</th><th>Dates</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($requests as $req): ?>
            <tr>
                <td><strong><?= e($req['first_name'] . ' ' . $req['last_name']) ?></strong><small class="table-subtext"><?= e($req['leave_type']) ?></small></td>
                <td><?= e(format_day($req['start_date'])) ?> to <?= e(format_day($req['end_date'])) ?></td>
                <td>
                    <span class="pill pill-<?= $req['status'] === 'approved' ? 'success' : ($req['status'] === 'rejected' ? 'neutral' : 'warning') ?>"><?= e(ucfirst($req['status'])) ?></span>
                    <?php if ($req['reviewer']): ?><small class="table-subtext">by <?= e($req['reviewer']) ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if ($req['status'] === 'pending' && $isManager): ?>
                        <form method="post" class="inline-form" style="gap:6px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="review">
                            <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                            <button type="submit" name="status" value="approved" class="button button-small button-primary" style="padding:4px 8px">Approve</button>
                            <button type="submit" name="status" value="rejected" class="button button-small button-quiet" style="padding:4px 8px">Reject</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="4" class="empty-cell">No leave requests found.</td></tr><?php endif; ?>
        </tbody></table></div>
    </article>
</section>
>>>>>>> 9675731b7defc439d304f0da27d69db06d37bfed
<?php require __DIR__ . '/includes/footer.php'; ?>