.PHONY: check package

check:
	composer validate --strict --no-check-publish
	composer lint
	composer lint:style
	composer test
	@if [ -x vendor/bin/phpunit ]; then composer test:phpunit; fi
	@if [ -x vendor/bin/psalm.phar ]; then composer psalm; fi
	node --check js/app.js
	node --check js/admin.js
	node --check l10n/pt_BR.js
	TZ=America/Sao_Paulo node --test tests/js/*.test.js
	xmllint --noout --schema tests/info.xsd appinfo/info.xml
	xmllint --noout phpunit.xml phpcs.xml
	php -r 'json_decode(file_get_contents("l10n/pt_BR.json"), true, 512, JSON_THROW_ON_ERROR);'
	php -r 'json_decode(file_get_contents(".impeccable/design.json"), true, 512, JSON_THROW_ON_ERROR);'
	php scripts/check-l10n.php

package:
	./scripts/package.sh
