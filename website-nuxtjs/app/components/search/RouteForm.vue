<!-- components/Search/RouteForm.vue -->
<template>
  <div class="space-y-4 p-4 bg-white rounded-lg shadow-lg">
    <div class="space-y-3">
      <!-- Pickup Address -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">
          Pickup Location
        </label>
        <AddressSearch
          ref="pickupSearch"
          placeholder="Enter pickup address..."
          :initial-value="pickupLocation?.name"
          @select="onPickupSelect"
          @clear="clearPickup"
        />
      </div>

      <!-- Swap Button -->
      <div class="flex justify-center">
        <UButton
          icon="i-heroicons-arrow-up-down"
          color="gray"
          variant="ghost"
          size="sm"
          :disabled="!pickupLocation || !dropoffLocation"
          @click="swapLocations"
        />
      </div>

      <!-- Dropoff Address -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">
          Delivery Location
        </label>
        <AddressSearch
          ref="dropoffSearch"
          placeholder="Enter delivery address..."
          :initial-value="dropoffLocation?.name"
          @select="onDropoffSelect"
          @clear="clearDropoff"
        />
      </div>
    </div>

    <!-- Route Summary -->
    <RouteSummary
      v-if="routeData"
      :route="routeData"
      class="mt-4"
    />

    <!-- Calculate Button -->
    <UButton
      block
      :disabled="!canCalculate"
      :loading="calculating"
      @click="calculateRoute"
    >
      {{ routeData ? 'Recalculate Route' : 'Calculate Route' }}
    </UButton>

    <!-- Map Click Hint -->
    <div v-if="!pickupLocation || !dropoffLocation" class="text-sm text-gray-500 text-center">
      <UIcon name="i-heroicons-light-bulb" class="w-4 h-4 inline mr-1" />
      Tip: You can also click on the map to set locations
    </div>
  </div>
</template>

<script setup lang="ts">
import type { LocationSuggestion } from './AddressSearch.vue'
import AddressSearch from "~/components/search/AddressSearch.vue"
import RouteSummary from "~/components/ui/RouteSummary.vue"
import maplibregl from 'maplibre-gl'

// Declare global window type for markers storage
declare global {
  interface Window {
    _markers?: Record<string, any>
  }
}

interface RouteData {
  distance: number
  duration: number
  geometry: any
  summary: {
    distance_km: number
    duration_min: number
    text: string
  }
}

const props = defineProps<{
  map?: any // MapLibre instance
}>()

const emit = defineEmits<{
  'route:calculated': [route: RouteData]
  'locations:changed': [locations: { pickup: LocationSuggestion | null; dropoff: LocationSuggestion | null }]
}>()

const pickupSearch = ref()
const dropoffSearch = ref()

const pickupLocation = ref<LocationSuggestion | null>(null)
const dropoffLocation = ref<LocationSuggestion | null>(null)
const routeData = ref<RouteData | null>(null)
const calculating = ref(false)

// Computed
const canCalculate = computed(() => {
  return pickupLocation.value && dropoffLocation.value
})

// Handle location selections
const onPickupSelect = (location: LocationSuggestion) => {
  pickupLocation.value = location
  emitLocationChange()
  addMarker('pickup', location, 'A', 'green')
}

const onDropoffSelect = (location: LocationSuggestion) => {
  dropoffLocation.value = location
  emitLocationChange()
  addMarker('dropoff', location, 'B', 'red')
}

// Clear locations
const clearPickup = () => {
  pickupLocation.value = null
  removeMarker('pickup')
  emitLocationChange()
}

const clearDropoff = () => {
  dropoffLocation.value = null
  removeMarker('dropoff')
  emitLocationChange()
}

// Swap locations
const swapLocations = () => {
  if (!pickupLocation.value || !dropoffLocation.value) return

  const temp = pickupLocation.value
  pickupLocation.value = dropoffLocation.value
  dropoffLocation.value = temp

  // Swap markers
  addMarker('pickup', pickupLocation.value, 'A', 'green')
  addMarker('dropoff', dropoffLocation.value, 'B', 'red')

  emitLocationChange()

  // Automatically recalculate route
  calculateRoute()
}

