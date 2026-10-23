<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'sales', 'accountant']);
$pageTitle = 'Invoices';

$search = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$sql = 'SELECT i.*, so.order_number, c.name AS customer_name,
               (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.invoice_id = i.id) AS paid_amount
        FROM invoices i
        JOIN sales_orders so ON so.id = i.order_id
        JOIN customers c ON c.id = so.customer_id
        WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (i.invoice_number LIKE ? OR so.order_number LIKE ? OR c.name LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
if ($status !== '' && in_array($status, ['unpaid', 'part_paid', 'paid', 'void'], true)) {
    $sql .= ' AND i.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY i.id DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$totalOutstanding = 0.0;
foreach ($invoices as $row) {
    if (in_array($row['status'], ['unpaid', 'part_paid'], true)) {
        $totalOutstanding += (float) $row['amount'] - (float) $row['paid_amount'];
    }
}
require __DIR__ . '/includes/header.php';
?>
<section class="metrics-grid" style="margin-bottom:18px">
    <article class="metric-card"><div class="metric-label"><span class="metric-icon icon-blue">$</span><span>Invoices</span></div><div class="metric-value"><?= number_format(count($invoices)) ?></div><div class="metric-foot">Shown in list</div></article>
    <article class="metric-card"><div class="metric-label"><span class="metric-icon icon-amber">!</span><span>Outstanding</span></div><div class="metric-value"><?= e(money_short($totalOutstanding)) ?></div><div class="metric-foot">Unpaid + part-paid balance</div></article>
</section>

<section class="panel">
    <div class="panel-heading"><div><h2>Invoices</h2><p>Click a row to view or record payments</p></div></div>
    <form method="get" class="search-form">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search invoice #, order # or customer">
        <select name="status" style="padding:10px 11px;border:1px solid #dce2eb;border-radius:9px;font-size:12px;background:#fff">
            <option value="">All statuses</option>
            <?php foreach (['unpaid', 'part_paid', 'paid', 'void'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="button button-secondary" type="submit">Filter</button>
        <?php if ($search !== '' || $status !== ''): ?><a class="text-link" href="invoices.php">Clear</a><?php endif; ?>
    </form>
    <?php if (!$invoices): ?>
        <div class="empty-state"><strong>No invoices yet</strong><p>Invoices are generated from fulfilled or confirmed sale orders.</p><a class="button button-primary" href="sales.php">Go to sales orders</a></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Invoice</th><th>Customer</th><th>Order</th><th>Issued</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $inv):
                $balance = (float) $inv['amount'] - (float) $inv['paid_amount'];
                $statusKey = $inv['status'] === 'paid' ? 'success' : ($inv['status'] === 'void' ? 'neutral' : ($inv['status'] === 'part_paid' ? 'info' : 'warning'));
            ?>
                <tr>
                    <td><strong><a href="invoice.php?id=<?= (int) $inv['id'] ?>"><?= e($inv['invoice_number']) ?></a></strong></td>
                    <td><?= e($inv['customer_name']) ?></td>
                    <td><a class="text-link" href="sale.php?id=<?= (int) $inv['order_id'] ?>"><?= e($inv['order_number']) ?></a></td>
                    <td><?= e(format_day($inv['invoice_date'])) ?></td>
                    <td><?= e(format_day($inv['due_date'])) ?></td>
                    <td><?= e(money($inv['amount'])) ?></td>
                    <td><?= e(money($inv['paid_amount'])) ?></td>
                    <td><span class="pill pill-<?= $statusKey ?>"><?= e(ucfirst(str_replace('_', ' ', $inv['status']))) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>