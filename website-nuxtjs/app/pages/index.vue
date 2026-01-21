<!-- pages/index.vue -->
<template>
  <div class="bg-gray-50 min-h-screen">
    <main class="container mx-auto px-4 py-6">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Route Form -->
        <div class="lg:col-span-1 space-y-6">
          <SearchRouteForm
            ref="routeFormRef"
            :map="mapInstance"
            @route:calculated="onRouteCalculated"
            @locations:changed="onLocationsChanged"
          />
        </div>

        <!-- Right Column: Map -->
        <div class="lg:col-span-2">
          <div class="bg-white rounded-lg shadow-lg overflow-hidden h-[600px]">
            <RoutingMap
              ref="routingMapRef"
              @map:loaded="onMapLoaded"
              @map:click="onMapClick"
            >
              <!-- Map overlays can go here -->
            </RoutingMap>
          </div>

          <!-- Map Legend -->
          <div class="mt-4 flex items-center justify-center space-x-6 text-sm text-gray-600">
            <div class="flex items-center">
              <div class="w-3 h-3 rounded-full bg-green-500 mr-2"></div>
              <span>Pickup (A)</span>
            </div>
            <div class="flex items-center">
              <div class="w-3 h-3 rounded-full bg-red-500 mr-2"></div>
              <span>Delivery (B)</span>
            </div>
            <div class="flex items-center">
              <div class="w-6 h-1 bg-blue-500 mr-2"></div>
              <span>Route</span>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import type { LocationSuggestion } from '~/components/search/AddressSearch.vue'
import RoutingMap from "~/components/map/RoutingMap.vue"
import SearchRouteForm from "~/components/search/RouteForm.vue"

// Refs
const routingMapRef = ref()
const routeFormRef = ref()
const mapInstance = ref()

// Handle map loaded
const onMapLoaded = (map: any) => {
  mapInstance.value = map
  console.log('Map loaded successfully')
}

// Handle map clicks
const onMapClick = async (coordinates: [number, number]) => {
  try {
    // Reverse geocode the clicked point
    const response = await $fetch(
      `http://localhost:8181/?lat=${coordinates[1]}&lon=${coordinates[0]}`
    )

    if (response && response.lat && response.lon) {
      const location: LocationSuggestion = {
        place_id: Date.now(), // Temporary ID
        osm_type: 'node',
        osm_id: Date.now(),
        lat: parseFloat(response.lat),
        lon: parseFloat(response.lon),
        name: response.display_name || 'Selected Location',
        display_name: response.display_name || 'Selected point on map',
        class: 'place',
        type: 'selected'
      }

      // Ask user if this is pickup or dropoff
      const isPickup = confirm('Use this location as pickup? (Cancel for delivery)')

      if (isPickup) {
        routeFormRef.value?.setPickupLocation(location)
      } else {
        routeFormRef.value?.setDropoffLocation(location)
      }
    }
  } catch (error) {
    console.error('Reverse geocoding failed:', error)
  }
}

// Handle route calculated
const onRouteCalculated = (route: any) => {
  console.log('Route calculated:', route)
}

// Handle locations changed
const onLocationsChanged = (locations: any) => {
  console.log('Locations updated:', locations)
}
</script>

<style>
.custom-marker {
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.1);
  }
  100% {
    transform: scale(1);
  }
}
</style>
