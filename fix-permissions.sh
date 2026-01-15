#!/bin/bash
# fix-permissions.sh

echo "Fixing permissions for Docker data directories..."

# Get current user ID and group ID
USER_ID=$(id -u)
GROUP_ID=$(id -g)

# Set proper permissions and ownership
sudo chmod -R 777 data/ logs/
sudo chown -R $USER_ID:$GROUP_ID data/ logs/

# Fix specific subdirectories
find data/ -type d -exec sudo chmod 777 {} \;
find logs/ -type d -exec sudo chmod 777 {} \;

echo "Permissions fixed! You can now run: docker-compose up -d"