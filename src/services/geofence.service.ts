import {booleanPointInPolygon} from '@turf/turf';
// @ts-ignore
import type {Point, Polygon} from '@turf/turf';

export class GeofenceService {
    private londonBoundary: Polygon;

    // London borough boundaries (example - add more as needed)
    private boroughs: Record<string, any> = {
        'westminster': {
            type: 'Polygon',
            coordinates: [[
                [-0.17, 51.48],
                [-0.13, 51.48],
                [-0.13, 51.52],
                [-0.17, 51.52],
                [-0.17, 51.48]
            ]]
        },
        'camden': {
            type: 'Polygon',
            coordinates: [[
                [-0.15, 51.53],
                [-0.12, 51.53],
                [-0.12, 51.56],
                [-0.15, 51.56],
                [-0.15, 51.53]
            ]]
        },
        'tower-hamlets': {
            type: 'Polygon',
            coordinates: [[
                [-0.08, 51.50],
                [-0.03, 51.50],
                [-0.03, 51.53],
                [-0.08, 51.53],
                [-0.08, 51.50]
            ]]
        }
    };

    // Congestion charge zone polygon (Central London)
    private congestionZone: Polygon = {
        type: 'Polygon',
        coordinates: [[
            [-0.12, 51.50],
            [-0.08, 51.50],
            [-0.08, 51.53],
            [-0.12, 51.53],
            [-0.12, 51.50]
        ]]
    };

    // ULEZ (Ultra Low Emission Zone) boundary
    private ulezZone: Polygon = {
        type: 'Polygon',
        coordinates: [[
            [-0.15, 51.47],
            [-0.05, 51.47],
            [-0.05, 51.55],
            [-0.15, 51.55],
            [-0.15, 51.47]
        ]]
    };

    constructor() {
        // Load bounds from environment (matching docker-compose)
        const bounds = process.env.LONDON_BOUNDS?.split(',').map(Number) || [0.33, 51.28, 0.52, 51.72];
        const [east, south, west, north] = bounds;

        this.londonBoundary = {
            type: 'Polygon',
            coordinates: [[
                [west, south],  // Southwest
                [east, south],  // Southeast
                [east, north],  // Northeast
                [west, north],  // Northwest
                [west, south]   // Close polygon
            ]]
        };
    }

    /**
     * Check if a point is within London bounds
     * @param coordinates [lng, lat]
     */
    async isWithinLondonBounds(coordinates: [number, number]): Promise<boolean> {
        const point: Point = {
            type: 'Point',
            coordinates
        };

        return booleanPointInPolygon(point, this.londonBoundary);
    }

    /**
     * Check if a point is within a specific London borough
     * @param coordinates [lng, lat]
     */
    async getBoroughForLocation(coordinates: [number, number]): Promise<string | null> {
        const point: Point = {
            type: 'Point',
            coordinates
        };

        for (const [boroughName, boroughPolygon] of Object.entries(this.boroughs)) {
            if (booleanPointInPolygon(point, boroughPolygon)) {
                return boroughName;
            }
        }

        return null;
    }

    /**
     * Check if a point is within the Congestion Charge zone
     * @param coordinates [lng, lat]
     */
    async isInCongestionZone(coordinates: [number, number]): Promise<boolean> {
        const point: Point = {
            type: 'Point',
            coordinates
        };

        return booleanPointInPolygon(point, this.congestionZone);
    }

    /**
     * Check if a point is within the ULEZ zone
     * @param coordinates [lng, lat]
     */
    async isInUlezZone(coordinates: [number, number]): Promise<boolean> {
        const point: Point = {
            type: 'Point',
            coordinates
        };

        return booleanPointInPolygon(point, this.ulezZone);
    }

    /**
     * Get all zone information for a location
     * @param coordinates [lng, lat]
     */
    async getZoneInfo(coordinates: [number, number]): Promise<{
        isInLondon: boolean;
        borough: string | null;
        isInCongestionZone: boolean;
        isInUlezZone: boolean;
        zones: string[];
    }> {
        const [isInLondon, borough, isInCongestionZone, isInUlezZone] = await Promise.all([
            this.isWithinLondonBounds(coordinates),
            this.getBoroughForLocation(coordinates),
            this.isInCongestionZone(coordinates),
            this.isInUlezZone(coordinates)
        ]);

        const zones: string[] = [];
        if (isInCongestionZone) zones.push('congestion-charge');
        if (isInUlezZone) zones.push('ulez');

        return {
            isInLondon,
            borough,
            isInCongestionZone,
            isInUlezZone,
            zones
        };
    }

