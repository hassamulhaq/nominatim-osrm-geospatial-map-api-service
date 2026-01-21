<!-- components/UI/RouteSummary.vue -->
<template>
  <div class="bg-blue-50 border border-blue-100 rounded-lg p-4">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-lg font-semibold text-blue-900">
          {{ route.summary.text }}
        </div>
        <div class="text-sm text-blue-700 mt-1">
          {{ formatDistance(route.distance) }} • {{ formatDuration(route.duration) }}
        </div>
      </div>

      <div class="flex items-center space-x-2">
        <!-- Distance -->
        <div class="text-center">
          <div class="text-2xl font-bold text-blue-900">
            {{ (route.distance / 1000).toFixed(1) }}
          </div>
          <div class="text-xs text-blue-700">km</div>
        </div>

        <div class="h-8 w-px bg-blue-200"></div>

        <!-- Duration -->
        <div class="text-center">
          <div class="text-2xl font-bold text-blue-900">
            {{ Math.ceil(route.duration / 60) }}
          </div>
          <div class="text-xs text-blue-700">min</div>
        </div>
      </div>
    </div>

    <!-- Price Estimate (for van service) -->
    <div v-if="estimatePrice" class="mt-3 pt-3 border-t border-blue-200">
      <div class="flex items-center justify-between">
        <span class="text-sm font-medium text-blue-900">Estimated Price</span>
        <span class="text-lg font-bold text-green-600">
          £{{ calculatePrice(route.distance, route.duration) }}
        </span>
      </div>
      <div class="text-xs text-blue-700 mt-1">
        Based on £2.50/km + £0.50/min
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
interface RouteSummaryProps {
  route: {
    distance: number
    duration: number
    summary: {
      text: string
    }
  }
  estimatePrice?: boolean
}

const props = withDefaults(defineProps<RouteSummaryProps>(), {
  estimatePrice: true
})

// Format distance
const formatDistance = (meters: number) => {
  if (meters < 1000) {
    return `${meters.toFixed(0)} meters`
  }
  return `${(meters / 1000).toFixed(1)} kilometers`
}

// Format duration
const formatDuration = (seconds: number) => {
  const minutes = Math.ceil(seconds / 60)
  if (minutes < 60) {
    return `${minutes} minutes`
  }
  const hours = Math.floor(minutes / 60)
  const remainingMinutes = minutes % 60
  return `${hours}h ${remainingMinutes}m`
}

// Calculate price for van service
const calculatePrice = (distance: number, duration: number) => {
  const distanceKm = distance / 1000
  const durationMin = duration / 60

  // TODO: example pricing: £2.50 per km + £0.50 per minute
  const price = (distanceKm * 2.5) + (durationMin * 0.5)
  return price.toFixed(2)
}
</script>
