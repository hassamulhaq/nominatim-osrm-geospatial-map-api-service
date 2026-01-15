// // src/monitoring/metrics.ts
// import client from 'prom-client';
//
// const collectDefaultMetrics = client.collectDefaultMetrics;
// collectDefaultMetrics({ timeout: 5000 });
//
// export const metrics = {
//   searchRequests: new client.Counter({
//     name: 'map_search_requests_total',
//     help: 'Total number of search requests'
//   }),
//
//   routeCalculations: new client.Counter({
//     name: 'map_route_calculations_total',
//     help: 'Total number of route calculations'
//   }),
//
//   cacheHits: new client.Counter({
//     name: 'map_cache_hits_total',
//     help: 'Total number of cache hits'
//   }),
//
//   responseTime: new client.Histogram({
//     name: 'map_response_time_seconds',
//     help: 'Response time in seconds',
//     buckets: [0.1, 0.5, 1, 2, 5]
//   })
// };
//
// // Add metrics endpoint
// app.get('/metrics', async (req, res) => {
//   res.set('Content-Type', client.register.contentType);
//   res.end(await client.register.metrics());
// });