<<<<<<< HEAD
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'sales', 'accountant']);

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    flash('info', 'Select an invoice to view.');
    redirect('invoices.php');
}
$error = '';
$canRecordPayments = in_array(current_user()['role'], ['admin', 'manager', 'accountant'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        $stmt = db()->prepare('SELECT * FROM invoices WHERE id=?');
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if (!$invoice) throw new RuntimeException('Invoice not found.');

        if ($action === 'pay') {
            if (!$canRecordPayments) throw new RuntimeException('You do not have permission to record payments.');
            if ($invoice['status'] === 'void') throw new RuntimeException('This invoice has been voided.');
            if ($invoice['status'] === 'paid') throw new RuntimeException('This invoice is already fully paid.');

            $payDate   = post_string('payment_date', 10);
            $amountRaw = (string) ($_POST['amount'] ?? '');
            $method    = post_string('method', 30);
            $reference = post_string('reference', 100);
            $notes     = post_string('notes', 500);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) throw new RuntimeException('Enter a valid payment date.');
            if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) throw new RuntimeException('Amount must be greater than zero.');
            if (!in_array($method, ['cash', 'bank_transfer', 'card', 'mobile_money', 'cheque', 'other'], true)) throw new RuntimeException('Choose a valid payment method.');

            $amount = round((float) $amountRaw, 2);
            $epsilon = 0.005;

            db()->beginTransaction();
            $paidStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?');
            $paidStmt->execute([$id]);
            $alreadyPaid = (float) $paidStmt->fetchColumn();
            $remaining = round((float) $invoice['amount'] - $alreadyPaid, 2);
            if ($amount > $remaining + $epsilon) {
                throw new RuntimeException('Payment exceeds the outstanding balance. Remaining: ' . money($remaining));
            }

            db()->prepare('INSERT INTO payments (invoice_id, payment_date, amount, method, reference, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $payDate, $amount, $method, $reference ?: null, $notes ?: null, current_user()['id']]);

            $newPaid = round($alreadyPaid + $amount, 2);
            $newStatus = $newPaid >= (float) $invoice['amount'] - $epsilon ? 'paid' : 'part_paid';
            db()->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$newStatus, $id]);
            db()->commit();
            flash('success', 'Payment recorded. Invoice is now ' . str_replace('_', ' ', $newStatus) . '.');
            redirect('invoice.php?id=' . $id);
        }

        if ($action === 'void') {
            if (current_user()['role'] !== 'admin') throw new RuntimeException('Only an administrator can void invoices.');
            $paidStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?');
            $paidStmt->execute([$id]);
            if ((float) $paidStmt->fetchColumn() > 0) throw new RuntimeException('Cannot void an invoice that already has payments.');
            db()->prepare('UPDATE invoices SET status="void" WHERE id=?')->execute([$id]);
            flash('success', 'Invoice voided.');
            redirect('invoice.php?id=' . $id);
        }
    } catch (RuntimeException $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = 'The operation failed. Please try again.';
    }
}

$stmt = db()->prepare(
    'SELECT i.*, so.order_number, so.order_date, so.subtotal, so.tax_amount, so.total_amount, so.vat_rate, so.status AS order_status,
            c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, c.address AS customer_address
     FROM invoices i
     JOIN sales_orders so ON so.id = i.order_id
     JOIN customers c ON c.id = so.customer_id
     WHERE i.id=?'
);
$stmt->execute([$id]);
$invoice = $stmt->fetch();
if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect('invoices.php');
}

$itemStmt = db()->prepare('SELECT soi.*, p.name, p.sku FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE soi.order_id=? ORDER BY soi.id');
$itemStmt->execute([$invoice['order_id']]);
$items = $itemStmt->fetchAll();

$payStmt = db()->prepare('SELECT p.*, u.full_name FROM payments p LEFT JOIN users u ON u.id = p.received_by WHERE p.invoice_id=? ORDER BY p.payment_date, p.id');
$payStmt->execute([$id]);
$payments = $payStmt->fetchAll();

$totalPaid = 0.0;
foreach ($payments as $payment) {
    $totalPaid += (float) $payment['amount'];
}
$balance = round((float) $invoice['amount'] - $totalPaid, 2);

