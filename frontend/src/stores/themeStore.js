import { defineStore } from 'pinia'

export const useThemeStore = defineStore('theme', {
  state: () => {
    let saved = localStorage.getItem('inventoryTheme')
    if (saved === 'rpg' || saved === 'BSU') saved = 'bsu'
    const allowed = ['bsu', 'dark', 'light']
    const theme = allowed.includes(saved) ? saved : 'bsu'
    return {
      current: theme,
    }
  },

  actions: {
    init() {
      document.documentElement.dataset.theme = this.current
    },

    setTheme(themeName) {
      this.current = themeName
      localStorage.setItem('inventoryTheme', themeName)
      document.documentElement.dataset.theme = themeName
    },

    cycleTheme() {
      const themes = ['bsu', 'dark', 'light']
      const nextIndex = (themes.indexOf(this.current) + 1) % themes.length
      this.setTheme(themes[nextIndex])
    },
  },
})
