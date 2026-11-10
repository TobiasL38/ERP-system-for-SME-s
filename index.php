<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Overview';

$chartLabels = [];
$chartRevenue = [];
$chartPOs = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $chartLabels[] = date('M', strtotime("-$i months"));
    
    $rev = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE status IN ('confirmed','fulfilled') AND DATE_FORMAT(order_date, '%Y-%m') = '$month'")->fetchColumn();
    $chartRevenue[] = (float)$rev;

    $pos = db()->query("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE status IN ('submitted','part_received','received') AND DATE_FORMAT(order_date, '%Y-%m') = '$month'")->fetchColumn();
    $chartPOs[] = (float)$pos;
}


$metrics = [
    'products' => (int) db()->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn(),
    'low_stock' => (int) db()->query('SELECT COUNT(*) FROM inventory i JOIN products p ON p.id = i.product_id WHERE p.is_active = 1 AND i.quantity <= p.reorder_level')->fetchColumn(),
    'customers' => (int) db()->query('SELECT COUNT(*) FROM customers WHERE is_active = 1')->fetchColumn(),
    'suppliers' => (int) db()->query('SELECT COUNT(*) FROM suppliers WHERE is_active = 1')->fetchColumn(),
    'pending_pos' => (int) db()->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('submitted', 'part_received')")->fetchColumn(),
    'total_employees' => (int) db()->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn(),
    'pending_leave' => (int) db()->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn(),
];

$salesThisMonth = (float) db()->query(
    "SELECT COALESCE(SUM(total_amount),0) FROM sales_orders
     WHERE status IN ('confirmed','fulfilled')
       AND order_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
)->fetchColumn();
$recentSales = db()->query(
    'SELECT so.id, so.order_number, so.order_date, so.total_amount, so.status, c.name AS customer_name
     FROM sales_orders so JOIN customers c ON c.id = so.customer_id
     ORDER BY so.id DESC LIMIT 6'
)->fetchAll();

$lowStock = db()->query('SELECT p.id, p.sku, p.name, p.reorder_level, i.quantity FROM products p JOIN inventory i ON i.product_id = p.id WHERE p.is_active = 1 AND i.quantity <= p.reorder_level ORDER BY i.quantity ASC, p.name ASC LIMIT 6')->fetchAll();
$recentMovements = db()->query('SELECT sm.movement_type, sm.quantity, sm.previous_quantity, sm.new_quantity, sm.notes, sm.created_at, p.name AS product_name, p.sku, u.full_name FROM stock_movements sm JOIN products p ON p.id = sm.product_id LEFT JOIN users u ON u.id = sm.created_by ORDER BY sm.id DESC LIMIT 6')->fetchAll();
require __DIR__ . '/includes/header.php';
?>

<section class="metrics-grid" aria-label="Business summary">
    <article class="metric-card"><div class="metric-label"></span><span>Active products</span></div><div class="metric-value"><?= number_format($metrics['products']) ?></div><div class="metric-foot">Products in the catalogue</div></article>
    <article class="metric-card"><div class="metric-label"></span><span>Low-stock items</span></div><div class="metric-value"><?= number_format($metrics['low_stock']) ?></div><div class="metric-foot">At or below reorder level</div></article>
    <article class="metric-card"><div class="metric-label"></span><span>Customers</span></div><div class="metric-value"><?= number_format($metrics['customers']) ?></div><div class="metric-foot">Active customer records</div></article>
    <article class="metric-card"><div class="metric-label"></span><span>Suppliers</span></div><div class="metric-value"><?= number_format($metrics['suppliers']) ?></div><div class="metric-foot">Active supplier records</div></article>
    <article class="metric-card"><div class="metric-label"></span><span>Sales this month</span></div><div class="metric-value"><?= e(money_short($salesThisMonth)) ?></div><div class="metric-foot">Confirmed + fulfilled orders</div></article>
    <article class="metric-card"><div class="metric-label"></span><span>Active POs</span></div><div class="metric-value"><?= number_format($metrics['pending_pos']) ?></div><div class="metric-foot">Submitted or part-received</div></article>
    <article class="metric-card"><div class="metric-label"></span><span>Active Staff</span></div><div class="metric-value"><?= number_format($metrics['total_employees']) ?></div><div class="metric-foot">Employees currently active</div></article>
    <?php if (in_array(current_user()['role'], ['admin','manager','hr'])): ?>
    <article class="metric-card"><div class="metric-label"><span>Pending Leave</span></div><div class="metric-value"><?= number_format($metrics['pending_leave']) ?></div><div class="metric-foot">Awaiting approval</div></article>
    <?php endif; ?>
