// composables/useNominatim.ts
export const useNominatim = () => {
  const search = async (query: string, limit: number = 5) => {
    const { data, error } = await useFetch(`http://localhost:8181/?q=${encodeURIComponent(query)}&limit=${limit}`)

    if (error.value) {
      throw new Error(`Nominatim search failed: ${error.value.message}`)
    }

    return data.value
  }

  const reverse = async (lat: number, lon: number) => {
    const { data, error } = await useFetch(`http://localhost:8181/?lat=${lat}&lon=${lon}`)

    if (error.value) {
      throw new Error(`Reverse geocoding failed: ${error.value.message}`)
    }

    return data.value
  }

  return {
    search,
    reverse
  }
}
