FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nginx \
    supervisor

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy existing application directory contents
COPY . /var/www/html

# Copy existing application directory permissions
COPY --chown=www-data:www-data . /var/www/html

# Copy nginx config (assuming standard or skip if not present, but better to add a basic one)
# For now, we will use default nginx config but modify it slightly or use phpartisan serve for dev?
# No, this is for prod. We need a basic nginx conf. 
# Or we can use `cloudnative-pg` or similar images.
# Let's stick to a simple php-fpm setup and assume user has nginx on host or we add nginx here.
# I added nginx to apt-get above.

# Create directory for nginx socket
RUN mkdir -p /var/run/nginx

# Expose port 80
EXPOSE 80

# Start supervisor (or just php-fpm if no nginx)
CMD ["php-fpm"]
