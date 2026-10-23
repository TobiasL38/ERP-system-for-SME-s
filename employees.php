<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'hr']);
$pageTitle = 'Employees';
$error = '';
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $empNum = post_string('employee_number', 40);
            $firstName = post_string('first_name', 100);
            $lastName = post_string('last_name', 100);
            $email = post_string('email', 190);
            $phone = post_string('phone', 40);
            $dept = post_string('department', 100);
            $pos = post_string('position', 100);
            $empDate = post_string('employment_date', 10);
            $salaryRaw = (string) ($_POST['monthly_salary'] ?? '');
            $status = post_string('status', 20);

            if ($empNum === '' || $firstName === '' || $lastName === '') throw new RuntimeException('Employee number, first name, and last name are required.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if (!in_array($status, ['active', 'inactive', 'on_leave'], true)) throw new RuntimeException('Select a valid status.');
            
            $salary = is_numeric($salaryRaw) ? (float) $salaryRaw : null;

            if ($id > 0) {
                db()->prepare('UPDATE employees SET employee_number=?, first_name=?, last_name=?, email=?, phone=?, department=?, position=?, employment_date=?, monthly_salary=?, status=? WHERE id=?')
                    ->execute([$empNum, $firstName, $lastName, $email ?: null, $phone ?: null, $dept ?: null, $pos ?: null, $empDate ?: null, $salary, $status, $id]);
                flash('success', 'Employee details updated.');
            } else {
                db()->prepare('INSERT INTO employees (employee_number, first_name, last_name, email, phone, department, position, employment_date, monthly_salary, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$empNum, $firstName, $lastName, $email ?: null, $phone ?: null, $dept ?: null, $pos ?: null, $empDate ?: null, $salary, $status]);
                flash('success', 'Employee added successfully.');
            }
            redirect('employees.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = $exception->getCode() === '23000' ? 'Employee number already exists.' : 'The employee record could not be saved.';
    }
}

if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM employees WHERE id=?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT * FROM employees WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (first_name LIKE ? OR last_name LIKE ? OR employee_number LIKE ? OR department LIKE ?)';
    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term];
}
$sql .= ' ORDER BY first_name, last_name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2><?= $editing ? 'Edit employee' : 'Add employee' ?></h2><p>Manage staff profiles and departments.</p></div><?php if ($editing): ?><a class="text-link" href="employees.php">Cancel</a><?php endif; ?></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">
            <div class="field-grid">
                <div><label for="employee_number">Employee No. *</label><input id="employee_number" name="employee_number" maxlength="40" required value="<?= e($editing['employee_number'] ?? '') ?>" placeholder="e.g. EMP-001"></div>
                <div><label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="active" <?= ($editing['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="on_leave" <?= ($editing['status'] ?? '') === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                        <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive / Terminated</option>
                    </select>
                </div>
            </div>
            <div class="field-grid">
                <div><label for="first_name">First Name *</label><input id="first_name" name="first_name" maxlength="100" required value="<?= e($editing['first_name'] ?? '') ?>"></div>
                <div><label for="last_name">Last Name *</label><input id="last_name" name="last_name" maxlength="100" required value="<?= e($editing['last_name'] ?? '') ?>"></div>
            </div>
            <div class="field-grid">
                <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" value="<?= e($editing['email'] ?? '') ?>"></div>
                <div><label for="phone">Phone</label><input id="phone" name="phone" type="tel" maxlength="40" value="<?= e($editing['phone'] ?? '') ?>"></div>
            </div>
            <div class="field-grid">
                <div><label for="department">Department</label><input id="department" name="department" maxlength="100" value="<?= e($editing['department'] ?? '') ?>" placeholder="e.g. Sales, IT, HR"></div>
                <div><label for="position">Position / Title</label><input id="position" name="position" maxlength="100" value="<?= e($editing['position'] ?? '') ?>" placeholder="e.g. Sales Manager"></div>
            </div>
            <div class="field-grid">
                <div><label for="employment_date">Employment Date</label><input id="employment_date" name="employment_date" type="date" value="<?= e($editing['employment_date'] ?? '') ?>"></div>
                <div><label for="monthly_salary">Monthly Salary (KSh)</label><input id="monthly_salary" name="monthly_salary" type="number" min="0" step="0.01" value="<?= e($editing['monthly_salary'] ?? '') ?>"></div>
            </div>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Add employee' ?></button>
        </form>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Employee Directory</h2><p><?= count($employees) ?> employee(s)</p></div></div>
        <form method="get" class="search-form"><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, ID, or department"><button class="button button-secondary" type="submit">Search</button><?php if ($search !== ''): ?><a class="text-link" href="employees.php">Clear</a><?php endif; ?></form>
        <?php if (!$employees): ?><div class="empty-state compact"><strong>No employees found</strong><p>Add a new employee using the form.</p></div><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Employee</th><th>Department</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($employees as $emp): ?>
            <tr>
                <td><strong><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></strong><small class="table-subtext"><?= e($emp['employee_number']) ?></small></td>
                <td><?= e($emp['department'] ?: '—') ?><small class="table-subtext"><?= e($emp['position'] ?: '') ?></small></td>
                <td><span class="pill pill-<?= $emp['status'] === 'active' ? 'success' : ($emp['status'] === 'on_leave' ? 'warning' : 'neutral') ?>"><?= e(ucwords(str_replace('_', ' ', $emp['status']))) ?></span></td>
                <td><div class="row-actions"><a class="text-link" href="employees.php?edit=<?= (int) $emp['id'] ?>">Edit</a></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>