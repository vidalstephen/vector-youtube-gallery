SHELL := /bin/bash
COMPOSE := docker compose --env-file dev/.env

.PHONY: help install composer test test-unit test-integration lint perms up down logs reset

help:
	@echo "Vector YouTube Gallery — local dev"
	@echo ""
	@echo "  make up              Bring up WordPress + MariaDB + Adminer"
	@echo "  make down            Stop all containers"
	@echo "  make logs            Tail logs"
	@echo "  make perms           Fix bind-mount file permissions (0600 → 0644)"
	@echo "  make install         composer install inside the phpunit container"
	@echo "  make test            Run full test suite (phpunit)"
	@echo "  make test-unit       Run unit tests only"
	@echo "  make reset           Drop all volumes and start clean (DESTRUCTIVE)"
	@echo ""
	@echo "Containers:"
	@echo "  WordPress:    http://localhost:8000  (admin / changeme_wp_admin_password)"
	@echo "  Adminer:      http://localhost:8090"
	@echo ""

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f --tail=100

perms:
	find . -type f -not -path './.git/*' -not -path './vendor/*' -not -path './node_modules/*' -exec chmod 644 {} \;
	find . -type d -not -path './.git*' -not -path './vendor*' -not -path './node_modules*' -exec chmod 755 {} \;
	@echo "file perms normalized"

install:
	$(COMPOSE) --profile test run --rm phpunit sh -c 'mkdir -p /tmp/composer-bin && export COMPOSER_HOME=/tmp/.composer && curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp/composer-bin --filename=composer --quiet && export PATH=/tmp/composer-bin:$$PATH && composer install --no-interaction --prefer-dist --no-progress 2>&1 | tail -10'

test:
	$(COMPOSE) --profile test run --rm phpunit sh -c 'export PATH=/tmp/composer-bin:$$PATH && vendor/bin/phpunit --colors=always'

test-unit:
	$(COMPOSE) --profile test run --rm phpunit sh -c 'export PATH=/tmp/composer-bin:$$PATH && vendor/bin/phpunit --testsuite=unit --colors=always'

reset:
	$(COMPOSE) down -v
	@echo "all volumes removed"