$statusPill = match ($invoice['status']) {
    'paid'      => 'success',
    'part_paid' => 'info',
    'void'      => 'neutral',
    default     => 'warning',
};
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<section class="order-banner">
    <div>
        <span class="eyebrow">INVOICE</span>
        <h2><?= e($invoice['invoice_number']) ?> · <?= e($invoice['customer_name']) ?></h2>
        <p>Issued <?= e(format_day($invoice['invoice_date'])) ?> · Due <?= e(format_day($invoice['due_date'])) ?> · Order <a class="text-link" href="sale.php?id=<?= (int) $invoice['order_id'] ?>"><?= e($invoice['order_number']) ?></a></p>
    </div>
    <div class="order-meta">
        <span class="pill pill-<?= $statusPill ?>"><?= e(ucfirst(str_replace('_', ' ', $invoice['status']))) ?></span>
        <a class="text-link" href="invoices.php">← All invoices</a>
        <button class="button button-secondary button-small" onclick="window.print()">🖨 Print / PDF</button>
    </div>
    <div class="print-header">
    <h1><?= e(get_setting('company_name', 'SME ERP')) ?></h1>
    <p><?= e(get_setting('company_email')) ?> | <?= e(get_setting('company_phone')) ?></p>
</div>
</section>

<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>Record payment</h2><p>Balance: <?= e(money($balance)) ?></p></div></div>
        <?php if ($invoice['status'] === 'paid'): ?>
            <div class="empty-state compact"><strong>Invoice fully paid</strong><p>This invoice has been settled in full.</p></div>
        <?php elseif ($invoice['status'] === 'void'): ?>
            <div class="empty-state compact"><strong>Invoice voided</strong><p>No further payments can be recorded.</p></div>
        <?php elseif (!$canRecordPayments): ?>
            <div class="empty-state compact"><strong>Read-only</strong><p>Only managers, accountants and administrators can record payments.</p></div>
        <?php else: ?>
            <form method="post" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay">
                <div class="field-grid">
                    <div><label for="payment_date">Payment date *</label><input id="payment_date" name="payment_date" type="date" required value="<?= e(date('Y-m-d')) ?>"></div>
                    <div><label for="amount">Amount (KSh) *</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" max="<?= e(number_format($balance, 2, '.', '')) ?>" required value="<?= e(number_format($balance, 2, '.', '')) ?>"></div>
                </div>
                <div><label for="method">Method *</label>
                    <select id="method" name="method" required>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="mobile_money">Mobile money</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div><label for="reference">Reference</label><input id="reference" name="reference" maxlength="100" placeholder="Txn / receipt number"></div>
                <div><label for="notes">Notes</label><textarea id="notes" name="notes" maxlength="500" rows="2"></textarea></div>
                <button class="button button-primary" type="submit">Record payment</button>
            </form>
        <?php endif; ?>

        <?php if (current_user()['role'] === 'admin' && $invoice['status'] !== 'void' && $totalPaid == 0.0): ?>
            <form method="post" data-confirm="Void this invoice? This cannot be undone." style="margin-top:14px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="void">
                <button class="button button-quiet button-small" type="submit">Void invoice</button>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Invoice details</h2><p>For <?= e($invoice['customer_name']) ?></p></div></div>
        <div class="info-note" style="margin-top:0">
            <strong><?= e($invoice['customer_name']) ?></strong>
            <p><?= e($invoice['customer_email'] ?: 'No email recorded') ?> · <?= e($invoice['customer_phone'] ?: 'No phone recorded') ?></p>
            <?php if ($invoice['customer_address']): ?><p><?= e($invoice['customer_address']) ?></p><?php endif; ?>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Product</th><th>Qty</th><th>Unit price</th><th>Line total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr><td><strong><?= e($item['name']) ?></strong><small class="table-subtext"><?= e($item['sku']) ?></small></td><td><?= e(number_format((float) $item['quantity'], 2)) ?></td><td><?= e(money($item['unit_price'])) ?></td><td><?= e(money($item['line_total'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="totals-list">
            <div><span>Subtotal</span><span><?= e(money($invoice['subtotal'])) ?></span></div>
            <div><span>VAT (<?= e(number_format((float) $invoice['vat_rate'], 2)) ?>%)</span><span><?= e(money($invoice['tax_amount'])) ?></span></div>
            <div class="total-row"><span>Invoice amount</span><span><?= e(money($invoice['amount'])) ?></span></div>
            <div><span>Total paid</span><span><?= e(money($totalPaid)) ?></span></div>
            <div class="total-row"><span>Balance due</span><span><?= e(money($balance)) ?></span></div>
        </div>

        <h3 style="margin:22px 0 12px;font:800 13px Manrope,sans-serif">Payment history</h3>
        <?php if (!$payments): ?>
            <div class="empty-state compact"><strong>No payments yet</strong><p>Recorded payments will appear here.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded by</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= e(format_day($payment['payment_date'])) ?></td>
                        <td><?= e(money($payment['amount'])) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', $payment['method']))) ?></td>
                        <td><?= e($payment['reference'] ?: '—') ?></td>
                        <td><?= e($payment['full_name'] ?? 'System') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </article>
</section>
=======
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'sales', 'accountant']);

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    flash('info', 'Select an invoice to view.');
    redirect('invoices.php');
}
$error = '';
$canRecordPayments = in_array(current_user()['role'], ['admin', 'manager', 'accountant'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        $stmt = db()->prepare('SELECT * FROM invoices WHERE id=?');
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if (!$invoice) throw new RuntimeException('Invoice not found.');

        if ($action === 'pay') {
            if (!$canRecordPayments) throw new RuntimeException('You do not have permission to record payments.');
            if ($invoice['status'] === 'void') throw new RuntimeException('This invoice has been voided.');
            if ($invoice['status'] === 'paid') throw new RuntimeException('This invoice is already fully paid.');

            $payDate   = post_string('payment_date', 10);
            $amountRaw = (string) ($_POST['amount'] ?? '');
            $method    = post_string('method', 30);
            $reference = post_string('reference', 100);
            $notes     = post_string('notes', 500);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) throw new RuntimeException('Enter a valid payment date.');
            if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) throw new RuntimeException('Amount must be greater than zero.');
            if (!in_array($method, ['cash', 'bank_transfer', 'card', 'mobile_money', 'cheque', 'other'], true)) throw new RuntimeException('Choose a valid payment method.');

            $amount = round((float) $amountRaw, 2);
            $epsilon = 0.005;

            db()->beginTransaction();
            $paidStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?');
            $paidStmt->execute([$id]);
            $alreadyPaid = (float) $paidStmt->fetchColumn();
            $remaining = round((float) $invoice['amount'] - $alreadyPaid, 2);
            if ($amount > $remaining + $epsilon) {
                throw new RuntimeException('Payment exceeds the outstanding balance. Remaining: ' . money($remaining));
            }

            db()->prepare('INSERT INTO payments (invoice_id, payment_date, amount, method, reference, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $payDate, $amount, $method, $reference ?: null, $notes ?: null, current_user()['id']]);

            $newPaid = round($alreadyPaid + $amount, 2);
            $newStatus = $newPaid >= (float) $invoice['amount'] - $epsilon ? 'paid' : 'part_paid';
            db()->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$newStatus, $id]);
            db()->commit();
            flash('success', 'Payment recorded. Invoice is now ' . str_replace('_', ' ', $newStatus) . '.');
            redirect('invoice.php?id=' . $id);
        }

        if ($action === 'void') {
            if (current_user()['role'] !== 'admin') throw new RuntimeException('Only an administrator can void invoices.');
            $paidStmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?');
            $paidStmt->execute([$id]);
            if ((float) $paidStmt->fetchColumn() > 0) throw new RuntimeException('Cannot void an invoice that already has payments.');
            db()->prepare('UPDATE invoices SET status="void" WHERE id=?')->execute([$id]);
            flash('success', 'Invoice voided.');
            redirect('invoice.php?id=' . $id);
        }
    } catch (RuntimeException $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = 'The operation failed. Please try again.';
    }
}

