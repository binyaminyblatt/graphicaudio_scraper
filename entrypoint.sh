#!/bin/bash

# Generate random refresh key if not provided
if [ -z "$REFRESH_KEY" ]; then
    REFRESH_KEY=$(openssl rand -hex 32)
    echo "========================================"
    echo "Generated REFRESH_KEY: $REFRESH_KEY"
    echo "Use this key for the /refresh endpoint"
    echo "========================================"
    export REFRESH_KEY
fi

# Start Apache
exec apache2-foreground