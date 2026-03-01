# Makefile

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# Use npx so CI (no global install) and local dev both work.
# Override with: make up WP_ENV="wp-env"
WP_ENV = npx wp-env

# Docker test config used for all wp-env run commands
DOCKER_CONFIG = --config=.wp-env.test.json

# Check if Docker is running
check-docker:
	@docker version  > /dev/null || (echo "" && echo "Error: Docker is not running. Please ensure Docker is installed and running." && echo "" && exit 1)

install-requirements:
	npm -g i @wordpress/env


# ─── Playground (port 8888, no Docker) ───────────────────────────────────────

start-if-not-running:
	@if [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8888)" = "000" ]; then \
		echo "Playground is NOT running. Starting..."; \
		$(WP_ENV) start --runtime=playground --update; \
		echo "Visit http://localhost:8888/wp-admin/ to access the Documentate dashboard."; \
	else \
		echo "Playground is already running on port 8888, skipping start."; \
	fi

# Bring up Playground (no Docker required)
up: start-if-not-running

# Stop Playground
down:
	$(WP_ENV) stop

# ─── Docker (port 8889, requires Docker) ─────────────────────────────────────

start-docker-if-not-running: check-docker
	@if [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8889)" = "000" ]; then \
		echo "Docker env is NOT running. Starting..."; \
		$(WP_ENV) start $(DOCKER_CONFIG) --update; \
		$(WP_ENV) run cli $(DOCKER_CONFIG) wp plugin activate documentate; \
		echo "Visit http://localhost:8889/wp-admin/ to access the Docker environment."; \
	else \
		echo "Docker env is already running on port 8889, skipping start."; \
	fi

# Bring up Docker containers
up-docker: check-docker start-docker-if-not-running

# Stop Docker containers
down-docker: check-docker
	$(WP_ENV) stop $(DOCKER_CONFIG)

# ─── Clean / Destroy ─────────────────────────────────────────────────────────

# Clean the Docker environment (wp-env v11 replaced "clean" with "reset")
clean: check-docker
	$(WP_ENV) reset $(DOCKER_CONFIG) development
	$(WP_ENV) reset $(DOCKER_CONFIG) tests
	$(WP_ENV) run cli $(DOCKER_CONFIG) wp plugin activate documentate
	$(WP_ENV) run cli $(DOCKER_CONFIG) wp language core install es_ES --activate
	$(WP_ENV) run cli $(DOCKER_CONFIG) wp site switch-language es_ES

destroy:
	$(WP_ENV) destroy

# ─── PHPUnit tests (Docker, port 8889) ───────────────────────────────────────

tests: test

# Run unit tests with PHPUnit. Use FILE or FILTER (or both).
test: start-docker-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	$(WP_ENV) run tests-cli $(DOCKER_CONFIG) --env-cwd=wp-content/plugins/documentate $$CMD --colors=always

# Run document generation tests only
test-generation: start-docker-if-not-running
	$(WP_ENV) run tests-cli $(DOCKER_CONFIG) --env-cwd=wp-content/plugins/documentate ./vendor/bin/phpunit --testsuite=generation --colors=always

# Run unit tests in verbose mode. Honor TEST filter if provided.
test-verbose: start-docker-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(TEST)" ]; then CMD="$$CMD --filter $(TEST)"; fi; \
	CMD="$$CMD --debug --verbose"; \
	$(WP_ENV) run tests-cli $(DOCKER_CONFIG) --env-cwd=wp-content/plugins/documentate $$CMD --colors=always

# Run tests with code coverage report.
# IMPORTANT: Requires wp-env started with Xdebug enabled:
#   wp-env start --config=.wp-env.test.json --xdebug=coverage
# If coverage shows 0%, restart wp-env with the --xdebug=coverage flag.
test-coverage: start-docker-if-not-running
	@mkdir -p artifacts/coverage
	@CMD="env XDEBUG_MODE=coverage ./vendor/bin/phpunit --colors=always --coverage-text=artifacts/coverage/coverage.txt --coverage-html artifacts/coverage/html --coverage-clover artifacts/coverage/clover.xml"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	$(WP_ENV) run tests-cli $(DOCKER_CONFIG) --env-cwd=wp-content/plugins/documentate $$CMD; \
	EXIT_CODE=$$?; \
	echo ""; \
	echo "════════════════════════════════════════════════════════════"; \
	echo "                    COVERAGE SUMMARY                        "; \
	echo "════════════════════════════════════════════════════════════"; \
	grep -E "^\s*(Lines|Functions|Classes|Methods):" artifacts/coverage/coverage.txt 2>/dev/null || echo "Coverage data not available"; \
	echo "════════════════════════════════════════════════════════════"; \
	echo "Full report: artifacts/coverage/html/index.html"; \
	echo ""; \
	exit $$EXIT_CODE

