# Use Node 18.18.0 base image
FROM node:18.18.0-alpine

# Set working directory
WORKDIR /app

# Copy package.json and package-lock.json
COPY package*.json ./

# Install dependencies
RUN npm install

# Copy the rest of the app files
COPY . .

# Build the Next.js app
RUN npm run build --no-lint

# Expose Next.js default port
EXPOSE 3000

# Start the app
CMD ["npm", "start"]
