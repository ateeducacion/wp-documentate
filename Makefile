# Makefile

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# Check if Docker is running
check-docker:
	@docker version  > /dev/null || (echo "" && echo "Error: Docker is not running. Please ensure Docker is installed and running." && echo "" && exit 1)

install-requirements:
	npm -g i @wordpress/env

start-if-not-running:
	@if [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8889)" = "000" ]; then \
		echo "wp-env is NOT running. Starting (previous updating) containers..."; \
		npx wp-env start --update; \
		npx wp-env run cli wp plugin activate documentate; \
		echo "Visit http://localhost:8888/wp-admin/ to access the Documentate dashboard."; \
	else \
		echo "wp-env is already running, skipping start."; \
	fi

# Bring up Docker containers
up: check-docker start-if-not-running

flush-permalinks:
	#npx wp-env run cli wp rewrite flush --hard
	npx wp-env run cli wp rewrite structure '/%postname%/'

# Function to create a user only if it does not exist
create-user:
	@if [ -z "$(USER)" ] || [ -z "$(EMAIL)" ] || [ -z "$(ROLE)" ]; then \
		echo "Error: Please, specify USER, EMAIL, ROLE and PASSWORD. Usage: make create-user USER=test1 EMAIL=test1@example.org ROLE=editor PASSWORD=password"; \
		exit 1; \
	fi
	npx wp-env run cli sh -c 'wp user list --field=user_login | grep -q "^$(USER)$$" || wp user create $(USER) $(EMAIL) --role=$(ROLE) --user_pass=$(PASSWORD)'

# Stop and remove Docker containers
down: check-docker
	npx wp-env stop

# Clean the environments, the same that running "npx wp-env clean all"
clean:
	npx wp-env clean development
	npx wp-env clean tests
	npx wp-env run cli wp plugin activate documentate
	npx wp-env run cli wp language core install es_ES --activate
	npx wp-env run cli wp site switch-language es_ES



destroy:
	npx wp-env destroy

# Pass the wp plugin-check
check-plugin-old: check-docker start-if-not-running
	npx wp-env run cli wp plugin install plugin-check --activate --color
	npx wp-env run cli wp plugin check documentate --exclude-directories=tests --exclude-checks=file_type,image_functions --ignore-warnings --color

# Pass the wp plugin-check with proper error handling
check-plugin: check-docker start-if-not-running
	# Install plugin-check if needed (don't fail if already active)
	@npx wp-env run cli wp plugin install plugin-check --activate --color || true

	# Run plugin check with colored output, capture exit code, and fail if needed
	@echo "Running WordPress Plugin Check..."
	@npx wp-env run cli wp plugin check documentate \
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


# Combined check for lint, tests, untranslated, and more
check: fix lint check-plugin test check-untranslated mo

check-all: check

tests: test

# Run unit tests with PHPUnit. Use FILE or FILTER (or both).
test: start-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/documentate $$CMD --testdox --colors=always

# Run document generation tests only
test-generation: start-if-not-running
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/documentate ./vendor/bin/phpunit --testsuite=generation --testdox --colors=always

# Run unit tests in verbose mode. Honor TEST filter if provided.
test-verbose: start-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(TEST)" ]; then CMD="$$CMD --filter $(TEST)"; fi; \
	CMD="$$CMD --debug --verbose"; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/documentate $$CMD --colors=always

# Run tests with code coverage report.
# IMPORTANT: Requires wp-env started with Xdebug enabled:
#   npx wp-env start --xdebug=coverage
# If coverage shows 0%, restart wp-env with the --xdebug=coverage flag.
# NOTE: Uses PHPUnit (not ParaTest) because WordPress tests share a single
# database and don't support parallel execution reliably.
test-coverage: start-if-not-running
	@mkdir -p artifacts/coverage
	@CMD="env XDEBUG_MODE=coverage ./vendor/bin/phpunit --testdox --colors=always --coverage-text=artifacts/coverage/coverage.txt --coverage-html artifacts/coverage/html --coverage-clover artifacts/coverage/clover.xml"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/documentate $$CMD; \
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

# Ensure tests environment has admin user and plugin active
setup-tests-env:
	@echo "Setting up tests environment..."
	@npx wp-env run tests-cli wp core install \
		--url=http://localhost:8889 \
		--title="Documentate Tests" \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.com \
		--skip-email 2>/dev/null || true
	@npx wp-env run cli wp language core install es_ES --activate
	@npx wp-env run cli wp site switch-language es_ES
	@npx wp-env run tests-cli wp plugin activate documentate 2>/dev/null || true
	@npx wp-env run tests-cli wp rewrite structure '/%postname%/' --hard 2>/dev/null || true

# Run E2E tests with Playwright against wp-env tests environment (port 8889)
test-e2e: start-if-not-running setup-tests-env
	WP_BASE_URL=http://localhost:8889 npm run test:e2e -- $(ARGS)

test-e2e-visual: start-if-not-running setup-tests-env
	WP_BASE_URL=http://localhost:8889 npm run test:e2e -- --ui


logs:
	npx wp-env logs

logs-test:
	npx wp-env logs --environment=tests


