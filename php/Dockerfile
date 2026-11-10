FROM php:8.2-apache

# default-mysql-client -> คำสั่ง mysql/mysqldump สำหรับ Export/Import ฐานข้อมูล
# libzip-dev -> ไลบรารีที่ docker-php-ext-install zip ต้องใช้ตอน build ส่วนขยาย ZipArchive
RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

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