# ─── E2E tests ────────────────────────────────────────────────────────────────

# Ensure tests environment has admin user and plugin active (Docker)
setup-tests-env:
	@echo "Setting up tests environment..."
	@$(WP_ENV) run tests-cli $(DOCKER_CONFIG) wp core install \
		--url=http://localhost:8889 \
		--title="Documentate Tests" \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.com \
		--skip-email 2>/dev/null || true
	@$(WP_ENV) run tests-cli $(DOCKER_CONFIG) wp language core install es_ES --activate 2>/dev/null || true
	@$(WP_ENV) run tests-cli $(DOCKER_CONFIG) wp site switch-language es_ES 2>/dev/null || true
	@$(WP_ENV) run tests-cli $(DOCKER_CONFIG) wp plugin activate documentate 2>/dev/null || true
	@$(WP_ENV) run tests-cli $(DOCKER_CONFIG) wp rewrite structure '/%postname%/' --hard 2>/dev/null || true

# Run E2E tests against Playground (port 8888, no Docker)
test-e2e: start-if-not-running
	TIMEOUT_MULTIPLIER=3 npm run test:e2e -- $(ARGS)

# Run E2E tests with visual UI against Playground (port 8888)
test-e2e-visual: start-if-not-running
	TIMEOUT_MULTIPLIER=3 npm run test:e2e -- --ui

# Run E2E tests against Docker (port 8889)
test-e2e-docker: start-docker-if-not-running setup-tests-env
	WP_BASE_URL=http://localhost:8889 npm run test:e2e -- $(ARGS)

# ─── WP-CLI helpers (Docker) ─────────────────────────────────────────────────

flush-permalinks:
	$(WP_ENV) run cli $(DOCKER_CONFIG) wp rewrite structure '/%postname%/'

# Function to create a user only if it does not exist
create-user:
	@if [ -z "$(USER)" ] || [ -z "$(EMAIL)" ] || [ -z "$(ROLE)" ]; then \
		echo "Error: Please, specify USER, EMAIL, ROLE and PASSWORD. Usage: make create-user USER=test1 EMAIL=test1@example.org ROLE=editor PASSWORD=password"; \
		exit 1; \
	fi
	$(WP_ENV) run cli $(DOCKER_CONFIG) sh -c 'wp user list --field=user_login | grep -q "^$(USER)$$" || wp user create $(USER) $(EMAIL) --role=$(ROLE) --user_pass=$(PASSWORD)'

logs:
	$(WP_ENV) logs $(DOCKER_CONFIG)

logs-test:
	$(WP_ENV) logs $(DOCKER_CONFIG) --environment=tests

# Finds the CLI container used by wp-env (Docker)
cli-container:
	@docker ps --format "{{.Names}}" \
	| grep "\-cli\-" \
	| grep -v "tests-cli" \
	|| ( \
		echo "No main CLI container found. Please run 'make up-docker' first." ; \
		exit 1 \
	)

# ─── Plugin check (Docker) ───────────────────────────────────────────────────

# Pass the wp plugin-check with proper error handling
check-plugin: check-docker start-docker-if-not-running
	# Install plugin-check if needed (don't fail if already active)
	@$(WP_ENV) run cli $(DOCKER_CONFIG) wp plugin install plugin-check --activate --color || true

	# Run plugin check with colored output, capture exit code, and fail if needed
	@echo "Running WordPress Plugin Check..."
	@$(WP_ENV) run cli $(DOCKER_CONFIG) wp plugin check documentate \
		--exclude-directories=tests \
		--exclude-checks=file_type,image_functions \
		--ignore-warnings \
		--color; \
	EXIT_CODE=$$?; \
	echo ""; \
	if [ $$EXIT_CODE -eq 0 ]; then \
		echo "Plugin Check: ✓ No errors found."; \
	else \
		echo "Plugin Check: ✗ Errors found (exit code: $$EXIT_CODE)."; \
		exit $$EXIT_CODE; \
	fi

# ─── Linting & Code Quality ──────────────────────────────────────────────────

# Combined check for lint, tests, untranslated, and more
check: fix lint check-plugin test check-untranslated mo

check-all: check

# Install Mago PHP toolchain via Composer
install-mago:
	@echo "Checking if Mago is installed..."
	@if [ ! -x "./vendor/bin/mago" ]; then \
		echo "Installing Mago..."; \
		composer install --prefer-dist; \
	else \
		echo "Mago is already installed."; \
	fi

