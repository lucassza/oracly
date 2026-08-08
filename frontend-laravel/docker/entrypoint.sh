#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

# Volume nomeado pode montar `vendor/` vazio — checar o autoload, não só o diretório.
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  php artisan key:generate --force --no-interaction
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache database
touch database/database.sqlite
chmod -R ug+rwx storage bootstrap/cache database || true

php artisan migrate --force --no-interaction

php artisan tinker --execute="
if (!\\App\\Models\\User::query()->where('email', 'admin@oracly.local')->exists()) {
    \\App\\Models\\User::query()->create([
        'name' => 'Admin Oracly',
        'email' => 'admin@oracly.local',
        'password' => 'oraclyadmin',
    ]);
    echo \"filament user created\\n\";
} else {
    echo \"filament user already exists\\n\";
}
" || true

exec "$@"