# Install PHP_CodeSniffer and WordPress Coding Standards in the container
install-phpcs: check-docker start-if-not-running
	@echo "Checking if PHP_CodeSniffer is installed..."
	@if ! npx wp-env run cli bash -c '[ -x "$$HOME/.composer/vendor/bin/phpcs" ]' > /dev/null 2>&1; then \
		echo "Installing PHP_CodeSniffer and WordPress Coding Standards..."; \
		npx wp-env run cli composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true; \
		npx wp-env run cli composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs --no-interaction; \
	else \
		echo "PHP_CodeSniffer is already installed."; \
	fi


# Check code style with PHP Code Sniffer inside the container
lint: install-phpcs
	npx wp-env run cli phpcs --standard=wp-content/plugins/documentate/.phpcs.xml.dist wp-content/plugins/documentate

# Automatically fix code style with PHP Code Beautifier inside the container
fix: install-phpcs
	npx wp-env run cli phpcbf --standard=wp-content/plugins/documentate/.phpcs.xml.dist wp-content/plugins/documentate

# Run PHP Mess Detector ignoring vendor and node_modules
phpmd:
	phpmd . text cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,node_modules,tests

# Finds the CLI container used by wp-env
cli-container:
	@docker ps --format "{{.Names}}" \
	| grep "\-cli\-" \
	| grep -v "tests-cli" \
	|| ( \
		echo "No main CLI container found. Please run 'make up' first." ; \
		exit 1 \
	)

# Fix wihout tty for use on git hooks
fix-no-tty: cli-container start-if-not-running
	@CONTAINER_CLI=$$( \
		docker ps --format "{{.Names}}" \
		| grep "\-cli\-" \
		| grep -v "tests-cli" \
	) && \
	echo "Running PHPCBF (no TTY) inside $$CONTAINER_CLI..." && \
	docker exec -i $$CONTAINER_CLI \
		phpcbf --standard=wp-content/plugins/documentate/.phpcs.xml.dist wp-content/plugins/documentate

# Lint wihout tty for use on git hooks
lint-no-tty: cli-container start-if-not-running
	@CONTAINER_CLI=$$( \
		docker ps --format "{{.Names}}" \
		| grep "\-cli\-" \
		| grep -v "tests-cli" \
	) && \
	echo "Running PHPCS (no TTY) inside $$CONTAINER_CLI..." && \
	docker exec -i $$CONTAINER_CLI \
		phpcs --standard=wp-content/plugins/documentate/.phpcs.xml.dist wp-content/plugins/documentate


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

# Show help with available commands
help:
	@echo "Available commands:"
	@echo ""
	@echo "General:"
	@echo "  up                 - Bring up Docker containers in interactive mode"
	@echo "  down               - Stop and remove Docker containers"
	@echo "  logs               - Show the docker container logs"
	@echo "  logs-test          - Show logs from test environment"
	@echo "  clean              - Clean up WordPress environment"
	@echo "  destroy            - Destroy the WordPress environment"
	@echo "  flush-permalinks   - Flush the created permalinks"
	@echo "  create-user        - Create a WordPress user if it doesn't exist."
	@echo "                       Usage: make create-user USER=<username> EMAIL=<email> ROLE=<role> PASSWORD=<password>"
	@echo ""
	@echo "Assets (SCSS / CSS):"
	@echo ""
	@echo "  css                   - Build production CSS (compressed, no source map)"
	@echo "  css-dev               - Build development CSS (expanded, with source map)"
	@echo "  css-watch             - Start watcher to recompile SCSS on changes (dev mode)"
	@echo "  css-clean             - Remove generated CSS and source map files"
	@echo ""
	@echo "Linting & Code Quality:"
	@echo "  fix                - Automatically fix code style with PHP_CodeSniffer"
	@echo "  lint               - Check code style with PHP_CodeSniffer"
	@echo "  fix-no-tty         - Same as 'fix' but without TTY (for git hooks)"
	@echo "  lint-no-tty        - Same as 'lint' but without TTY (for git hooks)"
	@echo "  check-plugin       - Run WordPress plugin-check tests"
	@echo "  check-untranslated - Check for untranslated strings"
	@echo "  check              - Run fix, lint, plugin-check, tests, untranslated, and mo"
	@echo "  check-all          - Alias for 'check'"
	@echo ""
	@echo "Testing:"
	@echo "  test               - Run PHPUnit tests. Accepts optional variables:"
	@echo "                       FILTER=<pattern> (run tests matching the pattern)"
	@echo "                       FILE=<path>      (run tests in specific file)"
	@echo "                       Examples:"
	@echo "                         make test FILTER=MyTest"
	@echo "                         make test FILE=tests/MyTest.php"
	@echo "                         make test FILE=tests/MyTest.php FILTER=test_my_feature"
	@echo "  test-generation    - Run document generation tests only"
	@echo "  test-coverage      - Run PHPUnit with coverage (requires: npx wp-env start --xdebug=coverage)"
	@echo ""
	@echo "  test-e2e           - Run E2E tests (non-interactive)"
	@echo "  test-e2e-visual    - Run E2E tests with visual test UI"
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
