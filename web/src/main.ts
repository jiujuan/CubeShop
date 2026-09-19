import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { ApiBusinessError } from './api/request'
import { useAuthStore } from './stores/auth'
import { useSiteStore } from './stores/site'
import './style.css'

const pinia = createPinia()
createApp(App).use(pinia).use(router).mount('#app')

/**
 * 站点品牌信息（名称 / 大小 logo）。
 * store 初始化时已从 localStorage 同步水合，这里只做一次回源刷新，
 * 不阻塞挂载——刷新页面时顶栏立即是上次的品牌，回源后再对齐后台最新配置。
 */
useSiteStore(pinia).load()

/**
 * 回填登录用户信息。
 * token 持久化在 localStorage，但 `user` 不持久化，且 `fetchUser()` 原先只在登录/注册成功后调用，
 * 导致「刷新页面 / 直接打开带 token 的链接」时 store.user 为 null —— 顶栏「Hi，xxx」只剩「Hi，」。
 * 仅在「有 token 且尚无 user」时请求一次；仅 401（token 失效）清理本地登录态，网络异常保留 token 待重试。
 */
const auth = useAuthStore(pinia)
if (auth.token && !auth.user) {
  auth.fetchUser().catch((e: unknown) => {
    if (e instanceof ApiBusinessError && e.status === 401) auth.clear()
  })
}
