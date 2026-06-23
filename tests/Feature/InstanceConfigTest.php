<?php

test('instance self hosted mode is disabled by default', function () {
    expect(config('instance.self_hosted'))->toBeFalse();
});
