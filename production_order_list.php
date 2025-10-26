<?php
// production_order_list.php —— 排产单列表
// 角色规则：sales 仅看自己；op/boss 看全部；其他角色禁止访问
// 编辑规则：boss/op 可编辑全部；sales 仅能编辑自己
// 依赖：/inc/header.php（含登录校验与本地 Tabler 资源）、/inc/footer.php、db.php（PDO）

declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/inc/header.php';
date_default_timezone_set('Asia/Shanghai');

/** ---------- helpers ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function to_int($v, int $def=0): int {
  if ($v === null) return $def;
  $v = is_numeric($v) ? (int)$v : $def;
  return max($v, 0);
}
function url_with(array $params): string {
  $base = strtok($_SERVER['REQUEST_URI'], '?') ?: 'production_order_list.php';
  $merged = array_merge($_GET, $params);
  return $base . '?' . http_build_query($merged);
}

/** ---------- current user & role gate ---------- */
$me = $_SESSION['user'] ?? null; // /inc/header.php 登录校验后应设置
$currentUserId = (int)($me['id'] ?? 0);
$currentRole   = (string)($me['role'] ?? '');

$allowedRoles = ['sales','op','boss']; // 如需兼容 planner，可改为 ['sales','op','boss','planner']
if (!in_array($currentRole, $allowedRoles, true)) {
  ?>
  <div class="container-xl py-6">
    <div class="text-center">
      <div style="font-size:28px;color:#d63939;font-weight:700;letter-spacing:.5px;">权限不足</div>
      <div style="margin-top:8px;color:#666;">抱歉，你没有访问本页面的权限。若有疑问请联系管理员开通相应角色。</div>
    </div>
  </div>
  <?php
  require __DIR__ . '/inc/footer.php';
  exit;
}

/** ---------- inputs ---------- */
$q         = trim((string)($_GET['q'] ?? ''));
$dateFrom  = trim((string)($_GET['date_from'] ?? '')); // YYYY-MM-DD
$dateTo    = trim((string)($_GET['date_to'] ?? ''));   // YYYY-MM-DD
$page      = max(1, (int)($_GET['page'] ?? 1));
$pageSize  = 10;
$offset    = ($page - 1) * $pageSize;

/** ---------- where building ---------- */
$where = [];
$params = [];

// 角色范围（sales 仅看自己；op/boss 全部）
if ($currentRole === 'sales') {
  $where[] = 'po.sales_user_id = :sales_uid';
  $params[':sales_uid'] = $currentUserId;
}

// 关键词（订单号 / 客户名 / 备注）
if ($q !== '') {
  $where[] = '(po.order_no LIKE :kw OR po.customer_name LIKE :kw OR po.note LIKE :kw)';
  $params[':kw'] = '%'.$q.'%';
}

