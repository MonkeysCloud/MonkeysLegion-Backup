#!/usr/bin/env bash
set -euo pipefail

sudo apt-get update
sudo apt-get install -y --no-install-recommends \
    mysql-client \
    postgresql-client \
    redis-tools \
    wget \
    gnupg

# MongoDB shell + database tools (mongosh, mongodump, mongorestore).
wget -qO- https://www.mongodb.org/static/pgp/server-7.0.asc \
    | sudo gpg --dearmor -o /usr/share/keyrings/mongodb-server-7.0.gpg
echo "deb [ signed-by=/usr/share/keyrings/mongodb-server-7.0.gpg ] https://repo.mongodb.org/apt/ubuntu jammy/mongodb-org/7.0 multiverse" \
    | sudo tee /etc/apt/sources.list.d/mongodb-org-7.0.list
sudo apt-get update
sudo apt-get install -y --no-install-recommends \
    mongodb-mongosh \
    mongodb-database-tools

command -v mysqldump
command -v pg_dump
command -v mongodump
command -v mongosh
command -v redis-cli