$stmt = db()->prepare(
    'SELECT i.*, so.order_number, so.order_date, so.subtotal, so.tax_amount, so.total_amount, so.vat_rate, so.status AS order_status,
            c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, c.address AS customer_address
     FROM invoices i
     JOIN sales_orders so ON so.id = i.order_id
     JOIN customers c ON c.id = so.customer_id
     WHERE i.id=?'
);
$stmt->execute([$id]);
$invoice = $stmt->fetch();
if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect('invoices.php');
}

$itemStmt = db()->prepare('SELECT soi.*, p.name, p.sku FROM sales_order_items soi JOIN products p ON p.id = soi.product_id WHERE soi.order_id=? ORDER BY soi.id');
$itemStmt->execute([$invoice['order_id']]);
$items = $itemStmt->fetchAll();

$payStmt = db()->prepare('SELECT p.*, u.full_name FROM payments p LEFT JOIN users u ON u.id = p.received_by WHERE p.invoice_id=? ORDER BY p.payment_date, p.id');
$payStmt->execute([$id]);
$payments = $payStmt->fetchAll();

$totalPaid = 0.0;
foreach ($payments as $payment) {
    $totalPaid += (float) $payment['amount'];
}
$balance = round((float) $invoice['amount'] - $totalPaid, 2);

$statusPill = match ($invoice['status']) {
    'paid'      => 'success',
    'part_paid' => 'info',
    'void'      => 'neutral',
    default     => 'warning',
};
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<section class="order-banner">
    <div>
        <span class="eyebrow">INVOICE</span>
        <h2><?= e($invoice['invoice_number']) ?> · <?= e($invoice['customer_name']) ?></h2>
        <p>Issued <?= e(format_day($invoice['invoice_date'])) ?> · Due <?= e(format_day($invoice['due_date'])) ?> · Order <a class="text-link" href="sale.php?id=<?= (int) $invoice['order_id'] ?>"><?= e($invoice['order_number']) ?></a></p>
    </div>
    <div class="order-meta">
        <span class="pill pill-<?= $statusPill ?>"><?= e(ucfirst(str_replace('_', ' ', $invoice['status']))) ?></span>
        <a class="text-link" href="invoices.php">← All invoices</a>
        <button class="button button-secondary button-small" onclick="window.print()">🖨 Print / PDF</button>
    </div>
    <div class="print-header">
    <h1><?= e(get_setting('company_name', 'SME ERP')) ?></h1>
    <p><?= e(get_setting('company_email')) ?> | <?= e(get_setting('company_phone')) ?></p>
