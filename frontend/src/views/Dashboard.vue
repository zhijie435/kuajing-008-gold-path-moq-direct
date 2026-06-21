<template>
  <div class="page-container">
    <div class="page-header">
      <h2 class="page-title">数据概览</h2>
    </div>

    <el-row :gutter="20" class="mb-16">
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">今日订单</div>
            <div class="stat-value">{{ stats.todayOrders || 0 }}</div>
          </div>
          <el-icon class="stat-icon primary"><List /></el-icon>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">待打单</div>
            <div class="stat-value">{{ stats.pendingShipping || 0 }}</div>
          </div>
          <el-icon class="stat-icon warning"><Clock /></el-icon>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">已打单</div>
            <div class="stat-value">{{ stats.shipped || 0 }}</div>
          </div>
          <el-icon class="stat-icon success"><CircleCheck /></el-icon>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-label">产品总数</div>
            <div class="stat-value">{{ stats.totalProducts || 0 }}</div>
          </div>
          <el-icon class="stat-icon info"><Goods /></el-icon>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="20">
      <el-col :span="12">
        <el-card class="card">
          <template #header>
            <div class="flex-between">
              <span>最近订单</span>
              <el-button type="primary" link @click="$router.push('/orders')">查看全部</el-button>
            </div>
          </template>
          <el-table :data="recentOrders" v-loading="loading" stripe>
            <el-table-column prop="order_no" label="订单号" width="160" />
            <el-table-column prop="receiver_name" label="收件人" width="100" />
            <el-table-column prop="total_quantity" label="数量" width="80" />
            <el-table-column prop="status_text" label="状态" width="100">
              <template #default="{ row }">
                <el-tag :type="getStatusType(row.status)">{{ row.status_text }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="created_at" label="创建时间" />
          </el-table>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card class="card">
          <template #header>
            <span>MOQ 预警产品</span>
          </template>
          <el-table :data="moqWarningProducts" v-loading="loading" stripe>
            <el-table-column prop="sku" label="SKU" width="140" />
            <el-table-column prop="name" label="产品名称" />
            <el-table-column prop="moq" label="起订量" width="100" />
            <el-table-column prop="stock" label="库存" width="100">
              <template #default="{ row }">
                <span :class="{ 'text-danger': row.stock < row.moq }">{{ row.stock }}</span>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { dashboardApi, orderApi, productApi } from '@/api'

const loading = ref(false)
const stats = ref({})
const recentOrders = ref([])
const moqWarningProducts = ref([])

const getStatusType = (status) => {
  const map = {
    0: 'info',
    10: 'warning',
    20: 'primary',
    30: 'success',
    40: 'danger'
  }
  return map[status] || 'info'
}

const loadData = async () => {
  loading.value = true
  try {
    const [statsRes, ordersRes, productsRes] = await Promise.all([
      dashboardApi.stats(),
      orderApi.list({ page: 1, page_size: 5 }),
      productApi.list({ moq_warning: 1 })
    ])
    stats.value = statsRes.data || {}
    recentOrders.value = ordersRes.data?.list || []
    moqWarningProducts.value = productsRes.data?.list || []
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>

<style scoped>
.stat-card {
  border: none;
  border-radius: 8px;
}
.stat-card :deep(.el-card__body) {
  padding: 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.stat-content {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.stat-label {
  color: #909399;
  font-size: 14px;
}
.stat-value {
  font-size: 32px;
  font-weight: 700;
  color: #303133;
}
.stat-icon {
  font-size: 48px;
  opacity: 0.3;
}
.stat-icon.primary { color: #409EFF; }
.stat-icon.success { color: #67C23A; }
.stat-icon.warning { color: #E6A23C; }
.stat-icon.info { color: #909399; }
.text-danger {
  color: #F56C6C;
  font-weight: 600;
}
</style>
