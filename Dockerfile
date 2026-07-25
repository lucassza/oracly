FROM node:22-slim

WORKDIR /app

# Install dependencies for Playwright
RUN apt-get update && apt-get install -y \
    libnss3 \
    libnspr4 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libdbus-1-3 \
    libxkbcommon0 \
    libatspi2.0-0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libpango-1.0-0 \
    libcairo2 \
    libasound2 \
    libwayland-client0 \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

# Copy package files
COPY package.json package-lock.json* ./

# Install dependencies
RUN npm ci

# Install Playwright browsers
RUN npx playwright install chromium --with-deps

# Copy source
COPY tsconfig.json ./
COPY src/ ./src/

# Build
RUN npm run build

# Create storage directories
RUN mkdir -p storage/{sessions,output,screenshots,debug-html,logs}

# Environment defaults
ENV NODE_ENV=production
ENV PLAYWRIGHT_HEADLESS=true

ENTRYPOINT ["node", "dist/cli/run.js"]
CMD ["scrape"]
