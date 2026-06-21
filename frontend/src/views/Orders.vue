<template>
  <div class="page-container">
    <div class="page-header">
      <h2 class="page-title">订单管理</h2>
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search-form">
          <el-input v-model="searchForm.keyword" placeholder="订单号/收件人/电话" clearable style="width: 240px" />
          <el-select v-model="searchForm.status" placeholder="订单状态" clearable style="width: 140px">
            <el-option label="待审核" :value="0" />
            <el-option label="MOQ已通过" :value="10" />
            <el-option label="已生成面单" :value="20" />
            <el-option label="已发货" :value="30" />
            <el-option label="已取消" :value="40" />
          </el-select>
          <el-date-picker
            v-model="searchForm.date_range"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
          />
          <el-button type="primary" @click="loadList">
            <el-icon><Search /></el-icon> 搜索
          </el-button>
          <el-button @click="resetSearch">
            <el-icon><Refresh /></el-icon> 重置
          </el-button>
        </div>
        <div class="flex-gap">
          <el-button type="primary" @click="$router.push('/orders/create')">
            <el-icon><Plus /></el-icon> 新建订单
          </el-button>
          <el-button type="success" :disabled="selectedOrders.length === 0" @click="batchCheckMoq">
            <el-icon><CircleCheck /></el-icon> 批量校验MOQ
          </el-button>
          <el-button type="warning" :disabled="selectedOrders.length === 0" @click="batchGenerateShipping">
            <el-icon><Printer /></el-icon> 批量打单
          </el-button>
        </div>
      </div>

      <el-table
        :data="tableData"
        v-loading="loading"
        stripe
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="50" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="order_no" label="订单号" width="160" />
        <el-table-column label="收件信息" min-width="200">
          <template #default="{ row }">
            <div>{{ row.receiver_name }} {{ row.receiver_phone }}</div>
            <div style="color: #909399; font-size: 12px">{{ row.receiver_address }}</div>
          </template>
        </el-table-column>
        <el-table-column label="商品信息" min-width="200">
          <template #default="{ row }">
            <div v-for="(item, idx) in row.items" :key="idx" style="font-size: 13px">
              {{ item.sku }} x{{ item.quantity }}
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="total_quantity" label="总数量" width="80" />
        <el-table-column prop="status_text" label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="getStatusType(row.status)">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="MOQ校验" width="100">
          <template #default="{ row }">
            <el-tag v-if="row.moq_checked === 1" type="success">已通过</el-tag>
            <el-tag v-else-if="row.moq_checked === 2" type="danger">未通过</el-tag>
            <el-tag v-else type="info">未校验</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="170" />
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleCheckMoq(row)" v-if="row.moq_checked !== 1">校验MOQ</el-button>
            <el-button type="warning" link @click="handleGenerateShipping(row)" v-if="row.moq_checked === 1 && row.status < 20">生成面单</el-button>
            <el-button type="success" link @click="viewDetail(row)">详情</el-button>
            <el-button type="danger" link @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="mt-16">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.page_size"
          :page-sizes="[10, 20, 50, 100]"
          :total="pagination.total"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="loadList"
          @current-change="loadList"
        />
      </div>
    </div>

    <el-dialog v-model="detailVisible" title="订单详情" width="700px">
      <el-descriptions :column="2" border v-if="currentOrder">
        <el-descriptions-item label="订单号">{{ currentOrder.order_no }}</el-descriptions-item>
        <el-descriptions-item label="订单状态">
          <el-tag :type="getStatusType(currentOrder.status)">{{ currentOrder.status_text }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="收件人">{{ currentOrder.receiver_name }}</el-descriptions-item>
        <el-descriptions-item label="联系电话">{{ currentOrder.receiver_phone }}</el-descriptions-item>
        <el-descriptions-item label="收件地址" :span="2">{{ currentOrder.receiver_address }}</el-descriptions-item>
        <el-descriptions-item label="备注" :span="2">{{ currentOrder.remark || '-' }}</el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ currentOrder.created_at }}</el-descriptions-item>
        <el-descriptions-item label="MOQ校验">
          <el-tag v-if="currentOrder.moq_checked === 1" type="success">已通过</el-tag>
          <el-tag v-else-if="currentOrder.moq_checked === 2" type="danger">未通过</el-tag>
          <el-tag v-else type="info">未校验</el-tag>
        </el-descriptions-item>
      </el-descriptions>
      <el-divider>商品明细</el-divider>
      <el-table :data="currentOrder?.items || []" border>
        <el-table-column prop="sku" label="SKU" />
        <el-table-column prop="name" label="产品名称" />
        <el-table-column prop="quantity" label="数量" width="100" />
        <el-table-column prop="moq" label="MOQ" width="100" />
        <el-table-column label="MOQ状态" width="120">
          <template #default="{ row }">
            <el-tag v-if="row.quantity >= row.moq" type="success">满足</el-tag>
            <el-tag v-else type="danger">不满足</el-tag>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { orderApi, shippingApi } from '@/api'

const loading = ref(false)
const tableData = ref([])
const selectedOrders = ref([])
const detailVisible = ref(false)
const currentOrder = ref(null)

const searchForm = reactive({
  keyword: '',
  status: null,
  date_range: []
})

const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0
})

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

const loadList = async () => {
  loading.value = true
  try {
    const params = {
      ...searchForm,
      page: pagination.page,
      page_size: pagination.page_size
    }
    if (searchForm.date_range?.length) {
      params.start_date = searchForm.date_range[0]
      params.end_date = searchForm.date_range[1]
    }
    const res = await orderApi.list(params)
    tableData.value = res.data?.list || []
    pagination.total = res.data?.total || 0
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const resetSearch = () => {
  searchForm.keyword = ''
  searchForm.status = null
  searchForm.date_range = []
  pagination.page = 1
  loadList()
}

const handleSelectionChange = (val) => {
  selectedOrders.value = val
}

const handleCheckMoq = async (row) => {
  try {
    const res = await orderApi.checkMoq({ order_id: row.id })
    if (res.data?.passed) {
      ElMessage.success('MOQ校验通过')
    } else {
      ElMessage.warning(res.data?.message || 'MOQ校验未通过')
    }
    loadList()
  } catch (e) {
    console.error(e)
  }
}

const batchCheckMoq = async () => {
  try {
    const ids = selectedOrders.value.map(o => o.id)
    const res = await orderApi.batchCheckMoq({ order_ids: ids })
    ElMessage.success(`校验完成：通过 ${res.data?.passed || 0} 条，未通过 ${res.data?.failed || 0} 条`)
    loadList()
  } catch (e) {
    console.error(e)
  }
}

const handleGenerateShipping = async (row) => {
  try {
    const res = await shippingApi.generate(row.id)
    ElMessage.success('面单生成成功')
    loadList()
  } catch (e) {
    console.error(e)
  }
}

const batchGenerateShipping = async () => {
  try {
    const ids = selectedOrders.value.filter(o => o.moq_checked === 1 && o.status < 20).map(o => o.id)
    if (ids.length === 0) {
      ElMessage.warning('请选择已通过MOQ校验且未生成面单的订单')
      return
    }
    const res = await shippingApi.batchGenerate({ order_ids: ids })
    ElMessage.success(`成功生成 ${res.data?.success || 0} 张面单`)
    loadList()
  } catch (e) {
    console.error(e)
  }
}

const viewDetail = async (row) => {
  try {
    const res = await orderApi.detail(row.id)
    currentOrder.value = res.data
    detailVisible.value = true
  } catch (e) {
    console.error(e)
  }
}

const handleDelete = async (row) => {
  try {
    await ElMessageBox.confirm(`确定删除订单「${row.order_no}」吗？`, '提示', { type: 'warning' })
    await orderApi.delete(row.id)
    ElMessage.success('删除成功')
    loadList()
  } catch (e) {
    if (e !== 'cancel') console.error(e)
  }
}

onMounted(loadList)
</script>
