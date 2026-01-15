import express from 'express';
import {createServer} from 'http';
import {Server} from 'socket.io';
import cors from 'cors';
import NodeCache from 'node-cache';
import {rateLimit} from 'express-rate-limit';
import {searchService} from './services/search.service';
import {routingService} from './services/routing.service';
import {geofenceService} from './services/geofence.service';

const app = express();
const httpServer = createServer(app);
const io = new Server(httpServer, {
    cors: {
        origin: process.env.NUXT_URL || 'http://localhost:3000',
        credentials: true
    }
});

// Configuration
const PORT = process.env.PORT || 3001;
const cache = new NodeCache({stdTTL: parseInt(process.env.CACHE_TTL || '3600')});

// Middleware
app.use((req, res, next) => {
    console.log({res});
    console.log(`${req.method} ${req.url}`);
    next();
});

app.use(cors({
    origin: [process.env.NUXT_URL || 'http://localhost:3000',
        process.env.LARAVEL_URL || 'http://localhost:8000'],
    credentials: true
}));
app.use(express.json());

// Rate limiting
const limiter = rateLimit({
    windowMs: parseInt(process.env.RATE_LIMIT_WINDOW || '900000'), // 15 minutes
    max: parseInt(process.env.RATE_LIMIT_MAX || '100'),
    message: {error: 'Too many requests, please try again later.'}
});
app.use('/api', limiter);

// Health check endpoint
app.get('/health', async (req, res) => {
    try {
        console.log({req})
        const [nominatimHealthy, osrmHealthy] = await Promise.all([
            searchService.isHealthy(),
            routingService.isHealthy()
        ]);

        res.json({
            status: 'healthy',
            timestamp: new Date().toISOString(),
            services: {
                nominatim: nominatimHealthy,
                osrm: osrmHealthy,
                cache: cache.stats.keys > 0
            }
        });
    } catch (error) {
        res.status(500).json({
            status: 'unhealthy',
            error: 'Health check failed'
        });
    }
});

// API Routes
// @ts-ignore
app.post('/api/search', async (req, res) => {
    try {
        const {query, limit = 5, bounds} = req.body;
        const cacheKey = `search:${query}:${limit}:${bounds}`;

        // check cache
        const cached = cache.get(cacheKey);
        if (cached) {
            return res.json(cached);
        }

        // perform search
        const results = await searchService.searchLocations(query, limit, bounds);

        // Cache results
        cache.set(cacheKey, results);
        res.json(results);
    } catch (error: any) {
        console.error('Search error:', error);
        res.status(500).json({
            error: 'Search failed',
            message: error.message,
        });
    }
});

// @ts-ignore
app.post('/api/route', async (req, res) => {
    try {
        const {from, to, mode = 'driving'} = req.body;
        const cacheKey = `route:${from.join(',')}:${to.join(',')}:${mode}`;

        // Check cache
        const cached = cache.get(cacheKey);
        if (cached) {
            return res.json(cached);
        }

        // Calculate route
        const route = await routingService.calculateRoute(from, to, mode);

        // Cache results
        cache.set(cacheKey, route);

        // Emit real-time update via Socket.IO
        io.emit('route:calculated', {
            id: req.body.requestId,
            route,
            timestamp: new Date().toISOString()
        });

        res.json(route);
    } catch (error: any) {
        console.error('Routing error:', error);
        res.status(500).json({
            error: 'Route calculation failed',
            message: error.message
        });
    }
});

app.post('/api/is-in-london', async (req, res) => {
    try {
        const {lat, lng} = req.body;
        const isInLondon = await geofenceService.isWithinLondonBounds([lng, lat]); // Note: [lng, lat]
        res.json({isInLondon});
    } catch (error: any) {
        res.status(500).json({
            error: 'Geofence check failed',
            message: error.message
        });
    }
});

// Batch operations for multiple routes
app.post('/api/routes/batch', async (req, res) => {
    try {
        const {routes} = req.body;
        const results = await Promise.all(
            routes.map(async (route: any) => {
                const result = await routingService.calculateRoute(route.from, route.to, route.mode);
                return {...route, result};
            })
        );
        res.json(results);
    } catch (error: any) {
        res.status(500).json({
            error: 'Batch routing failed',
            message: error.message
        });
    }
});

// Get zone information
app.post('/api/zone-info', async (req, res) => {
    try {
        const {lat, lng} = req.body;
        const zoneInfo = await geofenceService.getZoneInfo([lng, lat]); // Note: [lng, lat]
        res.json(zoneInfo);
    } catch (error: any) {
        res.status(500).json({
            error: 'Zone info failed',
            message: error.message
        });
    }
});

// Reverse geocoding
app.post('/api/reverse-geocode', async (req, res) => {
    try {
        const {lat, lng} = req.body;
        const address = await searchService.reverseGeocode(lat, lng);
        res.json(address);
    } catch (error: any) {
        res.status(500).json({
            error: 'Reverse geocoding failed',
            message: error.message
        });
    }
});

// Socket.IO connections
io.on('connection', (socket) => {
    console.log('Client connected:', socket.id);

    socket.on('track:location', (data) => {
        // Real-time location tracking
        socket.broadcast.emit('location:update', {
            userId: data.userId,
            location: data.location,
            timestamp: new Date().toISOString()
        });
    });

    socket.on('route:subscribe', (routeId) => {
        socket.join(`route:${routeId}`);
    });

    socket.on('disconnect', () => {
        console.log('Client disconnected:', socket.id);
    });
});

// Error handling middleware
app.use((err: any, req: any, res: any, next: any) => {
    console.log({req}, {next})
    console.error('Unhandled error:', err);
    res.status(500).json({
        error: 'Internal server error',
        message: process.env.NODE_ENV === 'development' ? err.message : undefined
    });
});

// 404 handler
app.use((req, res) => {
    console.log({req});
    res.status(404).json({error: 'Route not found'});
});

// Start server
httpServer.listen(PORT, () => {
    console.log(`🚀 Map API running on http://localhost:${PORT}`);
    console.log(`📡 Socket.IO available on same port`);
});