// Calculate route using OSRM
const calculateRoute = async () => {
  if (!pickupLocation.value || !dropoffLocation.value || !props.map) return

  calculating.value = true

  try {
    const response = await $fetch('http://localhost:5003/route/v1/driving/' +
      `${pickupLocation.value.lon},${pickupLocation.value.lat};` +
      `${dropoffLocation.value.lon},${dropoffLocation.value.lat}`,
      {
        params: {
          overview: 'full',
          geometries: 'geojson',
          steps: true
        }
      }
    )

    if (response.code === 'Ok' && response.routes.length > 0) {
      const route = response.routes[0]
      routeData.value = {
        distance: route.distance,
        duration: route.duration,
        geometry: route.geometry,
        summary: {
          distance_km: route.distance / 1000,
          duration_min: Math.ceil(route.duration / 60),
          text: `${(route.distance / 1000).toFixed(1)} km • ${Math.ceil(route.duration / 60)} min`
        }
      }

      emit('route:calculated', routeData.value)

      // Draw route on map
      await nextTick()
      drawRoute(route.geometry)

      // Fit map bounds to show entire route
      fitRouteBounds()
    }
  } catch (error) {
    console.error('Route calculation failed:', error)
    alert('Failed to calculate route. Please try again.')
  } finally {
    calculating.value = false
  }
}

// Map marker functions
const addMarker = (type: string, location: LocationSuggestion, label: string, color: string) => {
  if (!props.map) return

  // Remove existing marker
  removeMarker(type)

  // Create custom marker element
  const el = document.createElement('div')
  el.className = `custom-marker marker-${type}`
  el.style.cssText = `
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background-color: ${color === 'green' ? '#10b981' : '#ef4444'};
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    cursor: pointer;
  `
  el.textContent = label

  // Add to map
  const marker = new maplibregl.Marker({ element: el })
    .setLngLat([location.lon, location.lat])
    .addTo(props.map)

  // Store marker reference for removal
  if (!window._markers) window._markers = {}
  window._markers[type] = marker
}

const removeMarker = (type: string) => {
  if (window._markers && window._markers[type]) {
    window._markers[type].remove()
    delete window._markers[type]
  }
}

// Draw route line
const drawRoute = (geometry: any) => {
  if (!props.map) return

  // Remove existing route
  if (props.map.getLayer('route-line')) {
    props.map.removeLayer('route-line')
  }
  if (props.map.getSource('route')) {
    props.map.removeSource('route')
  }

  // Add new route
  props.map.addSource('route', {
    type: 'geojson',
    data: {
      type: 'Feature',
      properties: {},
      geometry: geometry
    }
  })

  props.map.addLayer({
    id: 'route-line',
    type: 'line',
    source: 'route',
    layout: {
      'line-join': 'round',
      'line-cap': 'round'
    },
    paint: {
      'line-color': '#4285F4',
      'line-width': 4,
      'line-opacity': 0.7
    }
  })
}

// Fit map bounds to show entire route
const fitRouteBounds = () => {
  if (!props.map || !pickupLocation.value || !dropoffLocation.value) return

  const bounds = new maplibregl.LngLatBounds()
  bounds.extend([pickupLocation.value.lon, pickupLocation.value.lat])
  bounds.extend([dropoffLocation.value.lon, dropoffLocation.value.lat])

  props.map.fitBounds(bounds, {
    padding: 100,
    duration: 1000
  })
}

// Emit location changes
const emitLocationChange = () => {
  emit('locations:changed', {
    pickup: pickupLocation.value,
    dropoff: dropoffLocation.value
  })
}

// Expose methods
defineExpose({
  setPickupLocation: (location: LocationSuggestion) => onPickupSelect(location),
  setDropoffLocation: (location: LocationSuggestion) => onDropoffSelect(location),
  calculateRoute
})
</script>
