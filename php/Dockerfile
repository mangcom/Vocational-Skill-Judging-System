FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

# ชี้ DocumentRoot ไปที่ public/ เพื่อไม่ให้เข้าถึงโค้ดฝั่ง app/ จากเว็บได้ตรง ๆ
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && { \
        echo '<Directory /var/www/html/public>'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
    } >> /etc/apache2/apache2.conf

COPY src/ /var/www/html/
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && mkdir -p /var/www/html/storage/uploads/criteria \
    && chown -R www-data:www-data /var/www/html/storage

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
