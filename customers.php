<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'sales']);
$pageTitle = 'Customers';
$error = '';
$editing = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = post_string('name', 150);
            $email = post_string('email', 190);
            $phone = post_string('phone', 40);
            $address = post_string('address', 500);
            $creditRaw = (string) ($_POST['credit_limit'] ?? '0');
            if ($name === '') throw new RuntimeException('Customer name is required.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if (!is_numeric($creditRaw) || (float) $creditRaw < 0) throw new RuntimeException('Credit limit must be a non-negative number.');
            if ($id > 0) {
                db()->prepare('UPDATE customers SET name=?, email=?, phone=?, address=?, credit_limit=? WHERE id=?')->execute([$name, $email ?: null, $phone ?: null, $address ?: null, $creditRaw, $id]);
                flash('success', 'Customer details updated.');
            } else {
                db()->prepare('INSERT INTO customers (name, email, phone, address, credit_limit, is_active) VALUES (?, ?, ?, ?, ?, 1)')->execute([$name, $email ?: null, $phone ?: null, $address ?: null, $creditRaw]);
                flash('success', 'Customer added successfully.');
            }
            redirect('customers.php');
        }
        if ($action === 'archive') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Invalid customer.');
            db()->prepare('UPDATE customers SET is_active=0 WHERE id=?')->execute([$id]);
            flash('success', 'Customer archived. Historical sales records, if any, are preserved.');
            redirect('customers.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = 'The customer could not be saved. Check the entered values and try again.';
    }
}
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM customers WHERE id=? AND is_active=1');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}
$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT * FROM customers WHERE is_active=1'; $params = [];
if ($search !== '') { $sql .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)'; $term = '%' . $search . '%'; $params = [$term, $term, $term]; }
$sql .= ' ORDER BY name';
$stmt = db()->prepare($sql); $stmt->execute($params); $customers = $stmt->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel"><div class="panel-heading"><div><h2><?= $editing ? 'Edit customer' : 'Add customer' ?></h2><p>Keep contact and account details in one place.</p></div><?php if ($editing): ?><a class="text-link" href="customers.php">Cancel</a><?php endif; ?></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">
            <div><label for="name">Customer / business name *</label><input id="name" name="name" maxlength="150" required value="<?= e($editing['name'] ?? '') ?>" placeholder="Customer name"></div>
            <div class="field-grid"><div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" value="<?= e($editing['email'] ?? '') ?>" placeholder="name@example.com"></div><div><label for="phone">Phone</label><input id="phone" name="phone" type="tel" maxlength="40" value="<?= e($editing['phone'] ?? '') ?>" placeholder="07xx xxx xxx"></div></div>
            <div><label for="address">Address</label><textarea id="address" name="address" rows="3" maxlength="500" placeholder="Physical or postal address"><?= e($editing['address'] ?? '') ?></textarea></div>
            <div><label for="credit_limit">Credit limit (KSh)</label><input id="credit_limit" name="credit_limit" type="number" min="0" step="0.01" value="<?= e($editing['credit_limit'] ?? '0.00') ?>"></div>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Add customer' ?></button>
        </form>
    </article>
    <article class="panel list-panel"><div class="panel-heading"><div><h2>Customer directory</h2><p><?= count($customers) ?> active customer(s)</p></div></div>
        <form method="get" class="search-form"><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email or phone"><button class="button button-secondary" type="submit">Search</button><?php if ($search !== ''): ?><a class="text-link" href="customers.php">Clear</a><?php endif; ?></form>
        <?php if (!$customers): ?><div class="empty-state compact"><strong>No customers found</strong><p>Use the form to add your first customer.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Phone</th><th>Credit limit</th><th></th></tr></thead><tbody>
            <?php foreach ($customers as $customer): ?><tr><td><strong><?= e($customer['name']) ?></strong><small class="table-subtext"><?= e($customer['email'] ?: 'No email recorded') ?></small></td><td><?= e($customer['phone'] ?: '—') ?></td><td><?= e(money($customer['credit_limit'])) ?></td><td><div class="row-actions"><a class="text-link" href="customers.php?edit=<?= (int) $customer['id'] ?>">Edit</a><form method="post" data-confirm="Archive this customer?" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>"><button class="link-button danger-link" type="submit">Archive</button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
