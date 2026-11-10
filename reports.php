<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'accountant']);
$pageTitle = 'Reports & Analytics';

$reportType = trim((string) ($_GET['type'] ?? 'pl'));
$startDate = trim((string) ($_GET['start'] ?? date('Y-m-01'))); // Default: 1st of this month
$endDate = trim((string) ($_GET['end'] ?? date('Y-m-d')));

$reportData = [];
$headers = [];
$summary = [];

if ($reportType === 'pl') {
    $stmt = db()->prepare("
        SELECT a.account_code, a.account_name, a.account_type,
               COALESCE(SUM(je.debit), 0) AS total_debit,
               COALESCE(SUM(je.credit), 0) AS total_credit
        FROM accounts a
        JOIN journal_entries je ON a.id = je.account_id
        WHERE a.account_type IN ('income', 'expense') AND je.entry_date BETWEEN ? AND ?
        GROUP BY a.id
        ORDER BY a.account_type DESC, a.account_code
    ");
    $stmt->execute([$startDate, $endDate]);
    $accounts = $stmt->fetchAll();

    $totalIncome = 0; $totalExpense = 0;
    foreach ($accounts as $a) {
        $net = $a['account_type'] === 'income' ? ($a['total_credit'] - $a['total_debit']) : ($a['total_debit'] - $a['total_credit']);
        if (abs($net) < 0.01) continue;

        if ($a['account_type'] === 'income') $totalIncome += $net;
        else $totalExpense += $net;

        $reportData[] = [
            'type' => ucfirst($a['account_type']),
            'code' => $a['account_code'],
            'name' => $a['account_name'],
            'amount' => $net
        ];
    }
    $summary = ['Total Income' => $totalIncome, 'Total Expense' => $totalExpense, 'Net Profit' => ($totalIncome - $totalExpense)];
    $headers = ['Type', 'Code', 'Account Name', 'Amount (KSh)'];

} elseif ($reportType === 'sales') {
    $stmt = db()->prepare("
        SELECT so.order_number, so.order_date, c.name AS customer_name, so.status, so.subtotal, so.tax_amount, so.total_amount
        FROM sales_orders so
        JOIN customers c ON c.id = so.customer_id
        WHERE so.status IN ('confirmed', 'fulfilled') AND so.order_date BETWEEN ? AND ?
        ORDER BY so.order_date DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $orders = $stmt->fetchAll();

    $totalRevenue = 0; $totalTax = 0;
    foreach ($orders as $o) {
        $totalRevenue += (float)$o['subtotal'];
        $totalTax += (float)$o['tax_amount'];
        $reportData[] = [
            'date' => $o['order_date'],
            'order' => $o['order_number'],
            'customer' => $o['customer_name'],
            'status' => ucfirst($o['status']),
            'subtotal' => $o['subtotal'],
            'tax' => $o['tax_amount'],
            'total' => $o['total_amount']
        ];
    }
    $summary = ['Total Sales (excl. tax)' => $totalRevenue, 'Tax Collected' => $totalTax, 'Gross Revenue' => ($totalRevenue + $totalTax)];
    $headers = ['Date', 'Order #', 'Customer', 'Status', 'Subtotal', 'Tax', 'Total'];

} elseif ($reportType === 'inventory') {
    $stmt = db()->query("
        SELECT p.sku, p.name, p.category, i.quantity, p.cost_price, p.unit_price,
               (i.quantity * p.cost_price) AS cost_value,
               (i.quantity * p.unit_price) AS retail_value
        FROM products p
        JOIN inventory i ON i.product_id = p.id
        WHERE p.is_active = 1
        ORDER BY p.name
    ");
    $products = $stmt->fetchAll();

    $totalCost = 0; $totalRetail = 0;
    foreach ($products as $p) {
        $totalCost += (float)$p['cost_value'];
        $totalRetail += (float)$p['retail_value'];
        $reportData[] = [
            'sku' => $p['sku'],
            'name' => $p['name'],
            'category' => $p['category'] ?? '—',
            'qty' => $p['quantity'],
            'cost' => $p['cost_price'],
            'cost_val' => $p['cost_value'],
            'retail_val' => $p['retail_value']
        ];
    }
    $summary = ['Total Cost Value' => $totalCost, 'Total Retail Value' => $totalRetail, 'Potential Margin' => ($totalRetail - $totalCost)];
    $headers = ['SKU', 'Product', 'Category', 'Qty', 'Unit Cost', 'Cost Value', 'Retail Value'];

} elseif ($reportType === 'tb') {
    $stmt = db()->query("
        SELECT a.account_code, a.account_name, a.account_type,
               COALESCE(SUM(je.debit), 0) AS total_debit,
               COALESCE(SUM(je.credit), 0) AS total_credit
        FROM accounts a
        LEFT JOIN journal_entries je ON a.id = je.account_id AND je.entry_date BETWEEN '$startDate' AND '$endDate'
        GROUP BY a.id
        ORDER BY a.account_code
    ");
    $accounts = $stmt->fetchAll();

    $grandDr = 0; $grandCr = 0;
    foreach ($accounts as $a) {
        $balance = (float)$a['total_debit'] - (float)$a['total_credit'];
        if (abs($balance) < 0.01) continue;
        
        $dr = $balance > 0 ? $balance : 0;
        $cr = $balance < 0 ? abs($balance) : 0;
        $grandDr += $dr; $grandCr += $cr;

        $reportData[] = [
            'code' => $a['account_code'],
            'name' => $a['account_name'],
            'type' => ucfirst($a['account_type']),
            'debit' => $dr,
            'credit' => $cr
        ];
    }
    $summary = ['Total Debits' => $grandDr, 'Total Credits' => $grandCr, 'Difference' => ($grandDr - $grandCr)];
    $headers = ['Code', 'Account', 'Type', 'Debit', 'Credit'];
}

require __DIR__ . '/includes/header.php';
?>
<section class="panel" style="margin-bottom:18px">
    <form method="get" class="search-form" style="flex-wrap:wrap;gap:12px">
        <div>
            <label style="display:block;font-size:10px;font-weight:700;margin-bottom:4px">Report Type</label>
            <select name="type" onchange="this.form.submit()" style="padding:10px 11px;border:1px solid #dce2eb;border-radius:9px;font-size:12px;background:#fff">
                <option value="pl" <?= $reportType === 'pl' ? 'selected' : '' ?>>Profit & Loss</option>
                <option value="sales" <?= $reportType === 'sales' ? 'selected' : '' ?>>Sales Summary</option>
                <option value="inventory" <?= $reportType === 'inventory' ? 'selected' : '' ?>>Inventory Valuation</option>
                <option value="tb" <?= $reportType === 'tb' ? 'selected' : '' ?>>Trial Balance</option>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:10px;font-weight:700;margin-bottom:4px">Start Date</label>
            <input type="date" name="start" value="<?= e($startDate) ?>" style="padding:9px 11px;border:1px solid #dce2eb;border-radius:9px;font-size:12px">
        </div>
        <div>
            <label style="display:block;font-size:10px;font-weight:700;margin-bottom:4px">End Date</label>
            <input type="date" name="end" value="<?= e($endDate) ?>" style="padding:9px 11px;border:1px solid #dce2eb;border-radius:9px;font-size:12px">
        </div>
        <div style="align-self:flex-end;display:flex;gap:8px">
            <button class="button button-secondary" type="submit">Run Report</button>
            <a class="button button-primary"  href="export.php?format=excel&type=<?= urlencode($reportType) ?>&start=<?= urlencode($startDate) ?>&end=<?= urlencode($endDate) ?>" class="button button-primary">Export Excel</a>
        </div>
    </form>
</section>

<section class="metrics-grid" style="margin-bottom:18px">
    <?php foreach ($summary as $label => $value): 
        $isNegative = $value < 0;
        $formatted = $isNegative ? '(' . money(abs($value)) . ')' : money($value);
    ?>
        <article class="metric-card">
            <div class="metric-label"><span><?= e($label) ?></span></div>
            <div class="metric-value" style="<?= $isNegative ? 'color:#b83e42' : '' ?>"><?= e($formatted) ?></div>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel">
    <div class="panel-heading"><div><h2>Report Data</h2><p><?= count($reportData) ?> record(s) found for the selected period.</p></div></div>
    <?php if (!$reportData): ?>
        <div class="empty-state"><strong>No data found</strong><p>Try adjusting the date range or adding transactions.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($reportData as $row): ?>
                <tr>
                    <?php foreach ($row as $key => $cell): 
                        $isMoney = str_contains($key, 'amount') || str_contains($key, 'total') || str_contains($key, 'cost') || str_contains($key, 'retail') || str_contains($key, 'debit') || str_contains($key, 'credit') || $key === 'subtotal' || $key === 'tax';
                    ?>
                        <td><?= $isMoney ? e(money($cell)) : e((string)$cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>