</section>

<section class="panel" style="margin-bottom:18px">
    <div class="panel-heading"><div><h2>6-Month Financial Trend</h2><p>Revenue vs Procurement</p></div></div>
    <div style="position: relative; height:300px; width:100%">
        <canvas id="financeChart"></canvas>
    </div>
</section>

<section class="content-grid">
    <article class="panel">
        <div class="panel-heading"><div><h2>Stock needing attention</h2><p>Items at or below their reorder level</p></div><a class="text-link" href="stock.php">Manage stock <span aria-hidden="true">→</span></a></div>
        <?php if (!$lowStock): ?>
            <div class="empty-state"><span class="empty-symbol">✓</span><strong>Nothing needs attention</strong><p>Low-stock items will appear here when stock reaches the configured reorder level.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Product</th><th>In stock</th><th>Reorder at</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($lowStock as $item): ?><tr><td><strong><?= e($item['name']) ?></strong><small class="table-subtext"><?= e($item['sku']) ?></small></td><td><?= e($item['quantity']) ?></td><td><?= e($item['reorder_level']) ?></td><td><span class="pill pill-warning">Low stock</span></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </article>
    <article class="panel quick-panel">
        <div class="panel-heading"><div><h2>Quick actions</h2><p>Common tasks</p></div></div>
        <?php $quickActions = [];
        if (in_array(current_user()['role'], ['admin','manager','inventory','procurement'], true)) {
            $quickActions[] = ['products.php', '＋', 'Add a product', 'Create a product record'];
            $quickActions[] = ['stock.php', '↕', 'Record stock movement', 'Receive or issue inventory'];
        }
        if (in_array(current_user()['role'], ['admin','manager','sales'], true)) $quickActions[] = ['customers.php', '♙', 'Add a customer', 'Keep customer details together'];
        if (in_array(current_user()['role'], ['admin','manager','procurement'], true)) $quickActions[] = ['suppliers.php', '⇄', 'Add a supplier', 'Maintain supplier records'];
        foreach ($quickActions as $action): ?>
        <a class="quick-action" href="<?= e($action[0]) ?>"><span class="quick-icon"><?= e($action[1]) ?></span><span><strong><?= e($action[2]) ?></strong><small><?= e($action[3]) ?></small></span><span class="quick-arrow">→</span></a>
        <?php endforeach; ?>
        <?php if (!$quickActions): ?><div class="empty-state compact"><strong>Your workspace is being prepared</strong><p>The finance and HR screens are planned for upcoming development sprints.</p></div><?php endif; ?>
    </article>
