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

if ! garage -c /etc/garage.toml layout show | grep -q "$NODE_ID"; then
    echo "Assigning layout to node $NODE_ID..."
    garage -c /etc/garage.toml layout assign -z dc1 -c 1G "$NODE_ID"
    garage -c /etc/garage.toml layout apply --version 1
else
    echo "Layout already configured."
fi

# Import or verify S3 API key
KEY_ID="${AWS_KEY:-minioadmin}"
SECRET_KEY="${AWS_SECRET:-minioadminpassword}"
KEY_NAME="roquette-key"

if ! garage -c /etc/garage.toml key list | grep -q "$KEY_NAME"; then
    echo "Importing roquette S3 key..."
    garage -c /etc/garage.toml key import --yes "$KEY_ID" "$SECRET_KEY" -n "$KEY_NAME"
else
    echo "Key $KEY_NAME already exists."
fi

# Create bucket and grant permissions
BUCKET_NAME="${AWS_BUCKET:-roquette-uploads}"

if ! garage -c /etc/garage.toml bucket list | grep -q "$BUCKET_NAME"; then
    echo "Creating bucket $BUCKET_NAME..."
    garage -c /etc/garage.toml bucket create "$BUCKET_NAME"
    garage -c /etc/garage.toml bucket allow "$BUCKET_NAME" --read --write --key "$KEY_NAME"
else
    echo "Bucket $BUCKET_NAME already exists."
fi

echo "Garage setup completed successfully."
wait $SERVER_PID
