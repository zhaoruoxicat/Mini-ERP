<?php
// products_edit.php
declare(strict_types=1);
require __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM products WHERE id=?");
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { http_response_code(404); exit('Not found'); }

/** 分类 / 子分类映射（联动） */
$cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
$subsAll = $pdo->query("SELECT * FROM subcategories ORDER BY sort_order ASC, id ASC")->fetchAll();
$subsByCat = [];
foreach ($subsAll as $row) {
  $subsByCat[(int)$row['category_id']][] = $row;
}

/** 获取当前库存、预定、可用 */
function get_current_qty(PDO $pdo, int $productId): array {
  // 优先视图
  try {
    $sv = $pdo->prepare("SELECT stock_qty, reserved_qty, available_qty FROM v_available_stock WHERE product_id=?");
    $sv->execute([$productId]);
    if ($v = $sv->fetch()) {
      return [
        'stock'     => (float)$v['stock_qty'],
        'reserved'  => (float)$v['reserved_qty'],
        'available' => (float)$v['available_qty'],
      ];
    }
  } catch (Throwable $e) { /* 视图不可用则回退 */ }

  // 回退到表汇总
  $stock = 0.0; $reserved = 0.0;
  $st = $pdo->prepare("SELECT move_type, quantity FROM inventory_moves WHERE product_id=?");
  $st->execute([$productId]);
  foreach ($st->fetchAll() as $r) {
    $q = (float)$r['quantity'];
    if ($r['move_type']==='in') $stock += $q;
    elseif ($r['move_type']==='out') $stock -= $q;
    else $stock += $q; // adjust
  }
  $st = $pdo->prepare("SELECT quantity FROM reservations WHERE status='pending' AND product_id=?");
  $st->execute([$productId]);
  foreach ($st->fetchAll() as $r) { $reserved += (float)$r['quantity']; }

  return ['stock'=>$stock, 'reserved'=>$reserved, 'available'=>$stock-$reserved];
}

$curr = get_current_qty($pdo, $id);

