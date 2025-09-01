# Base image: PHP + Apache
FROM php:8.2-apache

# Install required extensions (PostgreSQL, mysqli, pdo)
RUN docker-php-ext-install pdo pdo_pgsql pgsql mysqli

# Copy project files into container
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html/

# Expose port 80
EXPOSE 80

# Start Apache server
CMD ["apache2-foreground"]
