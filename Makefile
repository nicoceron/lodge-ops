.PHONY: bootstrap up down logs doctor test test-api test-web lint contract verify migrate seed shell-api

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
	curl --fail --silent --show-error http://localhost:$${WEB_PORT:-3000}/login >/dev/null
	@echo "LodgeOps API and web are reachable."

test: test-api test-web

test-api:
	cd apps/api && php artisan test --compact

test-web:
	cd apps/web && npm run test:run && npm run e2e

lint:
	cd apps/api && ./vendor/bin/pint --test
	cd apps/web && npm run lint && npm run typecheck

contract:
	cd apps/api && php artisan route:list --json > /tmp/lodge-ops-routes.json
	ruby scripts/verify-openapi.rb contracts/openapi.yaml /tmp/lodge-ops-routes.json

verify: lint contract test

migrate:
	docker compose run --rm api php artisan migrate

seed:
	docker compose run --rm api php artisan db:seed

shell-api:
	docker compose exec api sh