# Check code style with Mago linter
lint: install-mago
	./vendor/bin/mago lint

# Automatically fix code style with Mago formatter
fix: install-mago
	./vendor/bin/mago format

# Run PHP Mess Detector ignoring vendor and node_modules
phpmd:
	phpmd . text cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,node_modules,tests

# Fix without tty for use on git hooks
fix-no-tty: install-mago
	./vendor/bin/mago format

# Lint without tty for use on git hooks
lint-no-tty: install-mago
	./vendor/bin/mago lint

# ─── Composer / Translations / Packaging ─────────────────────────────────────

# Update Composer dependencies
update: check-docker
	composer update --no-cache --with-all-dependencies
	npm update

# Generate a .pot file for translations
pot:
	composer make-pot

# Update .po files from .pot file
po:
	composer update-po

# Generate .mo files from .po files
mo:
	composer make-mo

# Check the untranslated strings
check-untranslated:
	composer check-untranslated

# Generate the documentate-X.X.X.zip package
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: No se ha especificado una versión. Usa 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	# Update the version in documentate.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           $(VERSION)/" documentate.php
	$(SED_INPLACE) "s/define( 'DOCUMENTATE_VERSION', '[^']*'/define( 'DOCUMENTATE_VERSION', '$(VERSION)'/" documentate.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: $(VERSION)/" readme.txt

	# Create the ZIP package
	composer archive --format=zip --file="documentate-$(VERSION)"

	# Restore the version in documentate.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           0.0.0/" documentate.php
	$(SED_INPLACE) "s/define( 'DOCUMENTATE_VERSION', '[^']*'/define( 'DOCUMENTATE_VERSION', '0.0.0'/" documentate.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: 0.0.0/" readme.txt

# ─── Help ─────────────────────────────────────────────────────────────────────

# Show help with available commands
help:
	@echo "Available commands:"
	@echo ""
	@echo "Environments:"
	@echo "  up                 - Start Playground on port 8888 (no Docker needed)"
	@echo "  down               - Stop Playground"
	@echo "  up-docker          - Start Docker environment on port 8889"
	@echo "  down-docker        - Stop Docker environment"
	@echo "  logs               - Show Docker container logs"
	@echo "  logs-test          - Show logs from Docker test environment"
	@echo "  clean              - Reset Docker environment"
	@echo "  destroy            - Destroy all wp-env environments"
	@echo "  flush-permalinks   - Flush permalinks (Docker)"
	@echo "  create-user        - Create a WordPress user (Docker)"
	@echo "                       Usage: make create-user USER=<username> EMAIL=<email> ROLE=<role> PASSWORD=<password>"
	@echo ""
	@echo "Linting & Code Quality:"
	@echo "  fix                - Automatically fix code style with Mago formatter"
	@echo "  lint               - Check code style with Mago linter"
	@echo "  fix-no-tty         - Same as 'fix' but without TTY (for git hooks)"
	@echo "  lint-no-tty        - Same as 'lint' but without TTY (for git hooks)"
	@echo "  check-plugin       - Run WordPress plugin-check (Docker)"
	@echo "  check-untranslated - Check for untranslated strings"
	@echo "  check              - Run fix, lint, plugin-check, tests, untranslated, and mo"
	@echo "  check-all          - Alias for 'check'"
	@echo ""
	@echo "Testing:"
	@echo "  test               - Run PHPUnit tests (Docker, port 8889)"
	@echo "                       FILTER=<pattern> (run tests matching the pattern)"
	@echo "                       FILE=<path>      (run tests in specific file)"
	@echo "  test-generation    - Run document generation tests only (Docker)"
	@echo "  test-coverage      - Run PHPUnit with coverage (Docker, requires --xdebug=coverage)"
	@echo ""
	@echo "  test-e2e           - Run E2E tests against Playground (port 8888)"
	@echo "  test-e2e-visual    - Run E2E tests with visual UI (Playground)"
	@echo "  test-e2e-docker    - Run E2E tests against Docker (port 8889)"
	@echo ""
	@echo "Translations:"
	@echo "  pot                - Generate a .pot file for translations"
	@echo "  po                 - Update .po files from .pot file"
	@echo "  mo                 - Generate .mo files from .po files"
	@echo ""
	@echo "Packaging & Updates:"
	@echo "  update             - Update Composer dependencies"
	@echo "  package            - Create ZIP package. Usage: make package VERSION=x.y.z"
	@echo ""
	@echo "  help               - Show this help message"

# Set help as the default target if no target is specified
.DEFAULT_GOAL := help
