import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import permission from './directives/permission'
import './style.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// 按钮级权限指令：v-permission="'order.ship'"
app.directive('permission', permission)

app.mount('#app')
