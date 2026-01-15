export const config = {
    ports: {
        api: parseInt(process.env.PORT || '3001'),
        socket: parseInt(process.env.SOCKET_PORT || '3002')
    },

    services: {
        nominatim: process.env.NOMINATIM_URL || 'http://nominatim-api:8080',
        osrm: process.env.OSRM_URL || 'http://osrm-backend:5000',
        redis: process.env.REDIS_URL || 'redis://redis:6379'
    },

    cache: {
        ttl: parseInt(process.env.CACHE_TTL || '3600')
    },

    rateLimiting: {
        windowMs: parseInt(process.env.RATE_LIMIT_WINDOW || '900000'),
        max: parseInt(process.env.RATE_LIMIT_MAX || '100')
    },

    londonBounds: {
        // Parse from env or use defaults (matching docker-compose)
        west: parseFloat(process.env.LONDON_WEST || '0.52'),
        south: parseFloat(process.env.LONDON_SOUTH || '51.28'),
        east: parseFloat(process.env.LONDON_EAST || '0.33'),
        north: parseFloat(process.env.LONDON_NORTH || '51.72')
    },

    geofence: {
        congestionCharge: 15.00, // GBP
        ulezCharge: 12.50        // GBP
    },

    logging: {
        level: process.env.LOG_LEVEL || 'info',
        requests: process.env.LOG_REQUESTS !== 'false'
    }
};

export type Config = typeof config;