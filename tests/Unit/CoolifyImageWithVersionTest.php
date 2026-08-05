<?php

it('appends version only when helper image has no tag', function () {
    expect(coolifyImageWithVersion('ghcr.io/coollabsio/coolify-helper', '1.0.14'))
        ->toBe('ghcr.io/coollabsio/coolify-helper:1.0.14');

    expect(coolifyImageWithVersion('ghcr-mirror.liara.ir/coollabsio/coolify-helper:1.0.14', '1.0.14'))
        ->toBe('ghcr-mirror.liara.ir/coollabsio/coolify-helper:1.0.14');
});
