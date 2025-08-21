#!/usr/bin/env bash
# Live Preview Server Launch Script for Claude Code Agent
# Provides live HTML/JavaScript preview directly inside Cursor

PORT=${1:-5500}  # Port for the local server (adjustable via first argument)
NO_BROWSER=${2:-"--no-browser"}  # Prevent external browser opening

echo "🚀 Launching live-server on http://127.0.0.1:$PORT (auto-reload enabled)..."
echo "📝 Changes to HTML/CSS/JS files will trigger automatic page refresh"
echo "🔗 Open in Cursor: Command Palette > 'Simple Browser: Show' > http://localhost:$PORT"
echo ""

# Check if live-server is installed
if ! command -v live-server &> /dev/null; then
    echo "❌ live-server not found. Installing globally..."
    npm install -g live-server
    if [ $? -ne 0 ]; then
        echo "❌ Failed to install live-server. Please run: npm install -g live-server"
        exit 1
    fi
fi

# Check if we're in a directory with web files
if [ ! -f "index.html" ] && [ ! -f "public/index.html" ] && [ ! -f "src/index.html" ]; then
    echo "⚠️  No index.html found in current directory, public/, or src/"
    echo "📁 Files will be served from current directory: $(pwd)"
    echo ""
fi

# Start the live server with auto-reload
echo "🔄 Starting live-server with file watching enabled..."
live-server --port="$PORT" $NO_BROWSER --watch=. --wait=200 --ignore=node_modules,backend,.git