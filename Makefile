# Convenience targets. PHP_CONFIG selects the PHP installation to build for.
PHP_CONFIG ?= $(shell command -v php-config)
PHP ?= php
CARGO ?= cargo
EXT_DIR := extension
SO := $(EXT_DIR)/target/release/libpdf_preflight.so
# Builds without PHP bindings use their own target dir so that they never
# overwrite the extension binary.
CORE := --manifest-path $(EXT_DIR)/Cargo.toml --target-dir $(EXT_DIR)/target/core --no-default-features

.PHONY: all build test test-rust test-php test-lib analyse-lib lint fixtures bench check-deps clean

all: build

build:
	cd $(EXT_DIR) && PHP_CONFIG=$(PHP_CONFIG) $(CARGO) build --release --locked

test: test-rust test-php test-lib

test-rust:
	$(CARGO) test $(CORE) --release --locked

test-php: build
	$(PHP) -n -d extension=$(CURDIR)/$(SO) $(EXT_DIR)/tests/php/extension_test.php

# PHP library (php/): needs `composer install` first.
test-lib: build
	$(PHP) -d extension=$(CURDIR)/$(SO) vendor/bin/phpunit

analyse-lib:
	$(PHP) vendor/bin/phpstan analyse --memory-limit=512M

lint:
	cd $(EXT_DIR) && $(CARGO) fmt --check
	$(CARGO) clippy $(CORE) --features cli --all-targets --locked -- -D warnings
	cd $(EXT_DIR) && PHP_CONFIG=$(PHP_CONFIG) $(CARGO) clippy --all-targets --locked -- -D warnings

fixtures:
	python3 tests/fixtures/generate.py

bench:
	$(CARGO) build $(CORE) --features cli --release --locked
	$(EXT_DIR)/target/core/release/pdf-analyze --bench tests/fixtures/*.pdf $(BENCH_FILES)
	$(PHP) -n -d extension=$(CURDIR)/$(SO) examples/benchmark.php tests/fixtures/*.pdf $(BENCH_FILES)

check-deps: build
	sh scripts/check-deps.sh $(SO)

clean:
	cd $(EXT_DIR) && $(CARGO) clean
