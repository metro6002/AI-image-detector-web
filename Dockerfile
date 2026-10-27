FROM ubuntu:24.04

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# Update and install system dependencies: Apache2, PHP with cURL and MySQL, and MySQL Server
RUN apt-get update && apt-get install -y --no-install-recommends \
    apache2 \
    php \
    libapache2-mod-php \
    php-mysql \
    php-curl \
    php-mbstring \
    php-xml \
    mysql-server \
    curl \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache rewrite module and suppress ServerName warnings
RUN a2enmod rewrite \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Configure PHP settings for file uploads and environment variable visibility
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = 25M/' /etc/php/*/apache2/php.ini \
    && sed -i 's/post_max_size = .*/post_max_size = 30M/' /etc/php/*/apache2/php.ini \
    && sed -i 's/variables_order = .*/variables_order = "EGPCS"/' /etc/php/*/apache2/php.ini

# Set working directory to Apache document root
WORKDIR /var/www/html

# Remove the default Ubuntu Apache index page
RUN rm -f /var/www/html/index.html

# Copy schema and entrypoint script
COPY schema.sql /var/www/schema.sql
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Copy web source files
COPY src/ /var/www/html/

# Ensure uploads directory exists and set permissions
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads

# Expose HTTP port
EXPOSE 80

# Use start.sh entrypoint to manage MySQL and Apache
ENTRYPOINT ["/usr/local/bin/start.sh"]
