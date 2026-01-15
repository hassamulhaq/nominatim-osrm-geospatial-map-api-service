#!/bin/bash
# monitor.sh - Monitor setup progress

echo "Monitoring Map Services Setup..."
echo "======================================"

# 1. Check container status
echo "1. Container Status:"
docker compose ps

echo ""
echo "2. Image Download Status:"
docker images | grep -E "(postgis|nominatim|osrm|redis|adminer)"

echo ""
echo "3. Service Health:"
services=("nominatim-postgres" "osrm-backend" "nominatim-api" "map-api" "redis")
for service in "${services[@]}"; do
    if docker compose ps | grep -q "$service.*Up"; then
        echo "✅ $service: RUNNING"
    else
        echo "⏳ $service: STARTING"
    fi
done

echo ""
echo "4. Recent Logs (last 5 lines each):"
echo "------------------------------------"
for service in "${services[@]}"; do
    echo "$service:"
    docker compose logs --tail=5 $service 2>/dev/null | tail -5
    echo ""
done

echo "5. Estimated Completion:"
echo "   - Images download: 5-10 min (currently downloading)"
echo "   - DB initialization: 5-10 min"
echo "   - London extraction: 10-20 min"
echo "   - Total wait: 20-40 minutes"
echo ""
echo "⚡ Run './monitor.sh' again in 5 minutes"