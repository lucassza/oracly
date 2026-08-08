.PHONY: build up down logs shell artisan

build:
	docker compose build

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f app

shell:
	docker compose exec app bash

artisan:
	docker compose exec app php artisan $(CMD)
