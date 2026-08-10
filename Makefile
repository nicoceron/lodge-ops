.PHONY: bootstrap up down test test-api test-web lint migrate seed shell-api

bootstrap:
	cp .env.example .env
	docker compose build
	docker compose run --rm api php artisan key:generate
	docker compose run --rm api php artisan migrate --seed

up:
	docker compose up --build

down:
	docker compose down

test: test-api test-web

test-api:
	cd apps/api && php artisan test --compact

test-web:
	cd apps/web && npm run test:run && npm run build

lint:
	cd apps/api && ./vendor/bin/pint --test
	cd apps/web && npm run lint && npm run typecheck

migrate:
	docker compose run --rm api php artisan migrate

seed:
	docker compose run --rm api php artisan db:seed

shell-api:
	docker compose exec api sh

