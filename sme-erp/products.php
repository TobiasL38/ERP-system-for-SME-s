<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'inventory', 'procurement']);
$pageTitle = 'Products';
$error = '';
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $sku = strtoupper(post_string('sku', 60));
            $name = post_string('name', 150);
            $category = post_string('category', 100);
            $description = post_string('description', 1000);
            $priceRaw = (string) ($_POST['unit_price'] ?? '');
            $costRaw = (string) ($_POST['cost_price'] ?? '0');
            $reorderRaw = (string) ($_POST['reorder_level'] ?? '0');
            if ($sku === '' || $name === '') {
                throw new RuntimeException('SKU and product name are required.');
            }
            if (!is_numeric($priceRaw) || (float) $priceRaw < 0 || !is_numeric($costRaw) || (float) $costRaw < 0 || !is_numeric($reorderRaw) || (float) $reorderRaw < 0) {
                throw new RuntimeException('Price, cost and reorder level must be valid non-negative numbers.');
            }
            if ($id > 0) {
                $stmt = db()->prepare('UPDATE products SET sku=?, name=?, category=?, description=?, unit_price=?, cost_price=?, reorder_level=? WHERE id=?');
                $stmt->execute([$sku, $name, $category ?: null, $description ?: null, $priceRaw, $costRaw, $reorderRaw, $id]);
                flash('success', 'Product details updated.');
            } else {
                db()->beginTransaction();
                $stmt = db()->prepare('INSERT INTO products (sku, name, category, description, unit_price, cost_price, reorder_level, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
                $stmt->execute([$sku, $name, $category ?: null, $description ?: null, $priceRaw, $costRaw, $reorderRaw]);
                $productId = (int) db()->lastInsertId();
                db()->prepare('INSERT INTO inventory (product_id, quantity) VALUES (?, 0)')->execute([$productId]);
                db()->commit();
                flash('success', 'Product created. Record its opening quantity on the Stock movements page.');
            }
            redirect('products.php');
        }
        if ($action === 'deactivate') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Invalid product.');
            db()->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([$id]);
            flash('success', 'Product archived. Existing stock history has been preserved.');
            redirect('products.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = $exception->getCode() === '23000' ? 'That SKU is already in use. Choose a unique SKU.' : 'The product could not be saved. Check the entered values and try again.';
    }
}

if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id=? AND is_active=1');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}
$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT p.*, COALESCE(i.quantity, 0) AS quantity FROM products p LEFT JOIN inventory i ON i.product_id=p.id WHERE p.is_active=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (p.sku LIKE ? OR p.name LIKE ? OR p.category LIKE ?)';
    $term = '%' . $search . '%';
    $params = [$term, $term, $term];
}
$sql .= ' ORDER BY p.name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2><?= $editing ? 'Edit product' : 'Add product' ?></h2><p>Set catalogue details and reorder threshold.</p></div><?php if ($editing): ?><a class="text-link" href="products.php">Cancel</a><?php endif; ?></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">
            <div class="field-grid"><div><label for="sku">SKU / product code *</label><input id="sku" name="sku" maxlength="60" required value="<?= e($editing['sku'] ?? '') ?>" placeholder="e.g. PRD-001"></div><div><label for="name">Product name *</label><input id="name" name="name" maxlength="150" required value="<?= e($editing['name'] ?? '') ?>" placeholder="Product name"></div></div>
            <div><label for="category">Category</label><input id="category" name="category" maxlength="100" value="<?= e($editing['category'] ?? '') ?>" placeholder="e.g. Electronics"></div>
            <div><label for="description">Description</label><textarea id="description" name="description" rows="3" maxlength="1000" placeholder="Optional product details"><?= e($editing['description'] ?? '') ?></textarea></div>
            <div class="field-grid three-cols"><div><label for="unit_price">Selling price (KSh) *</label><input id="unit_price" name="unit_price" type="number" min="0" step="0.01" required value="<?= e($editing['unit_price'] ?? '0.00') ?>"></div><div><label for="cost_price">Unit cost (KSh)</label><input id="cost_price" name="cost_price" type="number" min="0" step="0.01" value="<?= e($editing['cost_price'] ?? '0.00') ?>"></div><div><label for="reorder_level">Reorder at</label><input id="reorder_level" name="reorder_level" type="number" min="0" step="0.01" value="<?= e($editing['reorder_level'] ?? '0') ?>"></div></div>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Create product' ?></button>
        </form>
    </article>
    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Product catalogue</h2><p><?= count($products) ?> active item(s)</p></div></div>
        <form method="get" class="search-form"><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, SKU or category"><button class="button button-secondary" type="submit">Search</button><?php if ($search !== ''): ?><a class="text-link" href="products.php">Clear</a><?php endif; ?></form>
        <?php if (!$products): ?><div class="empty-state compact"><strong>No products found</strong><p>Create a product using the form to get started.</p></div><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Product</th><th>Price</th><th>Stock</th><th></th></tr></thead><tbody>
        <?php foreach ($products as $product): ?><tr><td><strong><?= e($product['name']) ?></strong><small class="table-subtext"><?= e($product['sku']) ?><?= $product['category'] ? ' · ' . e($product['category']) : '' ?></small></td><td><?= e(money($product['unit_price'])) ?></td><td><span class="<?= (float) $product['quantity'] <= (float) $product['reorder_level'] ? 'stock-low' : 'stock-ok' ?>"><?= e(number_format((float) $product['quantity'], 2)) ?></span></td><td><div class="row-actions"><a class="text-link" href="products.php?edit=<?= (int) $product['id'] ?>">Edit</a><form method="post" data-confirm="Archive this product? Its historical movements will remain." class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><button class="link-button danger-link" type="submit">Archive</button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
