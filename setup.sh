#!/bin/bash
# setup.sh - Complete setup script for London Map Services

set -e

echo "🚀 Setting up London Map Services..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function for colored output
print_status() {
    echo -e "${GREEN}✅${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠️${NC} $1"
}

print_error() {
    echo -e "${RED}❌${NC} $1"
}

# Check for required OSM files
echo "🔍 Checking for OSM data files..."
OSM_DIR="./osm-data"
GB_FILE="$OSM_DIR/great-britain-260114.osm.pbf"
LONDON_FILE="$OSM_DIR/greater-london-260114.osm.pbf"

if [ ! -d "$OSM_DIR" ]; then
    mkdir -p "$OSM_DIR"
    print_warning "Created $OSM_DIR directory"
    print_warning "Please download OSM data files:"
    echo "  1. GB file: wget -O $GB_FILE https://download.geofabrik.de/europe/great-britain-latest.osm.pbf"
    echo "  2. Or London file: wget -O $LONDON_FILE https://download.geofabrik.de/europe/great-britain/england/greater-london-latest.osm.pbf"
else
    if [ -f "$GB_FILE" ] || [ -f "$LONDON_FILE" ]; then
        print_status "OSM data found in $OSM_DIR"
    else
        print_warning "OSM directory exists but no .pbf files found"
    fi
fi

# Create all required directories
print_status "Creating directories..."
mkdir -p \
  data/postgres-nominatim \
  data/nominatim \
  data/nominatim-flatnode \
  data/osrm \
  data/redis \
  data/cache \
  logs \
  config/postgres-init \
  scripts

# Set proper permissions (optional, but good for development)
print_status "Setting permissions..."
chmod 755 data/ logs/ scripts/

# Copy environment template if .env doesn't exist
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        print_status "Created .env file from template"
        print_warning "Please update .env with your specific values"
    else
        print_warning "No .env or .env.example found. Creating basic .env..."
        cat > .env << EOF
# Map Services Environment Variables
NODE_ENV=development

# Database
POSTGRES_PASSWORD=nominatim_password

# Redis
REDIS_PASSWORD=redis_map_pass

# Services
NOMINATIM_URL=http://nominatim-api:8080
OSRM_URL=http://osrm-backend:5000
REDIS_URL=redis://redis:6379

# London Bounds
LONDON_WEST=0.52
LONDON_SOUTH=51.28
LONDON_EAST=0.33
LONDON_NORTH=51.72
EOF
    fi
else
    print_status ".env file already exists"
fi

print_status "Setup complete!"
echo ""
echo "📋 ${YELLOW}Next steps:${NC}"
echo "1. ${GREEN}Check OSM data${NC}: Ensure you have .pbf files in ./osm-data/"
echo "2. ${GREEN}Review environment${NC}: Edit .env file if needed"
echo "3. ${GREEN}Build the services${NC}: docker compose build map-api"
echo "4. ${GREEN}Start all services${NC}: docker compose up -d"
echo "5. ${GREEN}Monitor progress${NC}: docker compose logs -f"
echo ""
echo "⏳ ${YELLOW}Initial import will take time:${NC}"
echo "   - Nominatim: 10-30 minutes (first time)"
echo "   - OSRM: 2-5 minutes"
echo ""
echo "🌐 ${YELLOW}Services will be available at:${NC}"
echo "   ${GREEN}Map API${NC}:      http://localhost:3001"
echo "   ${GREEN}Socket.IO${NC}:    ws://localhost:3002"
echo "   ${GREEN}Nominatim API${NC}: http://localhost:8181"
echo "   ${GREEN}OSRM${NC}:         http://localhost:5000"
echo "   ${GREEN}Redis${NC}:        localhost:6380"
echo "   ${GREEN}Adminer${NC}:      http://localhost:8282"
echo ""
echo "🔧 ${YELLOW}Useful commands:${NC}"
echo "   Check health: docker compose ps"
echo "   View logs:    docker compose logs [service-name]"
echo "   Stop:         docker compose down"
echo "   Full reset:   docker compose down -v"
echo ""
echo "${GREEN}✨ Setup complete! Run 'docker compose up -d' to start.${NC}"