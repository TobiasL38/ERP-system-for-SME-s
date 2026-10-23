<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'procurement']);
$pageTitle = 'Suppliers';
$error = '';
$editing = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = post_string('name', 150);
            $contact = post_string('contact_person', 150);
            $email = post_string('email', 190);
            $phone = post_string('phone', 40);
            $address = post_string('address', 500);
            $terms = post_string('payment_terms', 150);
            if ($name === '') throw new RuntimeException('Supplier/business name is required.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if ($id > 0) {
                db()->prepare('UPDATE suppliers SET name=?, contact_person=?, email=?, phone=?, address=?, payment_terms=? WHERE id=?')->execute([$name, $contact ?: null, $email ?: null, $phone ?: null, $address ?: null, $terms ?: null, $id]);
                flash('success', 'Supplier details updated.');
            } else {
                db()->prepare('INSERT INTO suppliers (name, contact_person, email, phone, address, payment_terms, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')->execute([$name, $contact ?: null, $email ?: null, $phone ?: null, $address ?: null, $terms ?: null]);
                flash('success', 'Supplier added successfully.');
            }
            redirect('suppliers.php');
        }
        if ($action === 'archive') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Invalid supplier.');
            db()->prepare('UPDATE suppliers SET is_active=0 WHERE id=?')->execute([$id]);
            flash('success', 'Supplier archived. Historical purchase records, if any, are preserved.');
            redirect('suppliers.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = 'The supplier could not be saved. Check the entered values and try again.';
    }
}
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM suppliers WHERE id=? AND is_active=1');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}
$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT * FROM suppliers WHERE is_active=1'; $params = [];
if ($search !== '') { $sql .= ' AND (name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ?)'; $term = '%' . $search . '%'; $params = [$term, $term, $term, $term]; }
$sql .= ' ORDER BY name';
$stmt = db()->prepare($sql); $stmt->execute($params); $suppliers = $stmt->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel"><div class="panel-heading"><div><h2><?= $editing ? 'Edit supplier' : 'Add supplier' ?></h2><p>Maintain supplier contact and payment terms.</p></div><?php if ($editing): ?><a class="text-link" href="suppliers.php">Cancel</a><?php endif; ?></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">
            <div><label for="name">Supplier / business name *</label><input id="name" name="name" maxlength="150" required value="<?= e($editing['name'] ?? '') ?>" placeholder="Supplier name"></div>
            <div><label for="contact_person">Contact person</label><input id="contact_person" name="contact_person" maxlength="150" value="<?= e($editing['contact_person'] ?? '') ?>" placeholder="Contact person"></div>
            <div class="field-grid"><div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" value="<?= e($editing['email'] ?? '') ?>" placeholder="supplier@example.com"></div><div><label for="phone">Phone</label><input id="phone" name="phone" type="tel" maxlength="40" value="<?= e($editing['phone'] ?? '') ?>" placeholder="07xx xxx xxx"></div></div>
            <div><label for="address">Address</label><textarea id="address" name="address" rows="2" maxlength="500" placeholder="Physical or postal address"><?= e($editing['address'] ?? '') ?></textarea></div>
            <div><label for="payment_terms">Payment terms</label><input id="payment_terms" name="payment_terms" maxlength="150" value="<?= e($editing['payment_terms'] ?? '') ?>" placeholder="e.g. 30 days, cash on delivery"></div>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Add supplier' ?></button>
        </form>
    </article>
    <article class="panel list-panel"><div class="panel-heading"><div><h2>Supplier directory</h2><p><?= count($suppliers) ?> active supplier(s)</p></div></div>
        <form method="get" class="search-form"><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, contact or phone"><button class="button button-secondary" type="submit">Search</button><?php if ($search !== ''): ?><a class="text-link" href="suppliers.php">Clear</a><?php endif; ?></form>
        <?php if (!$suppliers): ?><div class="empty-state compact"><strong>No suppliers found</strong><p>Use the form to add your first supplier.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Supplier</th><th>Contact</th><th>Terms</th><th></th></tr></thead><tbody>
            <?php foreach ($suppliers as $supplier): ?><tr><td><strong><?= e($supplier['name']) ?></strong><small class="table-subtext"><?= e($supplier['email'] ?: 'No email recorded') ?></small></td><td><?= e($supplier['contact_person'] ?: $supplier['phone'] ?: '—') ?></td><td><?= e($supplier['payment_terms'] ?: '—') ?></td><td><div class="row-actions"><a class="text-link" href="suppliers.php?edit=<?= (int) $supplier['id'] ?>">Edit</a><form method="post" data-confirm="Archive this supplier?" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= (int) $supplier['id'] ?>"><button class="link-button danger-link" type="submit">Archive</button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
