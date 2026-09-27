#!/bin/sh
set -e

# Start Garage daemon in background
garage -c /etc/garage.toml server &
SERVER_PID=$!

# Wait for Garage daemon to be ready
echo "Waiting for Garage to start..."
until garage -c /etc/garage.toml status > /dev/null 2>&1; do
    sleep 0.5
done
echo "Garage server is running."

# Configure cluster layout for single-node setup
NODE_ID=$(garage -c /etc/garage.toml node id -q)
echo "Garage node ID: $NODE_ID"

CURRENT_VERSION=$(garage -c /etc/garage.toml layout show 2>/dev/null | grep -i "cluster layout version:" | awk '{print $NF}' || true)
if [ -z "$CURRENT_VERSION" ] || [ "$CURRENT_VERSION" = "0" ]; then
    echo "Configuring cluster layout for single-node setup..."
    garage -c /etc/garage.toml layout assign -z dc1 -c 1G "$NODE_ID"
    garage -c /etc/garage.toml layout apply --version 1
else
    echo "Cluster layout already configured (version $CURRENT_VERSION)."
    garage -c /etc/garage.toml layout revert >/dev/null 2>&1 || true
fi

# Import or verify S3 API key
KEY_ID="${AWS_KEY:-minioadmin}"
SECRET_KEY="${AWS_SECRET:-minioadminpassword}"
KEY_NAME="roquette-key"

NEED_KEY_IMPORT=0
if ! garage -c /etc/garage.toml key list | grep -q "$KEY_NAME"; then
    NEED_KEY_IMPORT=1
elif ! garage -c /etc/garage.toml key info "$KEY_NAME" 2>/dev/null | grep -F -q "$KEY_ID"; then
    echo "Key $KEY_NAME exists with different ID, updating..."
    garage -c /etc/garage.toml key delete --yes "$KEY_NAME" >/dev/null 2>&1 || true
    NEED_KEY_IMPORT=1
else
    echo "Key $KEY_NAME already exists and matches KEY_ID."
fi

if [ "$NEED_KEY_IMPORT" -eq 1 ]; then
    echo "Importing roquette S3 key..."
    garage -c /etc/garage.toml key import --yes -n "$KEY_NAME" "$KEY_ID" "$SECRET_KEY"
fi

# Create bucket and grant permissions
BUCKET_NAME="${AWS_BUCKET:-roquette-uploads}"

if ! garage -c /etc/garage.toml bucket list | grep -q "$BUCKET_NAME"; then
    echo "Creating bucket $BUCKET_NAME..."
    garage -c /etc/garage.toml bucket create "$BUCKET_NAME"
else
    echo "Bucket $BUCKET_NAME already exists."
fi

echo "Ensuring permissions on bucket $BUCKET_NAME for key $KEY_NAME..."
garage -c /etc/garage.toml bucket allow "$BUCKET_NAME" --read --write --key "$KEY_NAME"

echo "Garage setup completed successfully."
wait $SERVER_PID
