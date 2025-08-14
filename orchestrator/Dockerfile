# Unified MCP Orchestrator Dockerfile
FROM python:3.11-slim

# Set working directory
WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    gcc \
    g++ \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements
COPY requirements.txt .

# Install Python dependencies
RUN pip install --no-cache-dir -r requirements.txt

# Copy orchestrator code
COPY orchestrator/ ./orchestrator/
COPY config/ ./config/
COPY tools/ ./tools/
COPY features/ ./features/

# Create necessary directories
RUN mkdir -p /app/artifacts /app/logs /app/data

# Environment variables
ENV PYTHONPATH=/app
ENV ORCHESTRATOR_HOME=/app
ENV LOG_LEVEL=INFO

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8081/health || exit 1

# Expose ports
# 8080: MCP Server
# 9090: Metrics endpoint
# 8081: Health check endpoint
EXPOSE 8080 9090 8081

# Run orchestrator
CMD ["python", "-m", "orchestrator.mcp_unified_orchestrator"]