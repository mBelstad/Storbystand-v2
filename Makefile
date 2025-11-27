SHELL := /bin/bash

DC := docker compose --env-file ./.env -f docker/docker-compose.yml

.PHONY: up down stop logs wp setup sh

up:
	$(DC) up -d

stop:
	$(DC) stop

logs:
	$(DC) logs -f

wp:
	bin/wp.sh $(filter-out $@,$(MAKECMDGOALS))

setup:
	bin/setup-wordpress.sh

sh:
	$(DC) exec wp bash

%:
	@:
