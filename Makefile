.PHONY: bootstrap up down logs doctor build-api test test-api test-web lint analyse-api contract verify migrate seed shell-api

bootstrap:
	test -f .env || cp .env.example .env
	test -f apps/api/.env || cp apps/api/.env.example apps/api/.env
	docker compose build
	docker compose up -d --wait postgres redis mailpit
	docker compose run --rm api sh -lc 'grep -Eq "^APP_KEY=base64:.+" .env || php artisan key:generate'
	docker compose run --rm api php artisan migrate --seed

up:
	docker compose up -d --build --wait
	docker compose ps

down:
	docker compose down

logs:
	docker compose logs -f --tail=150 api web worker scheduler

doctor:
	docker compose config --quiet
	docker compose ps
	curl --fail --silent --show-error http://localhost:$${API_PORT:-8000}/up >/dev/null
	curl --fail --silent --show-error http://localhost:$${API_PORT:-8000}/manage/login >/dev/null
	test "$$(curl --silent --output /dev/null --write-out '%{http_code}' http://localhost:$${API_PORT:-8000}/css/filament/filament/app.css)" = "200"
	test "$$(curl --silent --output /dev/null --write-out '%{http_code}' http://localhost:$${API_PORT:-8000}/js/filament/filament/app.js)" = "200"
	curl --fail --silent --show-error http://localhost:$${WEB_PORT:-3000}/ >/dev/null
	@echo "LodgeOps public site and Laravel/Filament application are reachable."

build-api:
	cd apps/api && npm ci && npm run build

test: test-api test-web

test-api:
	cd apps/api && php artisan test --compact

test-web:
	cd apps/web && npm run e2e

lint:
	cd apps/api && ./vendor/bin/pint --test
	$(MAKE) analyse-api
	cd apps/web && npm run lint && npm run typecheck

analyse-api:
	cd apps/api && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G

contract:
	cd apps/api && php artisan route:list --json > /tmp/lodge-ops-routes.json
	ruby scripts/verify-openapi.rb contracts/openapi.yaml /tmp/lodge-ops-routes.json

verify: build-api lint contract test

migrate:
	docker compose run --rm api php artisan migrate

seed:
	docker compose run --rm api php artisan db:seed

shell-api:
	docker compose exec api sh