    /**
     * Check if a route passes through specific zones
     */
    async analyzeRouteForZones(routeGeometry: any): Promise<{
        passesThroughCongestionZone: boolean;
        passesThroughUlezZone: boolean;
        affectedSegments: Array<{
            startIndex: number;
            endIndex: number;
            zone: string;
        }>;
    }> {
        const coordinates = routeGeometry.coordinates;
        const affectedSegments: Array<{
            startIndex: number;
            endIndex: number;
            zone: string;
        }> = [];

        let currentZone: string | null = null;
        let segmentStart = 0;

        for (let i = 0; i < coordinates.length; i++) {
            const point = coordinates[i];
            const pointFeature: Point = {
                type: 'Point',
                coordinates: point
            };

            const inCongestionZone = booleanPointInPolygon(pointFeature, this.congestionZone);
            const inUlezZone = booleanPointInPolygon(pointFeature, this.ulezZone);

            let zoneAtPoint: string | null = null;
            if (inCongestionZone) zoneAtPoint = 'congestion-charge';
            else if (inUlezZone) zoneAtPoint = 'ulez';

            // If zone changes, record the segment
            if (zoneAtPoint !== currentZone && currentZone !== null) {
                affectedSegments.push({
                    startIndex: segmentStart,
                    endIndex: i - 1,
                    zone: currentZone
                });
                segmentStart = i;
            }

            currentZone = zoneAtPoint;
        }

        // Record the last segment if it's in a zone
        if (currentZone !== null) {
            affectedSegments.push({
                startIndex: segmentStart,
                endIndex: coordinates.length - 1,
                zone: currentZone
            });
        }

        return {
            passesThroughCongestionZone: affectedSegments.some(s => s.zone === 'congestion-charge'),
            passesThroughUlezZone: affectedSegments.some(s => s.zone === 'ulez'),
            affectedSegments
        };
    }

    /**
     * Get bounding box for London (useful for map initialization)
     */
    getLondonBoundingBox(): [number, number, number, number] {
        const coords = this.londonBoundary.coordinates[0];
        let west = coords[0][0];
        let south = coords[0][1];
        let east = coords[0][0];
        let north = coords[0][1];

        for (const [lng, lat] of coords) {
            west = Math.min(west, lng);
            south = Math.min(south, lat);
            east = Math.max(east, lng);
            north = Math.max(north, lat);
        }

        return [west, south, east, north];
    }

    /**
     * Check if a bounding box is completely within London
     */
    async isBoundingBoxWithinLondon(bounds: [number, number, number, number]): Promise<boolean> {
        const [west, south, east, north] = bounds;

        // Check all four corners
        const corners: [number, number][] = [
            [west, south], // Southwest
            [east, south], // Southeast
            [east, north], // Northeast
            [west, north]  // Northwest
        ];

        for (const corner of corners) {
            if (!await this.isWithinLondonBounds(corner)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filter coordinates to only those within London
     */
    async filterToLondon(coordinates: [number, number][]): Promise<[number, number][]> {
        const filtered: [number, number][] = [];

        for (const coord of coordinates) {
            if (await this.isWithinLondonBounds(coord)) {
                filtered.push(coord);
            }
        }

        return filtered;
    }

    /**
     * Get polygon for a specific zone
     */
    getZonePolygon(zone: 'london' | 'congestion' | 'ulez' | string): Polygon | null {
        switch (zone) {
            case 'london':
                return this.londonBoundary;
            case 'congestion':
                return this.congestionZone;
            case 'ulez':
                return this.ulezZone;
            default:
                return this.boroughs[zone] || null;
        }
    }

    /**
     * Calculate zone-specific pricing/tolls
     */
    async calculateZonePricing(routeGeometry: any, vehicleType: string = 'car'): Promise<{
        total: number;
        breakdown: Array<{ zone: string; charge: number }>;
        currency: string;
    }> {
        const zoneAnalysis = await this.analyzeRouteForZones(routeGeometry);
        const breakdown: Array<{ zone: string; charge: number }> = [];

        // Congestion Charge (£15 for cars, motorcycles free)
        if (zoneAnalysis.passesThroughCongestionZone) {
            const charge = vehicleType === 'motorcycle' ? 0 : 15;
            if (charge > 0) {
                breakdown.push({zone: 'congestion-charge', charge});
            }
        }

        // ULEZ Charge (£12.50 for non-compliant vehicles)
        if (zoneAnalysis.passesThroughUlezZone) {
            // In real implementation, you'd check vehicle compliance
            const charge = 12.50;
            breakdown.push({zone: 'ulez', charge});
        }

        const total = breakdown.reduce((sum, item) => sum + item.charge, 0);

        return {
            total,
            breakdown,
            currency: 'GBP'
        };
    }

    /**
     * Check if a route avoids specific zones
     */
    async routeAvoidsZones(routeGeometry: any, zonesToAvoid: string[]): Promise<boolean> {
        const zoneAnalysis = await this.analyzeRouteForZones(routeGeometry);

        for (const segment of zoneAnalysis.affectedSegments) {
            if (zonesToAvoid.includes(segment.zone)) {
                return false;
            }
        }

        return true;
    }
}

export const geofenceService = new GeofenceService();