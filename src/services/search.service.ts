import axios, {AxiosInstance, AxiosError} from 'axios';

export class SearchService {
    private client: AxiosInstance;
    private londonBounds = {
        minLon: process.env.LONDON_EAST || 0.33,   // default: East London UK
        minLat: process.env.LONDON_SOUTH || 51.28,  // default: South London UK
        maxLon: process.env.LONDON_WEST || 0.52,   // default: West London UK
        maxLat: process.env.LONDON_NORTH || 51.72   // default: North London UK
    };

    constructor() {
        this.client = axios.create({
            baseURL: process.env.NOMINATIM_URL || 'http://nominatim-api:8080',
            timeout: 10000,
            headers: {
                'User-Agent': 'LondonMapService/1.0',
                'Accept': 'application/json'
            }
        });

        // Add retry logic for failed requests
        this.client.interceptors.response.use(
            (response) => response,
            async (error: AxiosError) => {
                const config = error.config;

                // Only retry on server errors or network issues
                if (!config || error?.response?.status || 0 < 500) {
                    return Promise.reject(error);
                }

                // Wait and retry once
                await new Promise(resolve => setTimeout(resolve, 1000));
                return this.client.request(config);
            }
        );
    }

    async searchLocations(query: string, limit: number = 5, useBounds: boolean = true): Promise<any[]> {
        const params: any = {
            q: `${query}, London, UK`,
            format: 'json',
            limit,
            addressdetails: 1,
            'accept-language': 'en-GB'
        };

        if (useBounds) {
            params.viewbox = `${this.londonBounds.minLon},${this.londonBounds.minLat},${this.londonBounds.maxLon},${this.londonBounds.maxLat}`;
            params.bounded = 1;
        }

        try {
            const response = await this.client.get('/search', {params});

            return response.data.map((item: any) => ({
                name: item.display_name,
                coordinates: [parseFloat(item.lat), parseFloat(item.lon)],
                address: item.address,
                type: item.type,
                importance: item.importance,
                boundingBox: item.boundingbox
            }));
        } catch (error: any) {
            console.error('Search service error:', error.message);
            throw new Error(`Search service unavailable: ${error.message}`);
        }
    }

    async reverseGeocode(lat: number, lon: number): Promise<any> {
        try {
            const response = await this.client.get('/reverse', {
                params: {
                    lat,
                    lon,
                    format: 'json',
                    'accept-language': 'en-GB',
                    zoom: 18 // Maximum detail level
                }
            });

            return {
                address: response.data.address,
                displayName: response.data.display_name,
                coordinates: {lat, lon}
            };
        } catch (error: any) {
            console.error('Reverse geocode error:', error.message);
            throw new Error(`Reverse geocoding failed: ${error.message}`);
        }
    }

    async isHealthy(): Promise<boolean> {
        try {
            await this.client.get('/status', {
                timeout: 3000,
                params: {format: 'json'}
            });
            return true;
        } catch (error) {
            console.warn('Nominatim health check failed:', error);
            return false;
        }
    }
}

export const searchService = new SearchService();