</section>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Recent sales orders</h2><p>Latest revenue activity</p></div><a class="text-link" href="sales.php">View all <span aria-hidden="true">→</span></a></div>
    <?php if (!$recentSales): ?>
        <div class="empty-state compact"><strong>No sales yet</strong><p>Create your first sale order to see it here.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($recentSales as $sale): ?>
            <tr>
                <td><strong><a href="sale.php?id=<?= (int) $sale['id'] ?>"><?= e($sale['order_number']) ?></a></strong></td>
                <td><?= e($sale['customer_name']) ?></td>
                <td><?= e(format_day($sale['order_date'])) ?></td>
                <td><?= e(money($sale['total_amount'])) ?></td>
                <td><span class="pill pill-<?= $sale['status'] === 'fulfilled' ? 'success' : ($sale['status'] === 'cancelled' ? 'neutral' : ($sale['status'] === 'confirmed' ? 'info' : 'warning')) ?>"><?= e(ucfirst($sale['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Pending Purchase Orders</h2><p>Items awaiting delivery</p></div><a class="text-link" href="purchases.php">View all <span aria-hidden="true">→</span></a></div>
    <?php 
    $pendingPos = db()->query('SELECT po.id, po.po_number, po.status, po.expected_date, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE po.status IN ("submitted", "part_received") ORDER BY po.id DESC LIMIT 5')->fetchAll();
    if (!$pendingPos): ?>
        <div class="empty-state compact"><strong>No pending orders</strong><p>Your active purchase orders will appear here.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>PO #</th><th>Supplier</th><th>Expected</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($pendingPos as $po): ?>
            <tr>
                <td><strong><a href="purchase.php?id=<?= (int) $po['id'] ?>"><?= e($po['po_number']) ?></a></strong></td>
                <td><?= e($po['supplier_name']) ?></td>
                <td><?= e(format_day($po['expected_date'])) ?></td>
                <td><span class="pill pill-<?= $po['status'] === 'part_received' ? 'info' : 'warning' ?>"><?= e(ucwords(str_replace('_', ' ', $po['status']))) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>

    <?php if (in_array(current_user()['role'], ['admin','manager','hr'])): 
    $pendingLeaves = db()->query('SELECT lr.id, lr.start_date, lr.end_date, lr.leave_type, e.first_name, e.last_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.status="pending" ORDER BY lr.id DESC LIMIT 5')->fetchAll();
    if ($pendingLeaves): ?>
    <section class="panel table-panel">
        <div class="panel-heading"><div><h2>Pending Leave Requests</h2><p>Staff awaiting your approval</p></div><a class="text-link" href="leave.php">View all <span aria-hidden="true">→</span></a></div>
        <div class="table-wrap"><table><thead><tr><th>Employee</th><th>Type</th><th>Dates</th></tr></thead><tbody>
        <?php foreach ($pendingLeaves as $leave): ?>
            <tr>
                <td><strong><?= e($leave['first_name'] . ' ' . $leave['last_name']) ?></strong></td>
                <td><?= e($leave['leave_type']) ?></td>
                <td><?= e(format_day($leave['start_date'])) ?> to <?= e(format_day($leave['end_date'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>
<?php endif; ?>
</section>

<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Recent stock activity</h2><p>A short history of inventory changes</p></div><a class="text-link" href="stock.php">View all movements <span aria-hidden="true">→</span></a></div>
    <?php if (!$recentMovements): ?>
        <div class="empty-state compact"><strong>No stock activity yet</strong><p>When you record a stock receipt, issue or count, it will appear here.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Product</th><th>Movement</th><th>Change</th><th>New balance</th><th>Recorded by</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($recentMovements as $movement): ?><tr><td><strong><?= e($movement['product_name']) ?></strong><small class="table-subtext"><?= e($movement['sku']) ?></small></td><td><span class="pill pill-<?= strtolower($movement['movement_type']) === 'out' ? 'neutral' : (strtolower($movement['movement_type']) === 'in' ? 'success' : 'info') ?>"><?= e($movement['movement_type']) ?></span></td><td><?= (float) $movement['quantity'] > 0 ? '+' : '' ?><?= e(number_format((float) $movement['quantity'], 2)) ?></td><td><?= e(number_format((float) $movement['new_quantity'], 2)) ?></td><td><?= e($movement['full_name'] ?? 'System') ?></td><td><?= e(format_date($movement['created_at'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('financeChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [
                {
                    label: 'Sales Revenue',
                    data: <?= json_encode($chartRevenue) ?>,
                    backgroundColor: 'rgba(22, 132, 91, 0.7)',
                    borderRadius: 4
                },
                {
                    label: 'Procurement Cost',
                    data: <?= json_encode($chartPOs) ?>,
                    backgroundColor: 'rgba(49, 105, 220, 0.7)',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true } }
        }
    });
});
</script>




<?php require __DIR__ . '/includes/footer.php'; ?>