/** 提交保存 */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name = trim($_POST['name'] ?? '');
  $sku  = trim($_POST['sku'] ?? '') ?: null;
  $category_id = (int)($_POST['category_id'] ?? 0);
  $subcategory_id = (int)($_POST['subcategory_id'] ?? 0);
  if ($subcategory_id <= 0) $subcategory_id = null;
  $unit  = trim($_POST['unit'] ?? 'pcs');
  $price = $_POST['price'] === '' ? '0' : $_POST['price'];

  $color = trim($_POST['color'] ?? '') ?: null;
  $product_date = trim($_POST['product_date'] ?? '') ?: null;
  $weight = ($_POST['weight'] === '' ? null : $_POST['weight']);

  $brand = trim($_POST['brand'] ?? '') ?: null;
  $spec  = trim($_POST['spec'] ?? '') ?: null;
  $supplier = trim($_POST['supplier'] ?? '') ?: null;
  $note  = trim($_POST['note'] ?? '') ?: null;

  // 目标库存与目标预定（与新增页对应：新增页是“初始库存/初始预定”）
  $target_stock    = $_POST['target_stock']    === '' ? null : (float)$_POST['target_stock'];
  $target_reserved = $_POST['target_reserved'] === '' ? null : (float)$_POST['target_reserved'];

  if ($name!=='' && $category_id>0) {
    // 1) 更新产品基础信息
    $stU = $pdo->prepare("UPDATE products SET
      name=?, sku=?, category_id=?, subcategory_id=?, unit=?, price=?, brand=?, spec=?, supplier=?, color=?, product_date=?, weight=?, note=?
      WHERE id=?");
    $stU->execute([$name,$sku,$category_id,$subcategory_id,$unit,$price,$brand,$spec,$supplier,$color,$product_date?:null,$weight,$note,$id]);

    // 2) 重新读取当前库存/预定，计算调整量
    $curr2 = get_current_qty($pdo, $id);
    if ($target_stock !== null)    { if ($target_stock < 0) $target_stock = 0; }
    if ($target_reserved !== null) { if ($target_reserved < 0) $target_reserved = 0; }

    // 2a) 库存：目标值 -> 写一条 adjust 流水
    if ($target_stock !== null) {
      $deltaStock = $target_stock - $curr2['stock'];
      if (abs($deltaStock) > 1e-9) {
        $stm = $pdo->prepare("INSERT INTO inventory_moves(product_id, move_type, quantity, note) VALUES(?,?,?,?)");
        $stm->execute([$id, 'adjust', $deltaStock, '编辑产品页：调整库存到目标值']);
      }
    }

    // 2b) 预定：目标值 -> 写一条 pending 预定调整（可为负，表示释放）
    if ($target_reserved !== null) {
      $deltaRes = $target_reserved - $curr2['reserved'];
      if (abs($deltaRes) > 1e-9) {
        $str = $pdo->prepare("INSERT INTO reservations(product_id, quantity, customer, expected_date, status, note)
                              VALUES(?,?,?,?, 'pending', ?)");
        $str->execute([$id, $deltaRes, '调整', null, '编辑产品页：调整预定到目标值']);
      }
    }

    header("Location: inventory.php");
    exit;
  }
}

include __DIR__ . '/inc/header.php';
?>
<div class="card">
  <div class="card-header">
    <h3 class="card-title">编辑产品</h3>
    <div class="ms-auto small text-muted">
      当前库存：<?= rtrim(rtrim(number_format($curr['stock'],3,'.',''), '0'),'.') ?>
      / 预定：<?= rtrim(rtrim(number_format($curr['reserved'],3,'.',''), '0'),'.') ?>
      / 可用：<?= rtrim(rtrim(number_format($curr['available'],3,'.',''), '0'),'.') ?>
    </div>
  </div>
  <div class="card-body">
    <form method="post" class="row g-3">

      <!-- 与新增页一致的顺序 -->
      <div class="col-md-4">
        <label class="form-label">产品名称</label>
        <input class="form-control" name="name" value="<?= h($p['name']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">SKU</label>
        <input class="form-control" name="sku" value="<?= h($p['sku']) ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">分类</label>
        <select class="form-select" name="category_id" id="edit-cat" required>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$p['category_id']===(int)$c['id']?'selected':'' ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">子分类</label>
        <select class="form-select" name="subcategory_id" id="edit-sub">
          <option value="">选择子分类（可空）</option>
          <?php foreach ($subsByCat[$p['category_id']] ?? [] as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)($p['subcategory_id'] ?? 0)===(int)$s['id']?'selected':'' ?>>
              <?= h($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">单位</label>
        <input class="form-control" name="unit" value="<?= h($p['unit']) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">价格</label>
        <input class="form-control" name="price" type="number" step="0.01" value="<?= h((string)$p['price']) ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">颜色</label>
        <input class="form-control" name="color" value="<?= h($p['color']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">日期</label>
        <input class="form-control" name="product_date" type="date" value="<?= h($p['product_date']) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">重量</label>
        <input class="form-control" name="weight" type="number" step="0.001" value="<?= h((string)$p['weight']) ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">品牌</label>
        <input class="form-control" name="brand" value="<?= h($p['brand']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">规格</label>
        <input class="form-control" name="spec" value="<?= h($p['spec']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">供应商</label>
        <input class="form-control" name="supplier" value="<?= h($p['supplier']) ?>">
      </div>

      <div class="col-12">
        <label class="form-label">备注</label>
        <input class="form-control" name="note" value="<?= h($p['note']) ?>">
      </div>

      <!-- 新增：与新增页对应的 库存/预定 字段（这里为“目标值”） -->
      <div class="col-md-3">
        <label class="form-label">库存数量（目标）</label>
        <input class="form-control" name="target_stock" type="number" step="0.001"
               value="<?= h((string)$curr['stock']) ?>" min="0">
        <div class="form-text">当前：<?= rtrim(rtrim(number_format($curr['stock'],3,'.',''), '0'),'.') ?></div>
      </div>
      <div class="col-md-3">
        <label class="form-label">预定数量（目标）</label>
        <input class="form-control" name="target_reserved" type="number" step="0.001"
               value="<?= h((string)$curr['reserved']) ?>" min="0">
        <div class="form-text">当前：<?= rtrim(rtrim(number_format($curr['reserved'],3,'.',''), '0'),'.') ?></div>
      </div>

      <div class="col-12">
        <button class="btn btn-primary">保存</button>
        <a class="btn btn-outline-secondary" href="inventory.php">返回</a>
      </div>
    </form>
  </div>
</div>

<script>
const subsByCat = <?= json_encode($subsByCat, JSON_UNESCAPED_UNICODE) ?>;
const editCat = document.getElementById('edit-cat');
const editSub = document.getElementById('edit-sub');

function rebuildEditSubs(catId, selectedId) {
  editSub.innerHTML = '<option value="">选择子分类（可空）</option>';
  const list = subsByCat[catId] || [];
  list.forEach(s => {
    const o = document.createElement('option');
    o.value = s.id;
    o.textContent = s.name;
    if (selectedId && String(selectedId) === String(s.id)) o.selected = true;
    editSub.appendChild(o);
  });
}

if (editCat) {
  editCat.addEventListener('change', function() {
    rebuildEditSubs(this.value, null);
  });
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