</div>
</section>

<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>Record payment</h2><p>Balance: <?= e(money($balance)) ?></p></div></div>
        <?php if ($invoice['status'] === 'paid'): ?>
            <div class="empty-state compact"><strong>Invoice fully paid</strong><p>This invoice has been settled in full.</p></div>
        <?php elseif ($invoice['status'] === 'void'): ?>
            <div class="empty-state compact"><strong>Invoice voided</strong><p>No further payments can be recorded.</p></div>
        <?php elseif (!$canRecordPayments): ?>
            <div class="empty-state compact"><strong>Read-only</strong><p>Only managers, accountants and administrators can record payments.</p></div>
        <?php else: ?>
            <form method="post" class="form-stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay">
                <div class="field-grid">
                    <div><label for="payment_date">Payment date *</label><input id="payment_date" name="payment_date" type="date" required value="<?= e(date('Y-m-d')) ?>"></div>
                    <div><label for="amount">Amount (KSh) *</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" max="<?= e(number_format($balance, 2, '.', '')) ?>" required value="<?= e(number_format($balance, 2, '.', '')) ?>"></div>
                </div>
                <div><label for="method">Method *</label>
                    <select id="method" name="method" required>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="mobile_money">Mobile money</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div><label for="reference">Reference</label><input id="reference" name="reference" maxlength="100" placeholder="Txn / receipt number"></div>
                <div><label for="notes">Notes</label><textarea id="notes" name="notes" maxlength="500" rows="2"></textarea></div>
                <button class="button button-primary" type="submit">Record payment</button>
            </form>
        <?php endif; ?>

        <?php if (current_user()['role'] === 'admin' && $invoice['status'] !== 'void' && $totalPaid == 0.0): ?>
            <form method="post" data-confirm="Void this invoice? This cannot be undone." style="margin-top:14px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="void">
                <button class="button button-quiet button-small" type="submit">Void invoice</button>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Invoice details</h2><p>For <?= e($invoice['customer_name']) ?></p></div></div>
        <div class="info-note" style="margin-top:0">
            <strong><?= e($invoice['customer_name']) ?></strong>
            <p><?= e($invoice['customer_email'] ?: 'No email recorded') ?> · <?= e($invoice['customer_phone'] ?: 'No phone recorded') ?></p>
            <?php if ($invoice['customer_address']): ?><p><?= e($invoice['customer_address']) ?></p><?php endif; ?>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Product</th><th>Qty</th><th>Unit price</th><th>Line total</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr><td><strong><?= e($item['name']) ?></strong><small class="table-subtext"><?= e($item['sku']) ?></small></td><td><?= e(number_format((float) $item['quantity'], 2)) ?></td><td><?= e(money($item['unit_price'])) ?></td><td><?= e(money($item['line_total'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="totals-list">
            <div><span>Subtotal</span><span><?= e(money($invoice['subtotal'])) ?></span></div>
            <div><span>VAT (<?= e(number_format((float) $invoice['vat_rate'], 2)) ?>%)</span><span><?= e(money($invoice['tax_amount'])) ?></span></div>
            <div class="total-row"><span>Invoice amount</span><span><?= e(money($invoice['amount'])) ?></span></div>
            <div><span>Total paid</span><span><?= e(money($totalPaid)) ?></span></div>
            <div class="total-row"><span>Balance due</span><span><?= e(money($balance)) ?></span></div>
        </div>

        <h3 style="margin:22px 0 12px;font:800 13px Manrope,sans-serif">Payment history</h3>
        <?php if (!$payments): ?>
            <div class="empty-state compact"><strong>No payments yet</strong><p>Recorded payments will appear here.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded by</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= e(format_day($payment['payment_date'])) ?></td>
                        <td><?= e(money($payment['amount'])) ?></td>
                        <td><?= e(ucwords(str_replace('_', ' ', $payment['method']))) ?></td>
                        <td><?= e($payment['reference'] ?: '—') ?></td>
                        <td><?= e($payment['full_name'] ?? 'System') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </article>
</section>
>>>>>>> 9675731b7defc439d304f0da27d69db06d37bfed
<?php require __DIR__ . '/includes/footer.php'; ?>