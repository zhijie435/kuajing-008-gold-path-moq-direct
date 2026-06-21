import axios from 'axios'
import { ElMessage } from 'element-plus'

const request = axios.create({
  baseURL: '/api',
  timeout: 15000
})

request.interceptors.request.use(
  (config) => {
    config.headers['Content-Type'] = 'application/json'
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

request.interceptors.response.use(
  (response) => {
    const res = response.data
    if (res.code !== 0 && res.code !== 200) {
      ElMessage.error(res.message || '请求失败')
      return Promise.reject(new Error(res.message || '请求失败'))
    }
    return res
  },
  (error) => {
    ElMessage.error(error.message || '网络错误')
    return Promise.reject(error)
  }
)

export const productApi = {
  list: (params) => request.get('/products', { params }),
  detail: (id) => request.get(`/products/${id}`),
  create: (data) => request.post('/products', data),
  update: (id, data) => request.put(`/products/${id}`, data),
  delete: (id) => request.delete(`/products/${id}`)
}

export const orderApi = {
  list: (params) => request.get('/orders', { params }),
  detail: (id) => request.get(`/orders/${id}`),
  create: (data) => request.post('/orders', data),
  update: (id, data) => request.put(`/orders/${id}`, data),
  delete: (id) => request.delete(`/orders/${id}`),
  checkMoq: (data) => request.post('/orders/check-moq', data),
  batchCheckMoq: (data) => request.post('/orders/batch-check-moq', data)
}

export const shippingApi = {
  list: (params) => request.get('/shipping', { params }),
  generate: (orderId) => request.post(`/shipping/generate/${orderId}`),
  batchGenerate: (data) => request.post('/shipping/batch-generate', data),
  print: (shippingId) => request.post(`/shipping/print/${shippingId}`),
  batchPrint: (data) => request.post('/shipping/batch-print', data)
}

export const dashboardApi = {
  stats: () => request.get('/dashboard/stats')
}

export default request
