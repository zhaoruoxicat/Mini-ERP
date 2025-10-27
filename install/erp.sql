

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";



--

-- --------------------------------------------------------

--
-- 表的结构 `categories`
--

CREATE TABLE `categories` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `inventory_moves`
--

CREATE TABLE `inventory_moves` (
  `id` bigint UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `move_type` enum('in','out','adjust') COLLATE utf8mb4_general_ci NOT NULL,
  `quantity` decimal(16,3) NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `production_orders`
--

CREATE TABLE `production_orders` (
  `id` bigint UNSIGNED NOT NULL,
  `order_no` varchar(40) NOT NULL,
  `customer_name` varchar(120) DEFAULT NULL,
  `sales_user_id` int UNSIGNED NOT NULL,
  `planner_user_id` int UNSIGNED DEFAULT NULL,
  `status_id` int UNSIGNED NOT NULL,
  `last_changed_by` int UNSIGNED DEFAULT NULL,
  `scheduled_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `note` text,
  `version` int UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- 触发器 `production_orders`
--
DELIMITER $$
CREATE TRIGGER `trg_po_status_forward_only` BEFORE UPDATE ON `production_orders` FOR EACH ROW BEGIN
  DECLARE old_order INT DEFAULT NULL;
  DECLARE new_order INT DEFAULT NULL;

  IF NEW.status_id <> OLD.status_id THEN
    SELECT sort_order INTO old_order
    FROM production_statuses WHERE id = OLD.status_id;

    SELECT sort_order INTO new_order
    FROM production_statuses WHERE id = NEW.status_id;

    IF new_order IS NULL OR old_order IS NULL OR new_order <> old_order + 1 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '状态必须按顺序逐步推进（只可前进一步）';
    END IF;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_po_status_history` AFTER UPDATE ON `production_orders` FOR EACH ROW BEGIN
  /* 只有状态变化才记录历史 */
  IF NEW.status_id <> OLD.status_id THEN
    INSERT INTO production_status_history
      (order_id, from_status_id, to_status_id, changed_by, remark)
    VALUES
      (
        NEW.id,
        OLD.status_id,
        NEW.status_id,
        COALESCE(NEW.last_changed_by, NEW.planner_user_id, NEW.sales_user_id),
        NULL
      );
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `production_order_items`
--

CREATE TABLE `production_order_items` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `product_sku` varchar(64) NOT NULL,
  `product_name` varchar(160) NOT NULL,
  `spec` varchar(160) DEFAULT NULL,
  `color` varchar(60) DEFAULT NULL,
  `qty` decimal(18,3) NOT NULL,
  `unit` varchar(16) NOT NULL DEFAULT 'pcs',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `production_statuses`
--

CREATE TABLE `production_statuses` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(40) NOT NULL,
  `key_name` varchar(40) NOT NULL,
  `sort_order` int UNSIGNED NOT NULL,
  `is_final` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `production_status_history`
--

CREATE TABLE `production_status_history` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` bigint UNSIGNED NOT NULL,
  `from_status_id` int UNSIGNED DEFAULT NULL,
  `to_status_id` int UNSIGNED NOT NULL,
  `changed_by` int UNSIGNED NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `remark` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `products`
--

CREATE TABLE `products` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(180) COLLATE utf8mb4_general_ci NOT NULL,
  `sku` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `subcategory_id` int UNSIGNED DEFAULT NULL,
  `unit` varchar(40) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pcs',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `brand` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `spec` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `supplier` varchar(180) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `color` varchar(60) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `product_date` date DEFAULT NULL,
  `weight` decimal(12,3) DEFAULT NULL,
  `note` text COLLATE utf8mb4_general_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `reservations`
--

CREATE TABLE `reservations` (
  `id` bigint UNSIGNED NOT NULL,
  `product_id` int UNSIGNED NOT NULL,
  `quantity` decimal(16,3) NOT NULL,
  `customer` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `status` enum('pending','fulfilled','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `note` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `subcategories`
--

CREATE TABLE `subcategories` (
  `id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_general_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `username` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `display_name` varchar(128) COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('boss','op','sales') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'sales',
  `password_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 替换视图以便查看 `v_available_stock`
-- （参见下面的实际视图）
--
CREATE TABLE `v_available_stock` (
);

-- --------------------------------------------------------

--
-- 替换视图以便查看 `v_reserved_pending`
-- （参见下面的实际视图）
--
CREATE TABLE `v_reserved_pending` (
`product_id` int unsigned
,`reserved_qty` decimal(38,3)
);

-- --------------------------------------------------------

--
-- 替换视图以便查看 `v_stock`
-- （参见下面的实际视图）
--
CREATE TABLE `v_stock` (
`product_id` int unsigned
,`stock_qty` decimal(38,3)
);

--
-- 转储表的索引
--

--
-- 表的索引 `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_categories_name` (`name`);

--
-- 表的索引 `inventory_moves`
--
ALTER TABLE `inventory_moves`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_product_created` (`product_id`,`created_at`);

--
-- 表的索引 `production_orders`
--
ALTER TABLE `production_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_no` (`order_no`),
  ADD KEY `fk_po_status` (`status_id`),
  ADD KEY `fk_po_sales` (`sales_user_id`),
  ADD KEY `fk_po_last_changed_by` (`last_changed_by`);

--
-- 表的索引 `production_order_items`
--
ALTER TABLE `production_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_poi_order` (`order_id`);

--
-- 表的索引 `production_statuses`
--
ALTER TABLE `production_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_name` (`key_name`),
  ADD UNIQUE KEY `uq_status_order` (`sort_order`);

--
-- 表的索引 `production_status_history`
--
ALTER TABLE `production_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_psh_to_status` (`to_status_id`),
  ADD KEY `fk_psh_user` (`changed_by`),
  ADD KEY `idx_psh_order` (`order_id`);

--
-- 表的索引 `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_products_sku` (`sku`),
  ADD KEY `fk_products_category` (`category_id`),
  ADD KEY `fk_products_subcategory` (`subcategory_id`);

--
-- 表的索引 `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_reservations_product` (`product_id`),
  ADD KEY `idx_reservations_status_date` (`status`,`expected_date`);

--
-- 表的索引 `subcategories`
--
ALTER TABLE `subcategories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_subcategories_cat_name` (`category_id`,`name`);

--
-- 表的索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `inventory_moves`
--
ALTER TABLE `inventory_moves`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `production_orders`
--
ALTER TABLE `production_orders`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `production_order_items`
--
ALTER TABLE `production_order_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `production_statuses`
--
ALTER TABLE `production_statuses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `production_status_history`
--
ALTER TABLE `production_status_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `products`
--
ALTER TABLE `products`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `subcategories`
--
ALTER TABLE `subcategories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- 视图结构 `v_available_stock`
--
DROP TABLE IF EXISTS `v_available_stock`;

CREATE ALGORITHM=UNDEFINED DEFINER=`erp`@`localhost` SQL SECURITY DEFINER VIEW `v_available_stock`  AS SELECT `p`.`id` AS `product_id`, coalesce(`s`.`stock_qty`,0) AS `stock_qty`, coalesce(`r`.`reserved_qty`,0) AS `reserved_qty`, (coalesce(`s`.`stock_qty`,0) - coalesce(`r`.`reserved_qty`,0)) AS `available_qty` FROM ((`products` `p` left join `v_stock` `s` on((`s`.`product_id` = `p`.`id`))) left join `v_reserved_pending` `r` on((`r`.`product_id` = `p`.`id`))) ;

-- --------------------------------------------------------

--
-- 视图结构 `v_reserved_pending`
--
DROP TABLE IF EXISTS `v_reserved_pending`;

CREATE ALGORITHM=UNDEFINED DEFINER=`erp`@`localhost` SQL SECURITY DEFINER VIEW `v_reserved_pending`  AS SELECT `p`.`id` AS `product_id`, coalesce(sum((case when (`r`.`status` = 'pending') then `r`.`quantity` else 0 end)),0) AS `reserved_qty` FROM (`products` `p` left join `reservations` `r` on((`r`.`product_id` = `p`.`id`))) GROUP BY `p`.`id` ;

-- --------------------------------------------------------

--
-- 视图结构 `v_stock`
--
DROP TABLE IF EXISTS `v_stock`;

CREATE ALGORITHM=UNDEFINED DEFINER=`erp`@`localhost` SQL SECURITY DEFINER VIEW `v_stock`  AS SELECT `p`.`id` AS `product_id`, coalesce(sum((case when (`im`.`move_type` = 'in') then `im`.`quantity` when (`im`.`move_type` = 'out') then -(`im`.`quantity`) when (`im`.`move_type` = 'adjust') then `im`.`quantity` else 0 end)),0) AS `stock_qty` FROM (`products` `p` left join `inventory_moves` `im` on((`im`.`product_id` = `p`.`id`))) GROUP BY `p`.`id` ;

--
-- 限制导出的表
--

--
-- 限制表 `inventory_moves`
--
ALTER TABLE `inventory_moves`
  ADD CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `production_orders`
--
ALTER TABLE `production_orders`
  ADD CONSTRAINT `fk_po_last_changed_by` FOREIGN KEY (`last_changed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_po_sales` FOREIGN KEY (`sales_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_po_status` FOREIGN KEY (`status_id`) REFERENCES `production_statuses` (`id`);

--
-- 限制表 `production_order_items`
--
ALTER TABLE `production_order_items`
  ADD CONSTRAINT `fk_poi_order` FOREIGN KEY (`order_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE;

--
-- 限制表 `production_status_history`
--
ALTER TABLE `production_status_history`
  ADD CONSTRAINT `fk_psh_order` FOREIGN KEY (`order_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_psh_to_status` FOREIGN KEY (`to_status_id`) REFERENCES `production_statuses` (`id`),
  ADD CONSTRAINT `fk_psh_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- 限制表 `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_products_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `subcategories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- 限制表 `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_reservations_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `subcategories`
--
ALTER TABLE `subcategories`
  ADD CONSTRAINT `fk_subcategories_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

