<!-- components/Search/AddressSearch.vue -->
<template>
  <div class="relative">
    <!-- Search Input -->
    <UInput
      v-model="query"
      :icon="loading ? 'i-heroicons-clock' : 'i-heroicons-magnifying-glass'"
      :placeholder="placeholder"
      :loading="loading"
      autocomplete="off"
      @input="onSearch"
      @focus="showSuggestions = true"
      @blur="hideSuggestions"
      @keydown="handleKeydown"
    >
      <template #trailing>
        <UButton
          v-if="query"
          icon="i-heroicons-x-mark"
          color="gray"
          variant="ghost"
          size="xs"
          @click="clearSearch"
        />
      </template>
    </UInput>

    <!-- Suggestions Dropdown -->
    <div
      v-if="showSuggestions && suggestions.length > 0"
      class="suggestions-container absolute z-50 w-full mt-1 bg-white rounded-lg shadow-lg border border-gray-200 max-h-64 overflow-y-auto"
    >
      <div
        v-for="(suggestion, index) in suggestions"
        :key="suggestion.place_id"
        class="px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors"
        :class="{
            'bg-blue-50 suggestion-selected': selectedIndex === index,
            'hover:bg-blue-100': selectedIndex === index
          }"
        @mousedown="selectSuggestion(suggestion)"
        @mouseenter="selectedIndex = index"
      >
        <div class="font-medium text-gray-900">{{ suggestion.name }}</div>
        <div class="text-sm text-gray-500 truncate">{{ suggestion.display_name }}</div>
        <div class="flex items-center gap-1 mt-1 text-xs text-gray-400">
          <UIcon name="i-heroicons-map-pin" class="w-3 h-3"/>
          <span>{{ suggestion.lat.toFixed(4) }}, {{ suggestion.lon.toFixed(4) }}</span>
        </div>
      </div>
    </div>

    <!-- Recent Searches -->
    <div
      v-if="showSuggestions && query === '' && recentSearches.length > 0"
      class="absolute z-50 w-full mt-1 bg-white rounded-lg shadow-lg border border-gray-200"
    >
      <div class="px-4 py-2 text-xs font-semibold text-gray-500 border-b">
        Recent Searches
      </div>
      <div
        v-for="search in recentSearches"
        :key="search.place_id"
        class="px-4 py-3 hover:bg-gray-50 cursor-pointer flex items-center justify-between"
        @mousedown="selectRecent(search)"
      >
        <div>
          <div class="font-medium text-gray-900">{{ search.name }}</div>
          <div class="text-sm text-gray-500">{{ search.display_name }}</div>
        </div>
        <UIcon name="i-heroicons-clock" class="w-4 h-4 text-gray-400"/>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
export interface LocationSuggestion {
  place_id: number
  osm_type: string
  osm_id: number
  lat: number
  lon: number
  name: string
  display_name: string
  class: string
  type: string
}

const props = defineProps<{
  placeholder?: string
  initialValue?: string
}>()

const emit = defineEmits<{
  'select': [location: LocationSuggestion]
  'clear': []
}>()

const query = ref(props.initialValue || '')
const suggestions = ref<LocationSuggestion[]>([])
const recentSearches = ref<LocationSuggestion[]>([])
const loading = ref(false)
const showSuggestions = ref(false)
const selectedIndex = ref(-1)

const DEBOUNCE_DELAY = 300
let searchTimeout: NodeJS.Timeout

// Load recent searches from localStorage
onMounted(() => {
  const stored = localStorage.getItem('recent_searches')
  if (stored) {
    recentSearches.value = JSON.parse(stored).slice(0, 5)
  }
})

// Debounced search
const onSearch = () => {
  clearTimeout(searchTimeout)

  if (!query.value.trim()) {
    suggestions.value = []
    selectedIndex.value = -1
    return
  }

  loading.value = true
  searchTimeout = setTimeout(async () => {
    try {
      const response = await $fetch(`http://localhost:8181/?q=${encodeURIComponent(query.value)}&limit=100`)
      suggestions.value = response
      selectedIndex.value = -1
    } catch (error) {
      console.error('Search failed:', error)
      suggestions.value = []
    } finally {
      loading.value = false
    }
  }, DEBOUNCE_DELAY)
}

// Handle suggestion selection
const selectSuggestion = (suggestion: LocationSuggestion) => {
  query.value = suggestion.name
  suggestions.value = []
  showSuggestions.value = false
  selectedIndex.value = -1

  // Add to recent searches
  addToRecent(suggestion)

  emit('select', suggestion)
}

// Handle keyboard navigation
const handleKeydown = (event: KeyboardEvent) => {
  if (!showSuggestions.value || suggestions.value.length === 0) return

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      selectedIndex.value = Math.min(selectedIndex.value + 1, suggestions.value.length - 1)
      scrollToSelected()
      break
    case 'ArrowUp':
      event.preventDefault()
      selectedIndex.value = Math.max(selectedIndex.value - 1, -1)
      scrollToSelected()
      break
    case 'Enter':
      event.preventDefault()
      if (selectedIndex.value >= 0 && selectedIndex.value < suggestions.value.length) {
        selectSuggestion(suggestions.value[selectedIndex.value])
      }
      break
    case 'Escape':
      showSuggestions.value = false
      selectedIndex.value = -1
      break
  }
}

// Scroll selected item into view
const scrollToSelected = () => {
  nextTick(() => {
    const container = document.querySelector('.suggestions-container')
    const selectedItem = document.querySelector('.suggestion-selected')
    if (container && selectedItem) {
      selectedItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    }
  })
}

// Handle recent search selection
const selectRecent = (suggestion: LocationSuggestion) => {
  query.value = suggestion.name
  emit('select', suggestion)
}

// Add to recent searches
const addToRecent = (suggestion: LocationSuggestion) => {
  recentSearches.value = [
    suggestion,
    ...recentSearches.value.filter(s => s.place_id !== suggestion.place_id)
  ].slice(0, 5)

  localStorage.setItem('recent_searches', JSON.stringify(recentSearches.value))
}

// Clear search
const clearSearch = () => {
  query.value = ''
  suggestions.value = []
  selectedIndex.value = -1
  emit('clear')
}

// Hide suggestions with delay for click to register
const hideSuggestions = () => {
  setTimeout(() => {
    showSuggestions.value = false
    selectedIndex.value = -1
  }, 200)
}

// Watch for initial value changes
watch(() => props.initialValue, (newValue) => {
  if (newValue && newValue !== query.value) {
    query.value = newValue
  }
})

// Expose query for parent components
defineExpose({
  query
})
</script>
