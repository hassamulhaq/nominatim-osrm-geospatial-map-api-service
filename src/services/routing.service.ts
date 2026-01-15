import axios, {AxiosInstance} from 'axios';
import {lineString, length} from '@turf/turf';

export class RoutingService {
    private client: AxiosInstance;
    private requestQueue: Array<() => Promise<any>> = [];
    private processing = false;
    private maxConcurrentRequests = 3;

    constructor() {
        this.client = axios.create({
            baseURL: process.env.OSRM_URL || 'http://osrm-backend:5000',
            timeout: 15000,
            headers: {
                'Accept': 'application/json'
            }
        });

        // Start queue processor
        this.processQueue();
    }

    /**
     * Calculate route between two points
     * @param from [lng, lat]
     * @param to [lng, lat]
     * @param mode driving, walking, cycling
     */
    async calculateRoute(from: [number, number], to: [number, number], mode: string = 'driving'): Promise<any> {
        return new Promise((resolve, reject) => {
            const request = async () => {
                try {
                    const result = await this.makeRouteRequest(from, to, mode);
                    resolve(result);
                } catch (error) {
                    reject(error);
                }
            };

            this.requestQueue.push(request);
        });
    }

    private async makeRouteRequest(from: [number, number], to: [number, number], mode: string): Promise<any> {
        const [fromLng, fromLat] = from;
        const [toLng, toLat] = to;

        try {
            const response = await this.client.get(`/route/v1/${mode}/${fromLng},${fromLat};${toLng},${toLat}`, {
                params: {
                    overview: 'full',
                    geometries: 'geojson',
                    steps: true,
                    annotations: true,
                    alternatives: 2 // Get alternative routes
                }
            });

            if (!response.data.routes || response.data.routes.length === 0) {
                throw new Error('No route found');
            }

            const route = response.data.routes[0];

            return {
                distance: route.distance, // meters
                duration: route.duration, // seconds
                geometry: route.geometry,
                legs: route.legs,
                waypoints: response.data.waypoints,
                summary: this.getRouteSummary(route),
                alternatives: response.data.routes.slice(1).map((alt: any) => ({
                    distance: alt.distance,
                    duration: alt.duration,
                    summary: this.getRouteSummary(alt)
                }))
            };
        } catch (error: any) {
            console.error('OSRM routing error:', error.message);

            // Fallback: create straight line if OSRM fails
            return this.createFallbackRoute(from, to);
        }
    }

    private getRouteSummary(route: any): string {
        const km = (route.distance / 1000).toFixed(1);
        const min = Math.ceil(route.duration / 60);
        return `${km} km • ${min} min`;
    }

    private createFallbackRoute(from: [number, number], to: [number, number]): any {
        const line = lineString([from, to]);
        const distance = length(line, {units: 'kilometers'}) * 1000; // Convert to meters
        const duration = distance / 13.41; // Assume 13.41 m/s ≈ 48.3 km/h average speed

        return {
            distance,
            duration,
            geometry: {
                type: 'LineString',
                coordinates: [from, to]
            },
            summary: `${(distance / 1000).toFixed(1)} km • ${Math.ceil(duration / 60)} min`,
            isFallback: true
        };
    }

    private async processQueue(): Promise<void> {
        if (this.processing || this.requestQueue.length === 0) {
            return;
        }

        this.processing = true;

        while (this.requestQueue.length > 0) {
            const batch = this.requestQueue.splice(0, this.maxConcurrentRequests);
            await Promise.all(batch.map(request => request()));

            // Small delay between batches to avoid overwhelming OSRM
            if (this.requestQueue.length > 0) {
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        }

        this.processing = false;

        // Check queue again after a short delay
        setTimeout(() => this.processQueue(), 100);
    }

    async calculateMultipleRoutes(origins: [number, number][], destinations: [number, number][]): Promise<any> {
        const coordinates = [...origins, ...destinations].map(c => c.join(',')).join(';');

        try {
            const response = await this.client.get(`/table/v1/driving/${coordinates}`, {
                params: {
                    sources: Array(origins.length).fill(0).map((_, i) => i).join(';'),
                    destinations: Array(destinations.length).fill(0).map((_, i) => i + origins.length).join(';')
                }
            });

            return response.data;
        } catch (error: any) {
            console.error('OSRM matrix error:', error.message);
            throw new Error(`Matrix routing failed: ${error.message}`);
        }
    }

    async isHealthy(): Promise<boolean> {
        try {
            // Simple test route within London
            await this.client.get('/route/v1/driving/0.1278,51.5074;-0.0900,51.5050', {
                params: {overview: false},
                timeout: 5000
            });
            return true;
        } catch (error) {
            console.warn('OSRM health check failed:', error);
            return false;
        }
    }
}

export const routingService = new RoutingService();