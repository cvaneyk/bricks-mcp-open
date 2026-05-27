FROM node:22-bookworm

# System deps
RUN apt-get update && apt-get install -y \
    git bash \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# MCP Server (from parent repo)
COPY ../index.js /app/bricks-mcp/index.js
COPY ../config.js /app/bricks-mcp/config.js
COPY ../site-manager.js /app/bricks-mcp/site-manager.js
COPY ../tools/ /app/bricks-mcp/tools/
COPY ../utils/ /app/bricks-mcp/utils/
COPY ../package.json /app/bricks-mcp/package.json
RUN cd /app/bricks-mcp && npm install --production

# Agent Service
COPY package.json ./
RUN npm install

COPY src/ /app/src/
COPY briefs/ /app/briefs/
COPY agents/ /app/agents/
COPY tsconfig.json /app/tsconfig.json

ENV MCP_COMMAND="node"
ENV MCP_SERVER_PATH="/app/bricks-mcp/index.js"

RUN mkdir -p /data

EXPOSE 8080

COPY entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh
CMD ["/app/entrypoint.sh"]
