#!/bin/bash
set -e

# Allow connections from all hosts
echo "host all all all md5" >> /var/lib/postgresql/data/pg_hba.conf
