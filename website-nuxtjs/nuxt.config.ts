// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: {enabled: false},

  // Runtime configuration
  runtimeConfig: {
    // Private keys (only available on server-side)
    apiSecret: process.env.API_SECRET,

    // Public keys (exposed to client-side)
    public: {
      baseUrl: process.env.BASE_URL || 'http://127.0.0.1:8000',
      apiBase: process.env.API_BASE_URL || 'http://127.0.0.1:8000/api',
      appName: process.env.APP_NAME || 'Man With Van',
      version: '4.0.0',
    }
  },

  modules: [
    '@nuxt/scripts',
    '@nuxt/ui',
  ],
  ssr: false,
  css: ['~/assets/css/main.css'],
  content: {
    // documentDriven: false, // you're manually controlling routing
    experimental: {nativeSqlite: true},
  },
  ui: {
    colorMode: true,
    icons: ['heroicons', 'simple-icons'],
  },
})
