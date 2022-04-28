# Oasis Drupal commerce projects

Импорт товаров из oasiscatalog в Drupal 8 + commerce

Конфигурация для Drupal 8.*

PHP 7.4

## Usage

```
git clone git@github.com:oasis-catalog/drupal.git some-dir
composer install
composer up --prefer-source
```

Перейти в раздел Администрирование » Торговля » Конфигурация » Commerce Oasis  /admin/commerce/config/oasis добавить «API Key» и «API User ID», а также произвести все необходимые настройки

В разделе Администрирование » Торговля » Конфигурация » Магазин » Магазины /admin/commerce/config/stores добавить магазин по умолчанию если отсутствует

Добавить задания в cron с php7.4

Иммпорт:
```
php /site-dir/vendor/bin/drush oasis --quiet
```
Обновление остатков:
```
php /site-dir/vendor/bin/drush oasis --stock --quiet
```
