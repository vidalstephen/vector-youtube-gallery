SHELL := /bin/bash
COMPOSE := docker compose --env-file dev/.env

.PHONY: help install composer test test-unit test-integration lint perms up down logs reset ci ci-smoke ci-clean remote-admin-status

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
	@echo "  make ci              Full CI gate: lint + test-unit + ci-smoke"
	@echo "  make ci-smoke        Boot WP, activate plugin, hit key endpoints, run all phase smokes"
	@echo "  make ci-clean        Tear down the CI smoke environment"
	@echo "  make remote-admin-status  Verify local + Tailscale wp-admin URLs"
	@echo ""
	@echo "Containers:"
	@echo "  WordPress local:   http://localhost:8000"
	@echo "  WordPress remote:  https://srv1388017.tail209ed.ts.net/wp-admin  (Tailscale tailnet only)"
	@echo "  Adminer:           http://localhost:8090"
	@echo ""

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f --tail=100

remote-admin-status:
	@echo "==> Tailscale Serve"
	@tailscale serve status
	@echo "==> Local wp-login canonical links"
	@curl -fsSL http://localhost:8000/wp-login.php | grep -Eo 'action="[^"]+"|href="http[^"]+"' | grep -E 'localhost|wp-login|wp-admin' | head -4
	@echo "==> Remote wp-login canonical links"
	@curl -k -fsSL https://srv1388017.tail209ed.ts.net/wp-login.php | grep -Eo 'action="[^"]+"|href="http[^"]+"' | grep -E 'srv1388017|wp-login|wp-admin' | head -4
	@echo "==> Remote wp-admin redirect"
	@curl -k -sSIL https://srv1388017.tail209ed.ts.net/wp-admin/ | grep -i '^location:' | head -1
	@echo "remote admin url: https://srv1388017.tail209ed.ts.net/wp-admin/"

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

# Phase 12.7: CI smoke hardening. The `ci` target is the
# canonical gate for "is this build ready to ship?": it runs lint
# on the touched source, the full unit suite, and a live boot of
# the WordPress container that exercises the Phase 12.x CLI
# subcommands and live smokes.
ci: lint test-unit ci-smoke

ci-smoke:
	@echo "==> Phase 12.7: live smoke (boot WP, activate, run phase smokes)"
	@./scripts/ci-smoke.sh

ci-clean:
	@echo "==> Phase 12.7: teardown CI environment"
	@./scripts/ci-smoke.sh clean

lint:
	@echo "==> Phase 12.7: php -l on every PHP file under src/ and tests/"
	@docker exec vyg-wp bash -c "find /var/www/html/wp-content/plugins/vector-youtube-gallery/src /var/www/html/wp-content/plugins/vector-youtube-gallery/tests -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 -P4 php -l 2>&1 | (! grep -v 'No syntax errors')"
	@echo "all source files parse cleanly"