<!-- components/Map/RoutingMap.vue -->
<template>
  <div class="relative w-full h-full">
    <!-- Map Container -->
    <div ref="mapContainer" class="absolute inset-0 h-full w-full" />

    <!-- Loading State -->
    <div v-if="loading" class="absolute inset-0 bg-gray-100/50 flex items-center justify-center">
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
    </div>

    <!-- Map Controls -->
    <div class="absolute top-4 right-4 z-10 space-y-2">
      <UButton
        icon="i-heroicons-plus"
        color="neutral"
        size="sm"
        aria-label="Zoom in"
        @click="zoomIn"
      />
      <UButton
        icon="i-heroicons-minus"
        color="neutral"
        size="sm"
        aria-label="Zoom out"
        @click="zoomOut"
      />
      <UButton
        icon="i-heroicons-arrows-pointing-out"
        color="neutral"
        size="sm"
        aria-label="Fit to London"
        @click="fitToLondon"
      />
    </div>

    <!-- Slot for markers/controls -->
    <slot />
  </div>
</template>

<script setup lang="ts">
import maplibregl from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'

const props = defineProps<{
  center?: [number, number]
  zoom?: number
}>()

const emit = defineEmits<{
  'map:loaded': [map: maplibregl.Map]
  'map:click': [coordinates: [number, number]]
}>()

const mapContainer = ref<HTMLElement>()
const map = ref<maplibregl.Map>()
const loading = ref(true)

const defaultCenter: [number, number] = [-0.1278, 51.5074] // London center
const defaultZoom = 9

// Initialize map
onMounted(() => {
  if (!mapContainer.value) return

  map.value = new maplibregl.Map({
    container: mapContainer.value,
    style: 'https://tiles.openfreemap.org/styles/liberty',   // Liberty style
    // style: 'https://tiles.openfreemap.org/styles/bright',    // Bright (light) style
    // style: 'https://tiles.openfreemap.org/styles/positron',  // Positron (OSM light) style
    // style: 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json', // Free OSM style | ⚠️ Note: Carto is sunsetting some services, but this still works as of 2025.
    // style: 'https://demotiles.maplibre.org/style.json', // Free OSM style
    center: props.center || defaultCenter,
    zoom: props.zoom || defaultZoom,
    attributionControl: false
  })

  // Add controls
  map.value.addControl(new maplibregl.NavigationControl(), 'top-left')
  map.value.addControl(new maplibregl.AttributionControl({
    compact: true
  }), 'bottom-right')

  // Wait for map to load
  map.value.on('load', () => {
    loading.value = false
    emit('map:loaded', map.value!)
  })

  // Handle clicks
  map.value.on('click', (e) => {
    emit('map:click', [e.lngLat.lng, e.lngLat.lat])
  })
})

// Cleanup on unmount
onUnmounted(() => {
  if (map.value) {
    map.value.remove()
  }
})

// Map controls
const zoomIn = () => map.value?.zoomIn()
const zoomOut = () => map.value?.zoomOut()

const fitToLondon = () => {
  map.value?.fitBounds([
    [-0.51, 51.28],  // SW
    [0.33, 51.72]    // NE
  ], {
    padding: 50,
    duration: 1000
  })
}

// Expose map instance
defineExpose({
  map,
  zoomIn,
  zoomOut,
  fitToLondon
})
</script>

<style scoped>
:deep(.maplibregl-ctrl) {
  margin: 0 !important;
}

:deep(.maplibregl-ctrl-group) {
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

:deep(.maplibregl-canvas) {
  outline: none;
}
</style>