// 时间筛选（创建时间）
if ($dateFrom !== '') {
  $where[] = 'po.created_at >= :date_from';
  $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
  $where[] = 'po.created_at <= :date_to';
  $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/** ---------- count & query ---------- */
$countSQL = "SELECT COUNT(1)
             FROM production_orders po
             $whereSQL";
$st = $pdo->prepare($countSQL);
$st->execute($params);
$total = (int)$st->fetchColumn();

$listSQL = "SELECT
              po.id, po.order_no, po.customer_name,
              po.sales_user_id, po.planner_user_id,
              po.status_id, po.scheduled_date, po.due_date,
              po.note, po.version, po.created_at, po.updated_at,
              s.name AS status_name,
              su.display_name AS sales_name,
              pu.display_name AS planner_name
            FROM production_orders po
            LEFT JOIN production_statuses s ON s.id = po.status_id
            LEFT JOIN users su ON su.id = po.sales_user_id
            LEFT JOIN users pu ON pu.id = po.planner_user_id
            $whereSQL
            ORDER BY po.updated_at DESC
            LIMIT :limit OFFSET :offset";

$st = $pdo->prepare($listSQL);
foreach ($params as $k => $v) {
  $st->bindValue($k, $v);
}
$st->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
$st->bindValue(':offset', $offset,   PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$totalPages = (int)ceil($total / $pageSize);

/** ---------- role-based action: can edit? ---------- */
// 把老板从只读改为可编辑全部
$canEditAll  = ($currentRole === 'op' || $currentRole === 'boss'); // 库管/排产 & 老板：可编辑全部
$readOnlyAll = false;                                              // 老板不再只读
$canEditOwn  = ($currentRole === 'sales');                         // 销售只能编辑自己单
?>
<div class="container-xl">
  <!-- 顶部筛选 -->
  <div class="row g-3 align-items-end mt-4">
    <div class="col-12 col-md-3">
      <label class="form-label">关键词</label>
      <input type="text" class="form-control" name="q" form="filterForm" value="<?=h($q)?>" placeholder="订单号 / 客户名 / 备注">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label">起始日期</label>
      <input type="date" class="form-control" name="date_from" form="filterForm" value="<?=h($dateFrom)?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label">结束日期</label>
      <input type="date" class="form-control" name="date_to" form="filterForm" value="<?=h($dateTo)?>">
    </div>
    <div class="col-12 col-md-5 d-flex gap-2">
      <form id="filterForm" method="get" class="d-flex gap-2 w-100">
        <!-- 隐藏 page，搜索时回到第1页 -->
        <input type="hidden" name="page" value="1">
        <button class="btn btn-primary">查询</button>
        <a class="btn btn-outline-secondary" href="<?=h(strtok($_SERVER['REQUEST_URI'],'?'))?>">重置</a>
      </form>
      <a class="btn btn-success" href="production_order_edit.php">新增排产单</a>
    </div>
  </div>

  <!-- 统计信息 -->
  <div class="row mt-3">
    <div class="col">
      <div class="card">
        <div class="card-body py-2">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <strong>总数：</strong><?=h((string)$total)?>，
              <strong>每页：</strong><?=h((string)$pageSize)?>，
              <strong>当前页：</strong><?=h((string)$page)?> / <?=h((string)max(1,$totalPages))?>
              <?php if ($currentRole === 'sales'): ?>
                <span class="ms-2 text-muted">（仅显示你创建的排产单）</span>
              <?php else: ?>
                <span class="ms-2 text-muted">（显示全部排产单）</span>
              <?php endif; ?>
            </div>
            <div class="text-muted">按最近更新排序</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 数据表 -->
  <div class="row mt-3">
    <div class="col">
      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover table-vcenter card-table mb-0">
              <thead>
                <tr>
                  <th style="width:110px;">订单号</th>
                  <th>客户</th>
                  <th style="width:140px;">状态</th>
                  <th style="width:130px;">计划排产日</th>
                  <th style="width:130px;">要求交期</th>
                  <th style="width:140px;">下单</th>
                  <th style="width:140px;">排产</th>
                  <th style="width:150px;">创建时间</th>
                  <th style="width:150px;">更新时间</th>
                  <th style="width:160px;" class="text-end">操作</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr><td colspan="10" class="text-center text-muted py-4">暂无数据</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $r):
                    $id     = (int)$r['id'];
                    $isOwn  = ((int)$r['sales_user_id'] === $currentUserId);
                    // 可编辑：boss/op 全部；sales 自己
                    $canEdit = ($canEditAll || ($canEditOwn && $isOwn)) && !$readOnlyAll;

                    // ✅ 查看页改为 production_order_view.php
                    $viewHref = 'production_order_view.php?id=' . $id;
                    // 编辑仍指向编辑页
                    $editHref = 'production_order_edit.php?id=' . $id;
                  ?>
                  <tr>
                    <td><a href="<?=h($viewHref)?>" class="text-reset"><?=h($r['order_no'])?></a></td>
                    <td><?=h($r['customer_name'])?></td>
                    <td><?=h($r['status_name'] ?? '—')?></td>
                    <td><?=h($r['scheduled_date'] ?: '—')?></td>
                    <td><?=h($r['due_date'] ?: '—')?></td>
                    <td><?=h($r['sales_name'] ?: '—')?></td>
                    <td><?=h($r['planner_name'] ?: '—')?></td>
                    <td><?=h($r['created_at'])?></td>
                    <td><?=h($r['updated_at'])?></td>
                    <td class="text-end">
                      <!-- 所有人都有“查看” -->
                      <a class="btn btn-sm btn-outline-secondary" href="<?=h($viewHref)?>">查看</a>
                      <?php if ($canEdit): ?>
                        <a class="btn btn-sm btn-primary ms-1" href="<?=h($editHref)?>">编辑</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <div class="small text-muted">
            显示第 <?=h((string)($offset+1))?> - <?=h((string)min($offset+$pageSize, $total))?> 条
          </div>
          <div class="pagination">
            <ul class="pagination m-0">
              <li class="page-item <?=($page<=1?'disabled':'')?>">
                <a class="page-link" href="<?=h(url_with(['page'=>1]))?>">首页</a>
              </li>
              <li class="page-item <?=($page<=1?'disabled':'')?>">
                <a class="page-link" href="<?=h(url_with(['page'=>max(1,$page-1)]))?>">上一页</a>
              </li>
              <?php
                // 简单窗口分页：当前页±2
                $start = max(1, $page-2);
                $end   = min($totalPages, $page+2);
                for ($i=$start; $i<=$end; $i++):
              ?>
                <li class="page-item <?=($i===$page?'active':'')?>"><a class="page-link" href="<?=h(url_with(['page'=>$i]))?>"><?=$i?></a></li>
              <?php endfor; ?>
              <li class="page-item <?=($page>=$totalPages?'disabled':'')?>">
                <a class="page-link" href="<?=h(url_with(['page'=>min($totalPages,$page+1)]))?>">下一页</a>
              </li>
              <li class="page-item <?=($page>=$totalPages?'disabled':'')?>">
                <a class="page-link" href="<?=h(url_with(['page'=>$totalPages]))?>">末页</a>
              </li>
            </ul>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php';
