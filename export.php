<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'accountant']);

$reportType = trim((string) ($_GET['type'] ?? 'pl'));
$startDate = trim((string) ($_GET['start'] ?? date('Y-m-01')));
$endDate = trim((string) ($_GET['end'] ?? date('Y-m-d')));

$headers = [];
$data = [];
$filename = "report_{$reportType}_" . date('Ymd') . ".xls";

if ($reportType === 'pl') {
    $stmt = db()->prepare("
        SELECT a.account_code, a.account_name, a.account_type,
               COALESCE(SUM(je.debit), 0) AS total_debit,
               COALESCE(SUM(je.credit), 0) AS total_credit
        FROM accounts a
        JOIN journal_entries je ON a.id = je.account_id
        WHERE a.account_type IN ('income', 'expense') AND je.entry_date BETWEEN ? AND ?
        GROUP BY a.id ORDER BY a.account_type DESC, a.account_code
    ");
    $stmt->execute([$startDate, $endDate]);
    $headers = ['Type', 'Code', 'Account Name', 'Net Amount (KSh)'];
    foreach ($stmt->fetchAll() as $a) {
        $net = $a['account_type'] === 'income' ? ($a['total_credit'] - $a['total_debit']) : ($a['total_debit'] - $a['total_credit']);
        if (abs($net) < 0.01) continue;
        $data[] = [ucfirst($a['account_type']), $a['account_code'], $a['account_name'], number_format($net, 2, '.', '')];
    }

} elseif ($reportType === 'sales') {
    $stmt = db()->prepare("
        SELECT so.order_number, so.order_date, c.name AS customer_name, so.status, so.subtotal, so.tax_amount, so.total_amount
        FROM sales_orders so JOIN customers c ON c.id = so.customer_id
        WHERE so.status IN ('confirmed', 'fulfilled') AND so.order_date BETWEEN ? AND ?
        ORDER BY so.order_date DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $headers = ['Date', 'Order #', 'Customer', 'Status', 'Subtotal', 'Tax', 'Total'];
    foreach ($stmt->fetchAll() as $o) {
        $data[] = [$o['order_date'], $o['order_number'], $o['customer_name'], ucfirst($o['status']), $o['subtotal'], $o['tax_amount'], $o['total_amount']];
    }

} elseif ($reportType === 'inventory') {
    $stmt = db()->query("
        SELECT p.sku, p.name, p.category, i.quantity, p.cost_price, p.unit_price,
               (i.quantity * p.cost_price) AS cost_value, (i.quantity * p.unit_price) AS retail_value
        FROM products p JOIN inventory i ON i.product_id = p.id WHERE p.is_active = 1 ORDER BY p.name
    ");
    $headers = ['SKU', 'Product Name', 'Category', 'Qty', 'Unit Cost', 'Cost Value', 'Retail Value'];
    foreach ($stmt->fetchAll() as $p) {
        $data[] = [$p['sku'], $p['name'], $p['category'] ?? '', $p['quantity'], $p['cost_price'], $p['cost_value'], $p['retail_value']];
    }

} elseif ($reportType === 'tb') {
    $stmt = db()->prepare("
        SELECT a.account_code, a.account_name, a.account_type,
               COALESCE(SUM(je.debit), 0) AS total_debit, COALESCE(SUM(je.credit), 0) AS total_credit
        FROM accounts a
        LEFT JOIN journal_entries je ON a.id = je.account_id AND je.entry_date BETWEEN ? AND ?
        GROUP BY a.id ORDER BY a.account_code
    ");
    $stmt->execute([$startDate, $endDate]);
    $headers = ['Code', 'Account Name', 'Type', 'Debit', 'Credit'];
    foreach ($stmt->fetchAll() as $a) {
        $balance = (float)$a['total_debit'] - (float)$a['total_credit'];
        if (abs($balance) < 0.01) continue;
        $dr = $balance > 0 ? $balance : 0;
        $cr = $balance < 0 ? abs($balance) : 0;
        $data[] = [$a['account_code'], $a['account_name'], ucfirst($a['account_type']), $dr ?: '', $cr ?: ''];
    }
}

// ==========================================
// EXCEL OUTPUT LOGIC
// ==========================================

// 1. Set headers to force download as an Excel file
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// 2. Add a UTF-8 BOM so Excel reads special characters (like KSh) correctly
echo "\xEF\xBB\xBF"; 

// 3. Output the HTML table
?>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* Styling so it looks good when opened in Excel */
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #1c3150; color: #ffffff; font-weight: bold; text-align: left; padding: 8px; }
        td { border: 1px solid #dddddd; padding: 8px; }
    </style>
</head>
<body>
    <table>
        <thead>
            <tr>
                <?php foreach ($headers as $header): ?>
                    <th><?= htmlspecialchars($header) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data)): ?>
                <tr>
                    <td colspan="<?= count($headers) ?>" style="text-align:center;">No data found for this period.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?= htmlspecialchars((string)$cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
<?php
exit; // Stop script execution so no other code runs