# Sponsors

This project is free and MIT-licensed. Sponsorship keeps it that way.

## MonoVM — VPS hosting

[MonoVM](https://monovm.com) sponsors the hosting behind this project.

If you want to run AI UI Live Editor on your own server — which is the
recommended setup, since your API keys then live in `.env` and never touch a
browser — a small VPS is more than enough. The editor stores nothing and needs
no database.

### Deploying on a fresh Ubuntu VPS

```bash
ssh root@your-server

apt update && apt install -y php8.3-cli php8.3-curl php8.3-xml php8.3-mbstring \
    composer nginx git

cd /var/www
git clone https://github.com/sina-nasiri/ai-ui-live-editor.git
cd ai-ui-live-editor

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate

# Put your provider key here so the browser never handles it
nano .env

chown -R www-data:www-data storage bootstrap/cache
```

Point Nginx at the `public/` directory:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/ai-ui-live-editor/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

**Before you expose it publicly**, read [SECURITY.md](SECURITY.md). A public
instance is a fetcher anyone can drive — at minimum set `EDITOR_ALLOWED_HOSTS`
and keep the rate limits, or put the whole thing behind authentication.

### Plans

| Plan | Specs | Suits |
|---|---|---|
| Basic | 1 CPU, 2 GB RAM | One person |
| Standard | 2 CPU, 4 GB RAM | A small team |
| Professional | 4 CPU, 8 GB RAM | An agency |

[Get a MonoVM VPS](https://monovm.com/linux-vps/) — code `AIUIEDITOR` for 10%
off the first month.

---

## Sponsoring this project

If this tool saves your team time and you would like to support it, open an
issue or reach out through the maintainer's GitHub profile.
