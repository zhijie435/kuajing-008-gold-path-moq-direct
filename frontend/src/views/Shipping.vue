<template>
  <div class="page-container">
    <div class="page-header">
      <h2 class="page-title">打单中心</h2>
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search-form">
          <el-input v-model="searchForm.keyword" placeholder="运单号/订单号/收件人" clearable style="width: 240px" />
          <el-select v-model="searchForm.status" placeholder="面单状态" clearable style="width: 140px">
            <el-option label="待打印" :value="0" />
            <el-option label="已打印" :value="1" />
            <el-option label="已发货" :value="2" />
            <el-option label="已作废" :value="9" />
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
          <el-button type="primary" :disabled="selectedList.length === 0" @click="batchPrint">
            <el-icon><Printer /></el-icon> 批量打印
          </el-button>
          <el-button type="success" :disabled="selectedList.length === 0" @click="batchMarkShipped">
            <el-icon><Van /></el-icon> 标记发货
          </el-button>
          <el-button type="primary" plain @click="exportLabels">
            <el-icon><Download /></el-icon> 导出面单
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
        <el-table-column prop="shipping_no" label="运单号" width="180">
          <template #default="{ row }">
            <span style="font-family: monospace; font-weight: 600">{{ row.shipping_no }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="order_no" label="关联订单" width="160" />
        <el-table-column label="收件人信息" min-width="220">
          <template #default="{ row }">
            <div>{{ row.receiver_name }} {{ row.receiver_phone }}</div>
            <div style="color: #909399; font-size: 12px">{{ row.receiver_address }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="carrier" label="快递公司" width="100" />
        <el-table-column label="商品信息" min-width="180">
          <template #default="{ row }">
            <div v-for="(item, idx) in row.items" :key="idx" style="font-size: 13px">
              {{ item.sku }} x{{ item.quantity }}
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="total_weight" label="总重量(g)" width="110" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="getStatusType(row.status)">{{ getStatusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="生成时间" width="170" />
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link @click="viewLabel(row)">
              <el-icon><View /></el-icon> 预览
            </el-button>
            <el-button type="success" link @click="printLabel(row)" v-if="row.status < 2">
              <el-icon><Printer /></el-icon> 打印
            </el-button>
            <el-button type="warning" link @click="markShipped(row)" v-if="row.status < 2">
              <el-icon><Van /></el-icon> 发货
            </el-button>
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

    <el-dialog v-model="previewVisible" title="面单预览" width="500px">
      <div v-if="currentLabel" class="shipping-label" id="shippingLabel">
        <div class="label-header">
          <div class="carrier-name">{{ currentLabel.carrier }}</div>
          <div class="shipping-no">{{ currentLabel.shipping_no }}</div>
        </div>
        <div class="label-divider"></div>
        <div class="label-section">
          <div class="label-row">
            <div class="label-col">
              <div class="label-label">寄件方</div>
              <div class="label-value">
                <div><b>MOQ直发仓</b></div>
                <div>13800138000</div>
                <div>广东省深圳市南山区科技园北区</div>
              </div>
            </div>
            <div class="label-arrow">→</div>
            <div class="label-col">
              <div class="label-label">收件方</div>
              <div class="label-value">
                <div><b>{{ currentLabel.receiver_name }}</b></div>
                <div>{{ currentLabel.receiver_phone }}</div>
                <div>{{ currentLabel.receiver_address }}</div>
              </div>
            </div>
          </div>
        </div>
        <div class="label-divider"></div>
        <div class="label-section">
          <div class="label-label">商品明细</div>
          <div class="label-items">
            <div v-for="(item, idx) in currentLabel.items" :key="idx" class="label-item">
              <span>{{ item.sku }} {{ item.name }}</span>
              <span>x{{ item.quantity }}</span>
            </div>
          </div>
        </div>
        <div class="label-divider"></div>
        <div class="label-footer">
          <div class="label-info">
            <span>总件数: <b>{{ currentLabel.items?.length || 0 }}</b></span>
            <span>总重量: <b>{{ currentLabel.total_weight }}g</b></span>
          </div>
          <div class="label-barcode">
            <svg width="200" height="50">
              <rect x="0" y="10" width="2" height="30" fill="#000" />
              <rect x="5" y="10" width="1" height="30" fill="#000" />
              <rect x="10" y="10" width="3" height="30" fill="#000" />
              <rect x="18" y="10" width="1" height="30" fill="#000" />
              <rect x="23" y="10" width="2" height="30" fill="#000" />
              <rect x="30" y="10" width="1" height="30" fill="#000" />
              <rect x="36" y="10" width="3" height="30" fill="#000" />
              <rect x="44" y="10" width="1" height="30" fill="#000" />
              <rect x="49" y="10" width="2" height="30" fill="#000" />
              <rect x="56" y="10" width="1" height="30" fill="#000" />
              <rect x="62" y="10" width="2" height="30" fill="#000" />
              <rect x="69" y="10" width="1" height="30" fill="#000" />
              <rect x="75" y="10" width="3" height="30" fill="#000" />
              <rect x="83" y="10" width="1" height="30" fill="#000" />
              <rect x="88" y="10" width="2" height="30" fill="#000" />
              <rect x="95" y="10" width="1" height="30" fill="#000" />
              <rect x="101" y="10" width="2" height="30" fill="#000" />
              <rect x="108" y="10" width="1" height="30" fill="#000" />
              <rect x="114" y="10" width="3" height="30" fill="#000" />
              <rect x="122" y="10" width="1" height="30" fill="#000" />
              <rect x="127" y="10" width="2" height="30" fill="#000" />
              <rect x="134" y="10" width="1" height="30" fill="#000" />
              <rect x="140" y="10" width="2" height="30" fill="#000" />
              <rect x="147" y="10" width="1" height="30" fill="#000" />
              <rect x="153" y="10" width="3" height="30" fill="#000" />
              <rect x="161" y="10" width="1" height="30" fill="#000" />
              <rect x="166" y="10" width="2" height="30" fill="#000" />
              <rect x="173" y="10" width="1" height="30" fill="#000" />
              <rect x="179" y="10" width="2" height="30" fill="#000" />
              <rect x="186" y="10" width="1" height="30" fill="#000" />
              <rect x="192" y="10" width="3" height="30" fill="#000" />
              <text x="100" y="48" text-anchor="middle" font-size="10" font-family="monospace">{{ currentLabel.shipping_no }}</text>
            </svg>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button @click="previewVisible = false">关闭</el-button>
        <el-button type="primary" @click="printCurrentLabel">
          <el-icon><Printer /></el-icon> 打印面单
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { shippingApi } from '@/api'

const loading = ref(false)
const tableData = ref([])
const selectedList = ref([])
const previewVisible = ref(false)
const currentLabel = ref(null)

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
    0: 'warning',
    1: 'primary',
    2: 'success',
    9: 'danger'
  }
  return map[status] || 'info'
}

const getStatusText = (status) => {
  const map = {
    0: '待打印',
    1: '已打印',
    2: '已发货',
    9: '已作废'
  }
  return map[status] || '未知'
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
    const res = await shippingApi.list(params)
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
  selectedList.value = val
}

const viewLabel = (row) => {
  currentLabel.value = row
  previewVisible.value = true
}

const printLabel = async (row) => {
  try {
    await shippingApi.print(row.id)
    ElMessage.success('已标记为打印')
    loadList()
  } catch (e) {
    console.error(e)
  }
}

const printCurrentLabel = async () => {
  if (!currentLabel.value) return
  try {
    await shippingApi.print(currentLabel.value.id)
    ElMessage.success('打印成功')
    previewVisible.value = false
    loadList()
  } catch (e) {
    console.error(e)
  }
}

const markShipped = async (row) => {
  try {
    await ElMessageBox.confirm(`确认运单 ${row.shipping_no} 已发货？`, '提示', { type: 'warning' })
    await shippingApi.ship(row.id)
    ElMessage.success('已标记发货，订单状态已同步更新')
    loadList()
  } catch (e) {
    if (e !== 'cancel') console.error(e)
  }
}

const batchPrint = async () => {
  try {
    const ids = selectedList.value.filter(s => s.status < 2).map(s => s.id)
    if (ids.length === 0) {
      ElMessage.warning('请选择待打印的面单')
      return
    }
    await shippingApi.batchPrint({ shipping_ids: ids })
    ElMessage.success(`已标记 ${ids.length} 张面单为打印状态`)
    loadList()
  } catch (e) {
    console.error(e)
  }
}

const batchMarkShipped = async () => {
  try {
    const ids = selectedList.value.filter(s => s.status < 2).map(s => s.id)
    if (ids.length === 0) {
      ElMessage.warning('请选择待发货的面单')
      return
    }
    await ElMessageBox.confirm(`确认将选中的 ${ids.length} 张面单标记为发货？`, '提示', { type: 'warning' })
    const res = await shippingApi.batchShip({ shipping_ids: ids })
    ElMessage.success(`批量标记发货成功，已同步更新 ${res.data?.count || 0} 个订单状态`)
    loadList()
  } catch (e) {
    if (e !== 'cancel') console.error(e)
  }
}

const exportLabels = () => {
  ElMessage.info('面单导出功能开发中')
}

onMounted(loadList)
</script>

<style scoped>
.shipping-label {
  border: 2px solid #303133;
  border-radius: 4px;
  padding: 16px;
  font-family: -apple-system, BlinkMacSystemFont, monospace;
}
.label-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.carrier-name {
  font-size: 20px;
  font-weight: 700;
  color: #409EFF;
}
.shipping-no {
  font-size: 18px;
  font-weight: 700;
  font-family: monospace;
}
.label-divider {
  border-top: 1px dashed #909399;
  margin: 12px 0;
}
.label-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.label-col {
  flex: 1;
}
.label-label {
  font-size: 12px;
  color: #909399;
  margin-bottom: 4px;
}
.label-value {
  font-size: 13px;
  line-height: 1.6;
}
.label-arrow {
  font-size: 24px;
  color: #409EFF;
  padding-top: 12px;
}
.label-items {
  margin-top: 8px;
}
.label-item {
  display: flex;
  justify-content: space-between;
  padding: 4px 0;
  font-size: 13px;
  border-bottom: 1px solid #f0f0f0;
}
.label-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.label-info {
  display: flex;
  gap: 24px;
  font-size: 13px;
}
</style>
