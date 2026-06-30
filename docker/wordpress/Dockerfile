FROM wordpress:6.4-php8.2-apache

# The stock Apache WordPress image does not include WP-CLI, but this
# project's live smokes and dev scripts intentionally run:
#   docker exec -u www-data vyg-wp wp ...
# Install WP-CLI at image-build time so recreating vyg-wp does not lose it.
RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
      -o /usr/local/bin/wp \
    && chmod 0755 /usr/local/bin/wp \
    && wp --info --allow-root
