-- MOQ直发打单系统 数据库初始化脚本
-- MySQL 5.7+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `moq_departments`;
CREATE TABLE `moq_departments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '部门名称',
  `code` varchar(32) NOT NULL DEFAULT '' COMMENT '部门编码',
  `parent_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '父部门ID',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:1正常,0禁用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='部门表';

DROP TABLE IF EXISTS `moq_users`;
CREATE TABLE `moq_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '' COMMENT '用户名',
  `password` varchar(128) NOT NULL DEFAULT '' COMMENT '密码',
  `real_name` varchar(64) NOT NULL DEFAULT '' COMMENT '真实姓名',
  `role` varchar(32) NOT NULL DEFAULT 'viewer' COMMENT '角色:admin/operator/viewer',
  `department_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
  `email` varchar(128) NOT NULL DEFAULT '' COMMENT '邮箱',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '电话',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:1正常,0禁用',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_department_id` (`department_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

DROP TABLE IF EXISTS `moq_products`;
CREATE TABLE `moq_products` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sku` varchar(64) NOT NULL DEFAULT '' COMMENT 'SKU编码',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '产品名称',
  `category` varchar(64) NOT NULL DEFAULT '' COMMENT '分类',
  `moq` int(11) NOT NULL DEFAULT 1 COMMENT '最小起订量',
  `unit` varchar(16) NOT NULL DEFAULT '件' COMMENT '单位',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
  `stock` int(11) NOT NULL DEFAULT 0 COMMENT '库存',
  `weight` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '重量(g)',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:1正常,0下架',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sku` (`sku`),
  KEY `idx_category` (`category`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品表';

DROP TABLE IF EXISTS `moq_orders`;
CREATE TABLE `moq_orders` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `receiver_name` varchar(64) NOT NULL DEFAULT '' COMMENT '收件人',
  `receiver_phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `receiver_address` varchar(500) NOT NULL DEFAULT '' COMMENT '收件地址',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '订单备注',
  `total_quantity` int(11) NOT NULL DEFAULT 0 COMMENT '总数量',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '总金额',
  `total_weight` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '总重量(g)',
  `status` tinyint(2) NOT NULL DEFAULT 0 COMMENT '状态:0待审核,10MOQ通过,15已审核,20已生成面单,30已发货,40已取消',
  `moq_checked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'MOQ校验:0未校验,1通过,2未通过',
  `moq_fail_reason` varchar(500) NOT NULL DEFAULT '' COMMENT 'MOQ校验失败原因',
  `review_remark` varchar(500) NOT NULL DEFAULT '' COMMENT '审核备注',
  `reviewed_at` datetime DEFAULT NULL COMMENT '审核时间',
  `shipping_id` int(11) unsigned DEFAULT NULL COMMENT '关联面单ID',
  `created_by` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建人ID',
  `department_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_status` (`status`),
  KEY `idx_moq_checked` (`moq_checked`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_receiver_phone` (`receiver_phone`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表';

DROP TABLE IF EXISTS `moq_order_items`;
CREATE TABLE `moq_order_items` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '订单ID',
  `product_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '产品ID',
  `sku` varchar(64) NOT NULL DEFAULT '' COMMENT 'SKU编码',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '产品名称',
  `moq` int(11) NOT NULL DEFAULT 0 COMMENT '下单时MOQ',
  `unit` varchar(16) NOT NULL DEFAULT '' COMMENT '单位',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '数量',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '下单时单价',
  `weight` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '下单时重量',
  `moq_passed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '单项MOQ是否通过:1是0否',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单明细表';

DROP TABLE IF EXISTS `moq_shipping_labels`;
CREATE TABLE `moq_shipping_labels` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `shipping_no` varchar(32) NOT NULL DEFAULT '' COMMENT '运单号',
  `order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '订单ID',
  `order_no` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `carrier` varchar(32) NOT NULL DEFAULT '顺丰速运' COMMENT '快递公司',
  `receiver_name` varchar(64) NOT NULL DEFAULT '' COMMENT '收件人',
  `receiver_phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `receiver_address` varchar(500) NOT NULL DEFAULT '' COMMENT '收件地址',
  `sender_name` varchar(64) NOT NULL DEFAULT 'MOQ直发仓' COMMENT '寄件人',
  `sender_phone` varchar(20) NOT NULL DEFAULT '13800138000' COMMENT '寄件电话',
  `sender_address` varchar(500) NOT NULL DEFAULT '广东省深圳市南山区科技园北区' COMMENT '寄件地址',
  `total_weight` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '总重量(g)',
  `status` tinyint(2) NOT NULL DEFAULT 0 COMMENT '状态:0待打印,1已打印,2已发货,9已作废',
  `printed_at` datetime DEFAULT NULL COMMENT '打印时间',
  `shipped_at` datetime DEFAULT NULL COMMENT '发货时间',
  `created_by` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '创建人ID',
  `department_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '部门ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shipping_no` (`shipping_no`),
  UNIQUE KEY `uk_order_id` (`order_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='面单表';

DROP TABLE IF EXISTS `moq_shipping_items`;
CREATE TABLE `moq_shipping_items` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `shipping_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '面单ID',
  `sku` varchar(64) NOT NULL DEFAULT '' COMMENT 'SKU编码',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '产品名称',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '数量',
  `unit` varchar(16) NOT NULL DEFAULT '' COMMENT '单位',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_shipping_id` (`shipping_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='面单明细表';

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO `moq_products` (`sku`, `name`, `category`, `moq`, `unit`, `price`, `stock`, `weight`, `remark`) VALUES
('SKU001', '无线蓝牙耳机', '数码配件', 50, '台', 89.00, 500, 45.00, '标准款蓝牙耳机'),
('SKU002', '手机保护壳', '数码配件', 100, '个', 15.00, 2000, 30.00, '通用透明手机壳'),
('SKU003', 'USB-C数据线', '数码配件', 200, '条', 12.00, 3000, 25.00, '1米快充数据线'),
('SKU004', '便携式充电宝', '数码配件', 30, '台', 128.00, 200, 180.00, '10000mAh移动电源'),
('SKU005', '智能手环', '智能穿戴', 20, '台', 199.00, 150, 35.00, '健康监测手环'),
('SKU006', '蓝牙音箱', '数码配件', 40, '台', 158.00, 300, 250.00, '迷你便携音箱'),
('SKU007', '无线充电器', '数码配件', 80, '台', 68.00, 600, 55.00, '15W无线快充'),
('SKU008', '鼠标垫', '办公周边', 500, '个', 5.00, 5000, 20.00, '防滑橡胶鼠标垫'),
('SKU009', '笔记本支架', '办公周边', 60, '个', 58.00, 400, 320.00, '铝合金散热支架'),
('SKU010', '桌面收纳盒', '办公周边', 150, '个', 28.00, 1200, 150.00, '多格分类收纳盒');

INSERT INTO `moq_departments` (`name`, `code`, `parent_id`) VALUES
('总公司', 'HQ', 0),
('销售部', 'SALES', 1),
('运营部', 'OPS', 1),
('仓储部', 'WH', 1);

INSERT INTO `moq_users` (`username`, `password`, `real_name`, `role`, `department_id`, `email`, `phone`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '系统管理员', 'admin', 1, 'admin@example.com', '13800000001'),
('operator1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '运营专员', 'operator', 3, 'operator1@example.com', '13800000002'),
('operator2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '仓储专员', 'operator', 4, 'operator2@example.com', '13800000003'),
('viewer1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '销售查看员', 'viewer', 2, 'viewer1@example.com', '13800000004');
